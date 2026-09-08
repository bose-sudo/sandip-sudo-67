<?php

namespace App\Http\Controllers;

use App\Models\LenderManufacturerProductCharges;
use App\Models\Manufacturers;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ManufacturersController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function manufacturer_screener() {
        $records = Manufacturers::select(['id', 'manufacturer'])->where('is_deleted', false)->orderBy('manufacturer', 'asc')->get();
        return $this->helper_controller->send_response('success', 'Manufacturer type fetched successfully', 200, $records);
    }

    public function create(Request $request)
    {
        $request->validate([
            'manufacturer' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
        ]);
    
        try {
            // Convert the manufacturer input to lowercase for case-insensitive comparison
            $manufacturerInput = strtolower($request->manufacturer);
    
            // Check if the manufacturer already exists (case-insensitive)
            $existingManufacturer = Manufacturers::whereRaw('LOWER(manufacturer) = ?', [$manufacturerInput])->first();
    
            if ($existingManufacturer) {
                return $this->helper_controller->send_response('failed', 'Manufacturer already exists', 400);
            }
    
            // Create a new manufacturer record
            $manufacturer = new Manufacturers();
            $manufacturer->manufacturer = $request->manufacturer;
            $manufacturer->save();
    
            return $this->helper_controller->send_response('success', 'Manufacturer created successfully', 200, $manufacturer);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function read($id)
    {
        try {
            $manufacturer = Manufacturers::find($id);
            if(!$manufacturer) {
                return $this->helper_controller->send_response('failed', 'Manufacturer not found', 400);
            }
            return $this->helper_controller->send_response('success', 'Manufacturer details fetched', 200, $manufacturer);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'manufacturer' => 'required|regex:/^[a-zA-Z\s]+$/|max:255',
        ]);
    
        try {
            // Convert the manufacturer input to lowercase for case-insensitive comparison
            $manufacturerInput = strtolower($request->manufacturer);
    
            // Check if the manufacturer already exists (case-insensitive) excluding the current record
            $existingManufacturer = Manufacturers::whereRaw('LOWER(manufacturer) = ?', [$manufacturerInput])
                                                 ->where('id', '!=', $id)
                                                 ->first();
    
            if ($existingManufacturer) {
                return $this->helper_controller->send_response('failed', 'Manufacturer already exists', 400);
            }
    
            // Find the manufacturer record and update it
            $manufacturer = Manufacturers::find($id);
            if(!$manufacturer) {
                return $this->helper_controller->send_response('failed', 'Manufacturer not found', 400);
            }
            $manufacturer->manufacturer = $request->manufacturer;
            $manufacturer->save();
    
            return $this->helper_controller->send_response('success', 'Manufacturer updated successfully', 200, $manufacturer);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }
    

    public function delete($id)
    {
        try {
            $manufacturer = Manufacturers::find($id);
            if(!$manufacturer) {
                return $this->helper_controller->send_response('failed', 'Manufacturer not found', 400);
            }
            $manufacturer->is_deleted = true;
            $manufacturer->deleted_at = Carbon::now()->format('Y-m-d H:i:s');
            $manufacturer->save();

            return $this->helper_controller->send_response('success', 'Manufacturer deleted successfully', 200, $manufacturer);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 400);
        }
    }

    public function store_lender_manufacturer_charges(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'lender_id' => 'required|integer',
                'manufacturer_id' => 'required|integer',
                'charge_details' => 'required|array',
                'charge_details.*.charge_head' => 'required|string|max:255',
                'charge_details.*.charge_type' => 'nullable|string|max:100',
                'charge_details.*.charge_value' => 'nullable|integer',
                'token_id' => 'nullable|string|max:100' // Optional if updating
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $validated = $validator->validated();
            $userId = auth()->user()->id; // assuming you have authentication
            $ip = $this->helper_controller->get_user_ip();

            if (!empty($validated['token_id'])) {
                LenderManufacturerProductCharges::where('token_id', $validated['token_id'])
                    ->update(['status' => 'HISTORY']);
                
                // Generate a new token in the format "dmY.primary_key"
                $tokenId = date('dmY') . (LenderManufacturerProductCharges::max('id') + 1);
            } else {
                // Generate a unique token for new records
                $tokenId = date('dmY') . (LenderManufacturerProductCharges::max('id') + 1);
            }

            // Create new records
            foreach ($validated['charge_details'] as $charge) {
                LenderManufacturerProductCharges::create([
                    'token_id' => $tokenId,
                    'lender_id' => $validated['lender_id'],
                    'manufacturer_id' => $validated['manufacturer_id'],
                    'charge_head' => $charge['charge_head'],
                    'charge_type' => $charge['charge_type'] ?? '',
                    'charge_value' => $charge['charge_value'] ?? 0,
                    'status' => 'ACTIVE',
                    'ip' => $ip,
                    'user_id' => $userId,
                ]);
            }

            return $this->helper_controller->send_response('success', 'Records processed successfully', 201);

        } catch (Exception $e) {
            
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function getActiveCharges($manufacturer_id)
    {
        try {
            $charges = LenderManufacturerProductCharges::where('manufacturer_id', $manufacturer_id)
                ->where('status', 'ACTIVE')
                ->with([
                    'manufacturer:id,manufacturer',
                    'lender:id,name'
                ])
                ->get();

            if ($charges->isEmpty()) {
                return $this->helper_controller->send_response('failed', 'No active charges found for this manufacturer.', 200);
            }

            return $this->helper_controller->send_response('success', "Charges fetched successfully", 200, $charges);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 500);
        }
    }

}
