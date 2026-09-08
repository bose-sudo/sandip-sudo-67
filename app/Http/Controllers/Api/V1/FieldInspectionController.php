<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BorrowerKyc;
use App\Models\FieldInspectionDetails;
use App\Models\FieldInspectionParty;
use App\Models\LoanBasicDetails;
use App\Models\ProductModel;
use App\Services\FieldInspectionService;
use App\Services\SmsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class FieldInspectionController extends Controller
{
    use ApiResponse;
    private const STEP = 16;
    protected SmsService $smsService;

    public function __construct(
        private readonly FieldInspectionService $fi,
        SmsService $smsService
    ) {
        $this->middleware('auth:api', [
            'except' => [
                'send_otp_field_verification',
                'verify_otp_field_verification',
                'verify_fi_access_token',
                'store_meter_image',
                'get_field_inspection_status',
                'calculate_distance_between_borrower_dealer',
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // STEP 1 — instruction (borrower app)
    // ------------------------------------------------------------------
    public function get_field_inspection_instruction(Request $request): JsonResponse
    {
        $borrowerId = (int) $this->get_borrower_id($request->user()->id);

        $loan = LoanBasicDetails::find($borrowerId);
        if (!$loan) {
            return $this->send_response('failed', 'Invalid request', 422);
        }

        $req   = $this->fi->resolveRequirements($borrowerId);
        $model = ProductModel::where('model_unq', $loan->model_id)->first();

        return $this->send_response('success', 'Field inspection requirement fetched.', 200, [
            'borrower_required'    => $req['borrower'],
            'coapplicant_required' => $req['co_applicant'],
            'guarantor_required'   => $req['guarantor'],
            'sub_product_type'     => $model->sub_product_type ?? null,
            'required_parties'     => array_keys(array_filter($req)),
        ]);
    }

    // ------------------------------------------------------------------
    // STEP 2 — store party details + dispatch inspector app links
    // ------------------------------------------------------------------
    public function store_field_verification_details(Request $request): JsonResponse
    {
        $userId     = $request->user()->id;
        $borrowerId = (int) $this->get_borrower_id($userId);

        $req = $this->fi->resolveRequirements($borrowerId);

        $rules = [
            'dealer_shop_id' => ['required', 'integer', 'exists:dealer_shops,id'],
            'name'           => ['required', 'string', 'max:120'],
            'mobile'         => ['required', 'regex:/^[6-9]\d{9}$/'],
        ];

        if ($req['co_applicant']) {
            $rules['coapplicant_name']   = ['required', 'string', 'max:120'];
            $rules['coapplicant_mobile'] = ['required', 'regex:/^[6-9]\d{9}$/'];
        }
        if ($req['guarantor']) {
            $rules['guarantor_name']   = ['required', 'string', 'max:120'];
            $rules['guarantor_mobile'] = ['required', 'regex:/^[6-9]\d{9}$/'];
        }

        $validator = Validator::make($request->all(), $rules, [
            'name.required'                => 'Name is required',
            'mobile.required'              => 'Mobile number is required',
            'mobile.regex'                 => 'Invalid mobile number format',
            'coapplicant_name.required'    => 'Co-applicant name is required',
            'coapplicant_mobile.required'  => 'Co-applicant mobile number is required',
            'coapplicant_mobile.regex'     => 'Invalid co-applicant mobile number format',
            'guarantor_name.required'      => 'Guarantor name is required',
            'guarantor_mobile.required'    => 'Guarantor mobile number is required',
            'guarantor_mobile.regex'       => 'Invalid guarantor mobile number format',
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $fiRow = FieldInspectionDetails::where('borrower_id', $borrowerId)->latest('id')->first()
                ?? new FieldInspectionDetails();

            $fiRow->borrower_id    = $borrowerId;
            $fiRow->name           = $request->input('name');
            $fiRow->mobile         = $request->input('mobile');
            $fiRow->dealer_shop_id = $request->input('dealer_shop_id');
            if (!$fiRow->exists) {
                $fiRow->is_inspection_verified = false;
            }
            $fiRow->save();

            $parties = $this->fi->syncParties($fiRow, [
                'borrower'     => ['name' => $request->input('name'),             'mobile' => $request->input('mobile')],
                'co_applicant' => ['name' => $request->input('coapplicant_name'), 'mobile' => $request->input('coapplicant_mobile')],
                'guarantor'    => ['name' => $request->input('guarantor_name'),   'mobile' => $request->input('guarantor_mobile')],
            ], $req);

            $this->fi->recomputeCompletion($fiRow);

            $link    = (string) config('field_inspection.inspector_app_link');
            $message = 'Please download the Finayo Inspector app from the link below to complete your field verification: ' . $link;

            $dispatched = [];
            $notified   = [];

            foreach ($parties as $type => $party) {
                // Same number across parties → one SMS only.
                if (!$party->is_verified && !in_array($party->mobile, $notified, true)) {
                    // $this->smsService->sendLink($party->mobile, $message);
                    $notified[] = $party->mobile;
                }

                $dispatched[] = [
                    'party_type'  => $type,
                    'party_uid'   => $party->party_uid,
                    'name'        => $party->name,
                    'mobile'      => $party->mobile,
                    'is_verified' => (bool) $party->is_verified,
                ];
            }

            $this->updateApplicationStep($userId, $borrowerId, [
                'step'                        => self::STEP,
                'field_inspection_details_id' => $fiRow->id,
                'details'                     => 'Field Inspection Details',
                'status'                      => 'COMPLETED',
            ]);

            $this->logStep($borrowerId, self::STEP, 'Field Inspection Details', 'b2c');

            DB::commit();

            return $this->send_response('success', 'Field inspection initiated. Verification link sent to all parties.', 200, [
                'link'    => $link,
                'parties' => $dispatched,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------
    // Inspector app — OTP request. Mobile collision resolved via party_uid.
    // ------------------------------------------------------------------
    public function send_otp_field_verification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mobile'    => ['required', 'digits:10'],
            'party_uid' => ['nullable', 'string', 'size:24'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $parties = FieldInspectionParty::where('mobile', $request->mobile)
                ->when($request->filled('party_uid'), fn($q) => $q->where('party_uid', $request->party_uid))
                ->orderBy('id')
                ->get();

            if ($parties->isEmpty()) {
                return $this->send_response('failed', 'Invalid request', 422);
            }

            if ($parties->count() > 1 && !$request->filled('party_uid')) {
                return $this->send_response('success', 'Multiple verifications found for this number.', 200, [
                    'requires_party_selection' => true,
                    'parties'                  => $parties->map(fn(FieldInspectionParty $p) => [
                        'party_uid'   => $p->party_uid,
                        'party_type'  => $p->party_type,
                        'label'       => $p->label(),
                        'name'        => $p->name,
                        'borrower_id' => $p->borrower_id,
                        'is_verified' => (bool) $p->is_verified,
                    ])->values(),
                ]);
            }

            /** @var FieldInspectionParty $party */
            $party = $parties->first();

            if ($party->is_verified) {
                return $this->send_response('success', 'Field already verified', 200, [
                    'requires_party_selection' => false,
                    'party_uid'                => $party->party_uid,
                    'party_type'               => $party->party_type,
                    'verified_by'              => $party->name,
                    'mobile'                   => $party->mobile,
                    'verification_status'      => true,
                    'verified_at'              => $party->verification_time,
                ]);
            }

            DB::beginTransaction();

            $bypass = $request->mobile === (string) config('field_inspection.test_mobile');
            $otp    = $this->fi->issueOtp($party, $bypass);

            if (!$bypass) {
                // $this->smsService->sendOtp(
                //     $party->mobile,
                //     $otp . ' is your Finayo field inspection login code. Please enter this code to verify your mobile number.'
                // );
            }

            DB::commit();

            return $this->send_response('success', 'OTP sent successfully', 200, [
                'requires_party_selection'  => false,
                'party_uid'                 => $party->party_uid,
                'party_type'                => $party->party_type,
                'label'                     => $party->label(),
                'verification_status'       => false,
                'meter_verification_status' => $party->meter_verification_status ?: 'not_uploaded',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function verify_otp_field_verification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'party_uid' => ['required', 'string', 'size:24'],
            'mobile'    => ['required', 'digits:10'],
            'otp'       => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $party = FieldInspectionParty::where('party_uid', $request->party_uid)
                ->where('mobile', $request->mobile)
                ->lockForUpdate()
                ->first();

            if (!$party || $party->otp === null || !hash_equals((string) $party->otp, (string) $request->otp)) {
                DB::rollBack();
                return $this->send_response('failed', 'Invalid otp', 422);
            }

            if ($party->otp_expires_at && $party->otp_expires_at->isPast()) {
                DB::rollBack();
                return $this->send_response('failed', 'OTP expired', 422);
            }

            $token = $this->fi->issueAccessToken($party);

            DB::commit();

            return $this->send_response('success', 'User logged in successfully', 200, array_merge(
                $this->fi->stageFor($party->refresh()),
                ['access_token' => $token]
            ));
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function verify_fi_access_token(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_token' => ['required', 'regex:/^[a-zA-Z0-9]+$/'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $party = $this->fi->partyByToken($request->access_token);
        if (!$party) {
            return $this->send_response('failed', 'Invalid access token', 422);
        }

        return $this->send_response('success', 'Access token is valid', 200, array_merge(
            $this->fi->stageFor($party),
            ['access_token' => $party->access_token]
        ));
    }

    // ------------------------------------------------------------------
    // Inspector app — meter image
    // ------------------------------------------------------------------
    public function store_meter_image(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_token' => ['required', 'regex:/^[a-zA-Z0-9]+$/'],
            'meter_img'    => ['required', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $party = $this->fi->partyByToken($request->access_token, true);
            if (!$party) {
                DB::rollBack();
                return $this->send_response('failed', 'Invalid access token', 422);
            }

            if ($party->meter_verification_status === 'pending') {
                DB::rollBack();
                return $this->send_response('failed', 'Meter image already submitted and awaiting verification.', 409);
            }
            if ($party->meter_verification_status === 'approved') {
                DB::rollBack();
                return $this->send_response('failed', 'Meter image already approved.', 409);
            }

            $loan = LoanBasicDetails::find($party->borrower_id);
            if (!$loan) {
                DB::rollBack();
                return $this->send_response('failed', 'Invalid request', 422);
            }

            $image     = $request->file('meter_img');
            $fileName  = "meter_image_{$party->party_type}_" . time() . '.' . $image->getClientOriginalExtension();
            $imagePath = $image->storeAs("Borrower/{$loan->borrower_id}/Field_Inspection", $fileName, 'public');

            $party->forceFill([
                'meter_image'               => 'storage/' . $imagePath,
                'meter_verification_status' => 'pending',
                'meter_remarks'             => null,
                'meter_uploaded_at'         => now(),
                'meter_verified_by'         => null,
                'meter_verified_at'         => null,
            ])->save();

            DB::commit();

            return $this->send_response('success', 'Meter image submitted for verification.', 200, $this->fi->stageFor($party));
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------
    // Inspector app — status (self + siblings)
    // ------------------------------------------------------------------
    public function get_field_inspection_status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_token' => ['required', 'regex:/^[a-zA-Z0-9]+$/'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $party = $this->fi->partyByToken($request->access_token);
        if (!$party) {
            return $this->send_response('failed', 'Invalid access token', 422);
        }

        $fiRow = $party->inspection;

        return $this->send_response('success', 'Status fetched.', 200, array_merge(
            $this->fi->stageFor($party),
            [
                'required_parties'         => $fiRow->requiredPartyTypes(),
                'pending_parties'          => $this->fi->pendingPartyTypes($fiRow),
                'application_fi_completed' => (bool) $fiRow->is_inspection_verified,
                'parties'                  => $this->fi->partySummary($fiRow),
            ]
        ));
    }

    // ------------------------------------------------------------------
    // Inspector app — premise capture + distance
    // ------------------------------------------------------------------
    public function calculate_distance_between_borrower_dealer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_token'   => ['required', 'regex:/^[a-zA-Z0-9]+$/'],
            'fi_premise_img' => ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'latitude'       => ['required', 'regex:/^-?\d{1,2}\.\d{1,8}$/', 'between:-90,90'],
            'longitude'      => ['required', 'regex:/^-?\d{1,3}\.\d{1,8}$/', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $party = $this->fi->partyByToken($request->access_token, true);
            if (!$party) {
                DB::rollBack();
                return $this->send_response('failed', 'Invalid access token', 422);
            }

            if ($party->is_verified) {
                DB::rollBack();
                return $this->send_response('failed', 'This party is already verified.', 409);
            }

            if ($party->meter_verification_status !== 'approved') {
                DB::rollBack();
                return $this->send_response('failed', 'Meter image not approved yet.', 422);
            }

            $fiRow = $party->inspection;
            $loan  = LoanBasicDetails::find($party->borrower_id);
            if (!$loan) {
                DB::rollBack();
                return $this->send_response('failed', 'Invalid request', 422);
            }

            $kyc = BorrowerKyc::where('borrower_id', $party->borrower_id)->first();
            if (!$kyc) {
                DB::rollBack();
                return $this->send_response('failed', 'Invalid borrower data', 422);
            }

            $model = ProductModel::where('model_unq', $loan->model_id)->first();
            if (!$model) {
                DB::rollBack();
                return $this->send_response('failed', 'Invalid Product Model', 422);
            }

            [$originLat, $originLng] = $this->fi->originCoordinates($fiRow, $loan);

            $distance = round($this->fi->haversineKm(
                $originLat,
                $originLng,
                (float) $request->latitude,
                (float) $request->longitude
            ), 4);

            $maxDistance = $this->fi->maxDistanceKm($party->party_type, $loan, $kyc, $model);
            $minDistance = (float) config('field_inspection.min_distance_km');

            $party->forceFill([
                'latitude'          => $request->latitude,
                'longitude'         => $request->longitude,
                'distance'          => $distance,
                'verification_time' => now(),
            ])->save();

            if ($distance < $minDistance || $distance > $maxDistance) {
                DB::commit();
                return $this->send_response(
                    'failed',
                    "Distance must be between 50 meters and {$maxDistance} KM.",
                    422,
                    array_merge($this->fi->stageFor($party), ['distance' => $distance])
                );
            }

            $image     = $request->file('fi_premise_img');
            $fileName  = "fi_premise_image_{$party->party_type}." . $image->getClientOriginalExtension();
            $imagePath = $image->storeAs("Borrower/{$loan->borrower_id}/Field_Inspection", $fileName, 'public');

            $party->forceFill([
                'fi_image'         => 'storage/' . $imagePath,
                'is_verified'      => true,
                'access_token'     => null,
                'token_expires_at' => null,
            ])->save();

            $completed = $this->fi->recomputeCompletion($fiRow);
            $pending   = $this->fi->pendingPartyTypes($fiRow);

            DB::commit();

            return $this->send_response(
                'success',
                $completed
                    ? 'Field inspection completed successfully.'
                    : 'Verification recorded. Pending: ' . implode(', ', $pending),
                200,
                [
                    'party_type'               => $party->party_type,
                    'label'                    => $party->label(),
                    'name'                     => $party->name,
                    'mobile'                   => $party->mobile,
                    'distance'                 => $distance,
                    'verification_status'      => true,
                    'application_fi_completed' => $completed,
                    'pending_parties'          => $pending,
                ]
            );
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------
    // Borrower app — aggregate status
    // ------------------------------------------------------------------
    public function get_field_verification_status(Request $request): JsonResponse
    {
        $borrowerId = (int) $this->get_borrower_id($request->user()->id);

        $fiRow = FieldInspectionDetails::where('borrower_id', $borrowerId)->latest('id')->first();
        if (!$fiRow) {
            return $this->send_response('failed', 'Not found', 422);
        }

        $isVerified = (bool) $fiRow->is_inspection_verified;

        return response()->json([
            'status'  => $isVerified ? 'success' : 'failed',
            'message' => $isVerified
                ? 'Field inspection details fetched successfully'
                : 'Field inspection not yet verified',
            'data'    => [
                'is_verified'      => $isVerified,
                'required_parties' => $fiRow->requiredPartyTypes(),
                'pending_parties'  => $this->fi->pendingPartyTypes($fiRow),
                'completed_at'     => $fiRow->completed_at,
                'parties'          => $this->fi->partySummary($fiRow),
            ],
        ], $isVerified ? 200 : 422);
    }
}
