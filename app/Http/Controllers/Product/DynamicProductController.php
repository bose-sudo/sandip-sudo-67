<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\{StoreDynamicProductRequest, AuthorRulesRequest};
use App\Models\OemDynamicProduct;
use App\Services\Product\ProductOnboardingService;
use App\Services\Product\RuleViewTransformer;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class DynamicProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductOnboardingService $service) {}

    public function index(Request $request)
    {
        $q = OemDynamicProduct::with('oem')->where('status', '<>', 'deleted');

        if ($request->filled('oem_id'))       $q->where('oem_id', $request->oem_id);
        if ($request->filled('product_type')) $q->where('product_type', $request->product_type);
        if ($request->filled('product_name')) {
            $q->whereRaw('LOWER(product_name) LIKE ?', ['%' . strtolower($request->product_name) . '%']);
        }

        $products = $q->orderByDesc('id')
            ->paginate((int) $request->get('number_of_records', 10), ['*'], 'page', (int) $request->get('page', 1));

        $products->getCollection()->transform(function ($p) {
            $p->manufacture_name = $p->oem?->manufacturer;
            $p->oem_unq = $p->oem?->oem_unq;
            $p->iot_amount = $p->iot ?? 0;
            $p->fldg_amount = $p->fldg ?? 0;
            $p->interest_rate = $p->interest ?? 0;
            $p->approve_multi = (bool) ($p->approve_multi ?? false);
            return $p;
        });

        return $this->send_response('success', 'Products fetched', 200, $products);
    }

    public function store(StoreDynamicProductRequest $request)
    {
        $data  = $request->validated();
        $rules = $data['rules'] ?? [];
        unset($data['rules']);

        try {
            $product = $this->service->createWithRules($data, $rules, auth()->id(), $request->ip());
            return $this->send_response('success', 'Product created', 200, $product);
        } catch (Throwable $e) {
            return $this->send_response('failed', 'Error: ' . $e->getMessage(), 422);
        }
    }

    /** Product + only the ACTIVE default rule set (not archived versions). */
    public function show(int $id)
    {
        $product = OemDynamicProduct::with([
            'oem',
            'ruleSets' => fn($q) => $q->where('scope', 'product_default')
                ->where('status', 'active'),
            'ruleSets.rules.conditions',
        ])->find($id);

        if (!$product) {
            return $this->send_response('failed', 'Product not found', 422);
        }

        $product->setAttribute('product_type_id',
            \App\Support\LegacyProductType::id($product->product_type));

        return $this->send_response('success', 'Product details', 200, $product);
    }

    /** Full version history (audit). */
    public function history(int $id)
    {
        $product = OemDynamicProduct::with(['oem', 'ruleSets.rules.conditions'])->find($id);
        return $product
            ? $this->send_response('success', 'Product version history', 200, $product)
            : $this->send_response('failed', 'Product not found', 422);
    }

    /** Update product static fields (in-place, no versioning). */
    public function update(StoreDynamicProductRequest $request, int $id)
    {
        $product = OemDynamicProduct::find($id);
        if (!$product) return $this->send_response('failed', 'Product not found', 422);

        $updated = $this->service->updateProduct($product, $request->validated(), auth()->id(), $request->ip());
        return $this->send_response('success', 'Product updated', 200, $updated);
    }

    /** Create OR re-author the product-default rule set (always new version). */
    public function authorRules(AuthorRulesRequest $request, int $id)
    {
        $product = OemDynamicProduct::find($id);
        if (!$product) return $this->send_response('failed', 'Product not found', 422);

        try {
            $set = $this->service->republishRules($product, $request->validated()['rules']);
            return $this->send_response('success', 'Rules published', 200, $set);
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Error: ' . $e->getMessage(), 422);
        }
    }

    /** Load current active rules for the edit form. */
    public function rules(int $id)
    {
        $product = OemDynamicProduct::find($id);
        if (!$product) return $this->send_response('failed', 'Product not found', 422);

        $set = $product->defaultRuleSet();
        return $this->send_response('success', 'Active rules', 200, [
            'version' => $set?->version,
            'rules'   => $set ? $set->load('rules.conditions')->rules : [],
        ]);
    }

    public function changeStatus(Request $request, int $id)
    {
        $product = OemDynamicProduct::find($id);
        if (!$product) return $this->send_response('failed', 'Product not found', 422);

        $product->update([
            'status'      => $product->status === 'active' ? 'inactive' : 'active',
            'modified_by' => auth()->id(),
            'ip'          => $request->ip(),
        ]);
        return $this->send_response('success', 'Status updated', 200);
    }

    // DynamicProductController
    public function view(int $id, RuleViewTransformer $transformer)
    {
        $product = OemDynamicProduct::with(['oem', 'ruleSets' => fn ($q) =>
            $q->where('scope','product_default')->where('status','active'), 'ruleSets.rules.conditions'])
            ->find($id);

        if (!$product) return $this->send_response('failed', 'Product not found', 422);

        return $this->send_response('success', 'Product view', 200, $transformer->forProduct($product));
    }
}
