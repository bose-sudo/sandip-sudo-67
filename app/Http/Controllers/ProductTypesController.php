<?php

namespace App\Http\Controllers;

use App\Models\ProductTypes;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class ProductTypesController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function product_type_screener() {
        $records = ProductTypes::select(['id', 'product_type'])->where('is_deleted', false)->orderBy('product_type', 'asc')->get();
        return $this->helper_controller->send_response('success', 'Product type fetched successfully', 200, $records);
    }

    public function create(Request $request)
    {
        try {
            // Validate the input
            $request->validate([
                'product_type' => 'required|regex:/^[a-zA-Z0-9\s,\.()-]+$/|max:255',
            ]);
    
            // Convert the product_type input to lowercase for case-insensitive comparison
            $productTypeInput = strtolower($request->product_type);
    
            // Check if the product type already exists (case-insensitive)
            $existingProductType = ProductTypes::whereRaw('LOWER(product_type) = ?', [$productTypeInput])->first();
    
            if ($existingProductType) {
                return $this->helper_controller->send_response('failed', 'Product Type already exists', 400);
            }
    
            // Create a new product type record
            $productType = new ProductTypes();
            $productType->product_type = $request->product_type;
            $productType->save();
    
            return $this->helper_controller->send_response('success', 'Product Type created successfully', 200, $productType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function read($id)
    {
        try {
            $productType = ProductTypes::find($id);
            if(!$productType) {
                return $this->helper_controller->send_response('failed', 'Product type not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Product Type details fetched', 200, $productType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Validate the input
            $request->validate([
                'product_type' => 'required|regex:/^[a-zA-Z0-9\s,\.()-]+$/|max:255',
            ]);
    
            // Convert the product_type input to lowercase for case-insensitive comparison
            $productTypeInput = strtolower($request->product_type);
    
            // Check if the product type already exists (case-insensitive), excluding the current record
            $existingProductType = ProductTypes::whereRaw('LOWER(product_type) = ?', [$productTypeInput])
                ->where('id', '!=', $id)  // Exclude the current record from the check
                ->first();
    
            if ($existingProductType) {
                return $this->helper_controller->send_response('failed', 'Product Type already exists', 400);
            }
    
            // Find and update the existing product type record
            $productType = ProductTypes::find($id);
            if(!$productType) {
                return $this->helper_controller->send_response('failed', 'Product type not found', 400);
            }
            $productType->product_type = $request->product_type;
            $productType->save();
    
            return $this->helper_controller->send_response('success', 'Product Type updated successfully', 200, $productType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $productType = ProductTypes::find($id);
            if(!$productType) {
                return $this->helper_controller->send_response('failed', 'Product type not found', 400);
            }
            $productType->is_active  = false;
            $productType->is_deleted = true;
            $productType->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $productType->save();

            return $this->helper_controller->send_response('success', 'Product Type deleted successfully', 200, $productType);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
}
