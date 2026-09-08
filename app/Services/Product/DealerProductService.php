<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\{DealerProduct, OemDynamicProduct, ProductRuleSet};
use Illuminate\Support\Facades\DB;

final class DealerProductService
{
    /** Assign a product to one or many dealers (idempotent, reactivates if previously inactive). */
    public function assign(int $productId, array $dealerIds, ?int $userId): array
    {
        return DB::transaction(function () use ($productId, $dealerIds, $userId) {
            $result = [];
            foreach (array_unique($dealerIds) as $dealerId) {
                $result[] = DealerProduct::updateOrCreate(
                    ['dealer_id' => $dealerId, 'product_id' => $productId],
                    ['status' => 'active', 'assigned_by' => $userId]
                );
            }
            return $result;
        });
    }

    /** Toggle one assignment active/inactive. */
    public function toggle(int $productId, int $dealerId): DealerProduct
    {
        $link = DealerProduct::where('product_id', $productId)->where('dealer_id', $dealerId)->firstOrFail();
        $link->update(['status' => $link->status === 'active' ? 'inactive' : 'active']);
        return $link;
    }

    /** Remove assignment. Also archives any dealer-override rule set for this pair. */
    public function remove(int $productId, int $dealerId): void
    {
        DB::transaction(function () use ($productId, $dealerId) {
            DealerProduct::where('product_id', $productId)->where('dealer_id', $dealerId)->delete();

            ProductRuleSet::where('product_id', $productId)
                ->where('scope', 'dealer_override')
                ->where('dealer_id', $dealerId)
                ->where('status', 'active')
                ->update(['status' => 'archived', 'effective_to' => now()]);
        });
    }

    /** Dealers a product is assigned to. */
    public function dealersFor(int $productId)
    {
        return DealerProduct::with('dealer:id,name,dealer_unq')
            ->where('product_id', $productId)->get();
    }

    /**
     * Tag many products to many dealers (cartesian). Idempotent — reactivates existing links.
     * @param int[] $productIds
     * @param int[] $dealerIds
     * @return array{created:int, reactivated:int, pairs:int}
     */
    public function bulkAssign(array $productIds, array $dealerIds, ?int $userId): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $dealerIds  = array_values(array_unique(array_map('intval', $dealerIds)));

        return DB::transaction(function () use ($productIds, $dealerIds, $userId) {
            $created = 0; $reactivated = 0;

            foreach ($productIds as $pid) {
                foreach ($dealerIds as $did) {
                    $existing = DealerProduct::where('product_id', $pid)
                        ->where('dealer_id', $did)->first();

                    if ($existing) {
                        if ($existing->status !== 'active') {
                            $existing->update(['status' => 'active', 'assigned_by' => $userId]);
                            $reactivated++;
                        }
                    } else {
                        // DealerProduct::create([
                        //     'product_id'  => $pid,
                        //     'dealer_id'   => $did,
                        //     'status'      => 'active',
                        //     'assigned_by' => $userId,
                        // ]);
                        DealerProduct::upsert(
                            [[
                                'product_id'  => $pid,
                                'dealer_id'   => $did,
                                'status'      => 'active',
                                'assigned_by' => $userId,
                                'created_at'  => now(),
                                'updated_at'  => now(),
                            ]],
                            ['product_id', 'dealer_id'],
                            ['status', 'assigned_by', 'updated_at']
                        );
                        $created++;
                    }
                }
            }

            return [
                'created'     => $created,
                'reactivated' => $reactivated,
                'pairs'       => count($productIds) * count($dealerIds),
            ];
        });
    }

    /** Dealers belonging to an OEM. */
    public function dealersByOem(int $oemId)
    {
        return Dealer::where('oem_id', $oemId)
            ->get(['id', 'dealer_unq', 'name']);
    }
}
