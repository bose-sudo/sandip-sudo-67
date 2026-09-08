<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\{AssignDealerRequest, AuthorRulesRequest, BulkAssignRequest};
use App\Models\OemDynamicProduct;
use App\Services\Product\{DealerProductService, ProductOnboardingService};
use App\Traits\ApiResponse;
use Throwable;

class DealerProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DealerProductService   $dealerService,
        private readonly ProductOnboardingService $ruleService,
    ) {}
    
    /** Assign product → dealer(s). Body: { dealer_ids: [1,2,3] } */
    public function assign(AssignDealerRequest $request, int $productId)
    {
        if (!OemDynamicProduct::whereKey($productId)->exists())
            return $this->send_response('failed', 'Product not found', 422);

        $links = $this->dealerService->assign($productId, $request->validated()['dealer_ids'], auth()->id());
        return $this->send_response('success', 'Dealers assigned', 200, $links);
    }

    public function bulkAssign(BulkAssignRequest $request)
    {
        // validate all products are dynamic + exist (request handles existence; check dynamic here if needed)
        $r = $this->dealerService->bulkAssign(
            $request->validated()['product_ids'],
            $request->validated()['dealer_ids'],
            auth()->id()
        );
        return $this->send_response('success',
            "Tagged {$r['pairs']} pair(s): {$r['created']} new, {$r['reactivated']} reactivated", 200, $r);
    }

    /** Toggle active/inactive. Body: { dealer_id } */
    public function toggle(int $productId)
    {
        $dealerId = (int) request('dealer_id');
        if (!$dealerId) return $this->send_response('failed', 'dealer_id required', 422);

        try {
            $link = $this->dealerService->toggle($productId, $dealerId);
            return $this->send_response('success', 'Assignment ' . $link->status, 200, $link);
        } catch (Throwable $e) {
            return $this->send_response('failed', 'Assignment not found', 422);
        }
    }

    /** Remove. Body: { dealer_id } */
    public function remove(int $productId)
    {
        $dealerId = (int) request('dealer_id');
        if (!$dealerId) return $this->send_response('failed', 'dealer_id required', 422);

        $this->dealerService->remove($productId, $dealerId);
        return $this->send_response('success', 'Assignment removed', 200);
    }

    /** List dealers a product is assigned to. */
    public function dealers(int $productId)
    {
        return $this->send_response(
            'success',
            'Assigned dealers',
            200,
            $this->dealerService->dealersFor($productId)
        );
    }

    /** Publish a dealer-specific override rule set (new version). Body: { rules:[...] } */
    public function authorDealerRules(AuthorRulesRequest $request, int $productId, int $dealerId)
    {
        $product = OemDynamicProduct::find($productId);
        if (!$product) return $this->send_response('failed', 'Product not found', 422);

        try {
            $set = $this->ruleService->republishRules($product, $request->validated()['rules'], $dealerId);
            return $this->send_response('success', 'Dealer scheme published', 200, $set);
        } catch (Throwable $e) {
            return $this->send_response('failed', 'Error: ' . $e->getMessage(), 422);
        }
    }

    /** Load a dealer's active override rules (for edit form). Empty = dealer inherits default. */
    public function dealerRules(int $productId, int $dealerId)
    {
        $set = OemDynamicProduct::find($productId)?->dealerRuleSet($dealerId);
        return $this->send_response('success', 'Dealer active rules', 200, [
            'version' => $set?->version,
            'rules'   => $set ? $set->load('rules.conditions')->rules : [],
        ]);
    }

    public function dealersByOem(int $oemId)
    {
        return $this->send_response('success', 'Dealers fetched', 200,
            $this->dealerService->dealersByOem($oemId)
        );
    }
}
