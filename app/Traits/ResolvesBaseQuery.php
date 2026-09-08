<?php

namespace App\Traits;

use App\Models\BorrowerIdentifier;
use App\Models\BorrowerIdentifierMapping;
use App\Models\Dealer;
use App\Models\LenderBorrowerGroup;
use App\Models\LenderDirectors;
use App\Models\Lenders;
use App\Models\LoanBasicDetails;
use App\Models\LoanServiceProvider;
use App\Models\LspExecutiveDetails;
use App\Models\Manufacturers;
use App\Models\ProductModel;
use App\Services\BorrowerVisibilityService;
use Illuminate\Database\Eloquent\Builder;

trait ResolvesBaseQuery
{
    /**
     * Determines the correct query scope and a flag indicating whether
     * rejection reasons should be shown.
     *
     * Returns:
     *   [Builder|null $query, bool $showRejectionReason, bool $empty]
     *
     * $empty = true means the caller should return zero-result response
     * immediately (e.g. LSP with no executives assigned).
     *
     * Precedence:
     *   1. Lender Executive (34) → scope by borrower_ids linked to lender
     *   2. Lender (29)           → scope by borrower_ids linked to lender
     *   3. LSP                   → scope by their executive IDs
     *   4. Dealer auth user      → scope by their dealer_unq
     *   5. OEM user              → scope by dealers linked via ProductModel
     *   6. user_type === 36      → scope by BorrowerIdentifierMapping
     *   7. Admin full access     → no scope (see all)
     *   8. Admin hierarchy       → scope by visible dealer_unq set
     *
     * @param  int                       $authId
     * @param  BorrowerVisibilityService  $visibilityService
     * @return array{0: Builder|null, 1: bool, 2: bool}
     */
    private function resolveBaseQuery(int $authId, BorrowerVisibilityService $visibilityService): array
    {
        $base = LoanBasicDetails::query();
        $user = auth()->user();

        // ── 1 & 2. Lender Executive (34) / Lender (29) ───────────────────────
        if ($user->user_type === 34) {
            $lenderExecutive = LenderDirectors::where('auth_id', $authId)->first();
            $lender_id = $lenderExecutive?->lender_id;
        } elseif ($user->user_type === 29) {
            $lender = Lenders::where('lender_auth_id', $authId)->first();
            $lender_id = $lender?->id;
        } else {
            $lender_id = null;
        }

        if ($lender_id) {
            $lenderBorrowerGroups = LenderBorrowerGroup::where('lender_id', $lender_id)->pluck('borrower_id');
            $otherBorrowers = LoanBasicDetails::where('lender_id', $lender_id)
                ->whereNotIn('id', $lenderBorrowerGroups)
                ->pluck('id');

            $borrowerIds = $lenderBorrowerGroups->merge($otherBorrowers)->unique()->values()->toArray();

            if (empty($borrowerIds)) {
                return [null, false, true];
            }

            $base->whereIn('id', $borrowerIds);
            return [$base, false, false];
        }

        // ── 3. LSP ────────────────────────────────────────────────────────────
        $lsp = LoanServiceProvider::where('lsp_auth_id', $authId)->first();
        if ($lsp) {
            $execIds = LspExecutiveDetails::where('lsp_id', $lsp->id)->pluck('id');

            if ($execIds->isEmpty()) {
                return [null, false, true]; // empty result
            }

            $base->whereIn('executive_id', $execIds);
            return [$base, false, false];
        }

        // ── 4. Dealer auth ────────────────────────────────────────────────────
        $dealerAuth = Dealer::where('dealer_auth_id', $authId)->first();
        if ($dealerAuth) {
            $base->where('dealer_id', $dealerAuth->dealer_unq);
            return [$base, false, false];
        }

        // ── 5. OEM user ───────────────────────────────────────────────────────
        $manufacturer = Manufacturers::where('oem_auth_id', $authId)->first();
        if ($manufacturer) {
            $dealerIds = ProductModel::whereRaw(
                    'LOWER(manufacture_name) LIKE ?',
                    ['%' . strtolower($manufacturer->manufacturer) . '%']
                )
                ->distinct()
                ->pluck('dealer_id');

            if ($dealerIds->isEmpty()) {
                return [null, false, true];
            }

            $dealerUnqs = Dealer::whereIn('id', $dealerIds)->pluck('dealer_unq');

            if ($dealerUnqs->isEmpty()) {
                return [null, false, true];
            }

            $base->whereIn('dealer_id', $dealerUnqs);
            return [$base, false, false];
        }

        // ── 6. user_type === 36 ───────────────────────────────────────────────
        if ($user->user_type === 36) {
            $identifier = BorrowerIdentifier::whereRaw(
                'users @> ?::jsonb',
                [json_encode([$authId])]
            )->first();

            if ($identifier) {
                $borrowerIds = BorrowerIdentifierMapping::where('identifier_id', $identifier->id)
                    ->where('row_status', 'A')
                    ->pluck('borrower_id')
                    ->toArray();

                if (!empty($borrowerIds)) {
                    $base->whereIn('id', $borrowerIds);
                }
            }

            return [$base, true, false];
        }

        // ── 7 & 8. Admin user — full access or hierarchy ──────────────────────
        if ($user->is_all_borrower_accessible) {
            return [$base, true, false];
        }

        $visibleDealerUnqs = $visibilityService->resolveVisibleDealerUnqs($authId);

        if ($visibleDealerUnqs->isEmpty()) {
            return [null, true, true];
        }

        $base->whereIn('dealer_id', $visibleDealerUnqs);
        return [$base, true, false];
    }
}