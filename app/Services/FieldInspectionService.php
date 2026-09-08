<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BorrowerKyc;
use App\Models\Dealer;
use App\Models\DealerShops;
use App\Models\FieldInspectionDetails;
use App\Models\FieldInspectionParty;
use App\Models\LoanBasicDetails;
use App\Models\ProductModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class FieldInspectionService
{
    private const PARTY_ORDER = ['borrower', 'co_applicant', 'guarantor'];

    /**
     * Borrower is always required. Co-applicant / guarantor only for multi-party
     * sub-products, and only when the corresponding skip flag is false.
     *
     * @return array{borrower: bool, co_applicant: bool, guarantor: bool}
     */
    public function resolveRequirements(int $borrowerId): array
    {
        $req = ['borrower' => true, 'co_applicant' => false, 'guarantor' => false];

        $loan = LoanBasicDetails::find($borrowerId);
        if (!$loan) {
            return $req;
        }

        $model      = ProductModel::where('model_unq', $loan->model_id)->first();
        $subProduct = $model->sub_product_type ?? null;

        if (!in_array($subProduct, (array) config('field_inspection.multi_party_sub_products'), true)) {
            return $req;
        }

        $kyc = BorrowerKyc::where('borrower_id', $borrowerId)->first();
        if (!$kyc) {
            return $req;
        }

        $req['co_applicant'] = !filter_var($kyc->skip_co_applicant, FILTER_VALIDATE_BOOLEAN);
        $req['guarantor']    = !filter_var($kyc->skip_guarantor, FILTER_VALIDATE_BOOLEAN);

        return $req;
    }

    /**
     * Create / refresh party rows. Verified parties are frozen. Changing an
     * unverified party's name or mobile resets its progress. Parties that are
     * no longer required are hard-deleted.
     *
     * @param  array<string, array{name: ?string, mobile: ?string}>  $payload
     * @param  array{borrower: bool, co_applicant: bool, guarantor: bool}  $requirements
     * @return array<string, FieldInspectionParty>
     */
    public function syncParties(FieldInspectionDetails $fi, array $payload, array $requirements): array
    {
        $existing = $fi->parties()->get()->keyBy('party_type');
        $result   = [];

        foreach (FieldInspectionParty::TYPES as $type) {
            $current = $existing->get($type);

            if (!($requirements[$type] ?? false)) {
                $current?->delete();
                continue;
            }

            $name   = trim((string) ($payload[$type]['name'] ?? ''));
            $mobile = trim((string) ($payload[$type]['mobile'] ?? ''));

            if ($current && $current->is_verified) {
                $result[$type] = $current;                       // frozen
                continue;
            }

            if ($current) {
                if ($current->mobile !== $mobile) {
                    $current->forceFill($this->blankProgress());  // identity changed → restart
                }
                $current->forceFill(['name' => $name, 'mobile' => $mobile])->save();
                $result[$type] = $current;
                continue;
            }

            $result[$type] = FieldInspectionParty::create([
                'field_inspection_detail_id' => $fi->id,
                'borrower_id'                => $fi->borrower_id,
                'party_type'                 => $type,
                'party_uid'                  => $this->uniquePartyUid(),
                'name'                       => $name,
                'mobile'                     => $mobile,
                'meter_verification_status'  => 'not_uploaded',
            ]);
        }

        $fi->forceFill([
            'borrower_required'     => (bool) $requirements['borrower'],
            'co_applicant_required' => (bool) $requirements['co_applicant'],
            'guarantor_required'    => (bool) $requirements['guarantor'],
        ])->save();

        return $result;
    }

    public function issueOtp(FieldInspectionParty $party, bool $bypass = false): string
    {
        $otp = $bypass
            ? (string) config('field_inspection.test_otp')
            : (string) random_int(100000, 999999);

        $party->forceFill([
            'otp'            => $otp,
            'otp_expires_at' => now()->addMinutes((int) config('field_inspection.otp_ttl_minutes')),
        ])->save();

        return $otp;
    }

    public function issueAccessToken(FieldInspectionParty $party): string
    {
        do {
            $token = Str::lower(Str::random(32));
        } while (FieldInspectionParty::where('access_token', $token)->exists());

        $party->forceFill([
            'otp'              => null,
            'otp_expires_at'   => null,
            'access_token'     => $token,
            'token_expires_at' => now()->addHours((int) config('field_inspection.token_ttl_hours')),
        ])->save();

        return $token;
    }

    public function partyByToken(?string $token, bool $lock = false): ?FieldInspectionParty
    {
        if (!$token) {
            return null;
        }

        $q = FieldInspectionParty::query()
            ->where('access_token', $token)
            ->where(fn ($w) => $w->whereNull('token_expires_at')->orWhere('token_expires_at', '>', now()));

        return $lock ? $q->lockForUpdate()->first() : $q->first();
    }

    /** Per-party stage machine — identical semantics to the previous single-party version. */
    public function stageFor(FieldInspectionParty $party): array
    {
        $meter = $party->meter_verification_status ?: 'not_uploaded';

        $stage = match (true) {
            (bool) $party->is_verified => 'completed',
            $meter === 'approved'      => 'capture_house',
            $meter === 'rejected'      => 'recapture_meter',
            $meter === 'pending'       => 'meter_pending',
            default                    => 'capture_meter',
        };

        $nextAction = match ($stage) {
            'capture_meter', 'recapture_meter' => 'upload_meter_image',
            'meter_pending'                    => 'wait_admin_approval',
            'capture_house'                    => 'upload_house_image_with_location',
            'completed'                        => 'none',
        };

        return [
            'party_uid'                 => $party->party_uid,
            'party_type'                => $party->party_type,
            'label'                     => $party->label(),
            'name'                      => $party->name,
            'mobile'                    => $party->mobile,
            'stage'                     => $stage,
            'next_action'               => $nextAction,
            'meter_verification_status' => $meter,
            'meter_remarks'             => $party->meter_remarks,
            'is_verified'               => (bool) $party->is_verified,
            'distance'                  => $party->distance,
        ];
    }

    /** Ordered summary of every required party on an application. */
    public function partySummary(FieldInspectionDetails $fi): Collection
    {
        $required = $fi->requiredPartyTypes();

        return $fi->parties()
            ->whereIn('party_type', $required)
            ->get()
            ->sortBy(fn ($p) => array_search($p->party_type, self::PARTY_ORDER, true))
            ->values()
            ->map(fn (FieldInspectionParty $p) => [
                'party_uid'                 => $p->party_uid,
                'party_type'                => $p->party_type,
                'label'                     => $p->label(),
                'name'                      => $p->name,
                'mobile'                    => $p->mobile,
                'stage'                     => $this->stageFor($p)['stage'],
                'meter_verification_status' => $p->meter_verification_status ?: 'not_uploaded',
                'is_verified'               => (bool) $p->is_verified,
                'distance'                  => $p->distance,
            ]);
    }

    /** Dealer-shop origin, falling back to the dealer record. */
    public function originCoordinates(FieldInspectionDetails $fi, LoanBasicDetails $loan): array
    {
        $lat = $lng = null;

        if (filled($fi->dealer_shop_id) && ($shop = DealerShops::find($fi->dealer_shop_id))) {
            $lat = $shop->latitude;
            $lng = $shop->longitude;
        }

        if ($lat === null || $lng === null) {
            $dealer = Dealer::where('dealer_unq', $loan->dealer_id)->first();
            if (!$dealer) {
                throw new RuntimeException('Invalid request');
            }
            $lat = $dealer->latitude;
            $lng = $dealer->longitude;
        }

        if ($lat === null || $lng === null) {
            throw new RuntimeException('Dealer latitude and longitude is missing');
        }

        return [(float) $lat, (float) $lng];
    }

    public function maxDistanceKm(string $partyType, LoanBasicDetails $loan, BorrowerKyc $kyc, ProductModel $model): float
    {
        $max = (float) config("field_inspection.max_distance_km.$partyType", 26);
        $ovr = (array) config('field_inspection.eka_override');

        $state = preg_replace('/\s+/', ' ', strtolower(trim((string) $kyc->state)));

        if (in_array($partyType, $ovr['applies_to'], true)
            && in_array($state, $ovr['states'], true)
            && (int) $loan->product_id === (int) $ovr['product_id']
            && in_array($model->sub_product_type, $ovr['sub_product_types'], true)
        ) {
            $max = (float) $ovr['max_distance_km'];
        }

        return $max;
    }

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Parent aggregate flips only when every required party is verified. */
    public function recomputeCompletion(FieldInspectionDetails $fi): bool
    {
        $required = $fi->requiredPartyTypes();

        $verified = $fi->parties()
            ->whereIn('party_type', $required)
            ->where('is_verified', true)
            ->count();

        $complete = $required !== [] && $verified === count($required);

        $fi->forceFill([
            'is_inspection_verified' => $complete,
            'completed_at'           => $complete ? now() : null,
            'verification_time'      => $complete ? now() : $fi->verification_time,
        ])->save();

        return $complete;
    }

    /** @return string[] */
    public function pendingPartyTypes(FieldInspectionDetails $fi): array
    {
        return $fi->parties()
            ->whereIn('party_type', $fi->requiredPartyTypes())
            ->where('is_verified', false)
            ->pluck('party_type')
            ->sortBy(fn ($t) => array_search($t, self::PARTY_ORDER, true))
            ->values()
            ->all();
    }

    private function blankProgress(): array
    {
        return [
            'otp'                       => null,
            'otp_expires_at'            => null,
            'access_token'              => null,
            'token_expires_at'          => null,
            'meter_image'               => null,
            'meter_verification_status' => 'not_uploaded',
            'meter_remarks'             => null,
            'meter_uploaded_at'         => null,
            'meter_verified_by'         => null,
            'meter_verified_at'         => null,
            'fi_image'                  => null,
            'latitude'                  => null,
            'longitude'                 => null,
            'distance'                  => null,
            'verification_time'         => null,
            'is_verified'               => false,
        ];
    }

    private function uniquePartyUid(): string
    {
        do {
            $uid = Str::lower(Str::random(24));
        } while (FieldInspectionParty::where('party_uid', $uid)->exists());

        return $uid;
    }
}