<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use App\Models\DealerShops;
use App\Models\LoanBasicDetails;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class DealerShopsController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    // Create Dealer Shop
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dealer_id'   => 'required|integer|exists:dealers,id',
            'shop_name'   => 'required|string|max:255|regex:/^[a-zA-Z0-9\-\/\s]+$/',
            'longitude'   => 'required|string|max:20',
            'latitude'    => 'required|string|max:20',
            'lat_long_image' => 'required|image|mimes:jpg,jpeg,png|max:5120',
            'address' => 'required|string|max:255|regex:/^[a-zA-Z0-9\-\/\(\),:\s]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $dealer = Dealer::find($request->dealer_id);
        if (!$dealer) {
            return $this->helper_controller->send_response('failed', 'Dealer not found.', 422);
        }

        $path = $request->file('lat_long_image')->storeAs('Dealers/' . $dealer->dealer_unq . '/shops', $request->file('lat_long_image')->getClientOriginalName(), 'public');
        $storagePath = 'storage/' . $path;

        $shop = new DealerShops();
        $shop->dealer_id = $request->dealer_id;
        $shop->shop_name = $request->shop_name;
        $shop->longitude = $request->longitude;
        $shop->latitude = $request->latitude;
        $shop->lat_long_image = $storagePath;
        $shop->status = 'active';
        $shop->modified_by = auth()->id();
        $shop->address = $request->address;
        $shop->ip = $this->helper_controller->get_user_ip();
        $shop->save();

        return $this->helper_controller->send_response('success', 'Dealer shop created successfully.', 200, $shop);
    }

    // Update Dealer Shop
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'shop_name'   => 'required|string|max:255|regex:/^[a-zA-Z0-9\-\/\s]+$/',
            'longitude'   => 'sometimes|required|string|max:20',
            'latitude'    => 'sometimes|required|string|max:20',
            'lat_long_image' => 'sometimes|required|image|mimes:jpg,jpeg,png|max:5120',
            'address' => 'required|string|max:255|regex:/^[a-zA-Z0-9\-\/\(\),:\s]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $shop = DealerShops::find($id);
        if (!$shop) {
            return $this->helper_controller->send_response('failed', 'Dealer shop not found.', 422);
        }

        if ($request->filled('shop_name')) $shop->shop_name = $request->shop_name;
        if ($request->filled('longitude')) $shop->longitude = $request->longitude;
        if ($request->filled('latitude')) $shop->latitude = $request->latitude;
        if ($request->filled('address')) $shop->address = $request->address;
        
        if ($request->hasFile('lat_long_image')) {
            $dealer = Dealer::find($shop->dealer_id);
            if (!$dealer) {
                return $this->helper_controller->send_response('failed', 'Dealer not found.', 422);
            }

            $path = $request->file('lat_long_image')->storeAs('Dealers/' . $dealer->dealer_unq . '/shops', $request->file('lat_long_image')->getClientOriginalName(), 'public');
            $shop->lat_long_image = 'storage/' . $path;
        }

        $shop->modified_by = auth()->id();
        $shop->ip = $this->helper_controller->get_user_ip();
        $shop->save();

        return $this->helper_controller->send_response('success', 'Dealer shop updated successfully.', 200, $shop);
    }

    // Get Shops by Dealer ID
    public function getShopsByDealerId($dealer_id)
    {
        $shops = DealerShops::with(['modifiedBy'])->where('dealer_id', $dealer_id)
                            ->where('status', '!=', 'deleted')
                            ->get();

        return $this->helper_controller->send_response('success', 'Dealer shops retrieved successfully.', 200, $shops);
    }

    // Change Dealer Shop Status
    public function changeStatus(Request $request, $id)
    {
        $shop = DealerShops::find($id);
        if (!$shop) {
            return $this->helper_controller->send_response('failed', 'Dealer shop not found.', 422);
        }

        $shop->status       = ($shop->status == 'active') ? 'inactive' : 'active';
        $shop->modified_by  = auth()->id();
        $shop->ip           = $this->helper_controller->get_user_ip();
        $shop->save();

        return $this->helper_controller->send_response('success', 'Dealer shop status updated successfully.', 200, $shop);
    }

    public function get_dealer_shop_by_borrower_id($borrower_id) {
        try {
            if(!is_numeric($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }
            
            $loan_details = LoanBasicDetails::find($borrower_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }
            
            $dealer = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
            if(!$dealer) {
                return $this->helper_controller->send_response('failed', "Dealer not found", 422);
            }

            $shops = DealerShops::select([
                            'id', 'shop_name', 'address'
                        ])
                        ->where('dealer_id', $dealer->id)
                        ->where('status', 'active')
                        ->get();
                            
            return $this->helper_controller->send_response('success', 'Dealer shop list fetched', 200, $shops);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred '. $e->getMessage(), 422);
        }
    }

    public function get_dealer_shops_borrower_id_b2c(Request $request) {
        try {
            if (!auth()->check()) {
                return $this->helper_controller->send_response('failed', "Unauthenticated access, please login", 422);
            }
            
            $user_id                = auth()->id();
            $borrower_primary_id    = $this->helper_controller->get_borrower_id($user_id);

            if(!is_numeric($borrower_primary_id)) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $loan_details = LoanBasicDetails::find($borrower_primary_id);
            if(!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }
            
            $dealer = Dealer::where('dealer_unq', $loan_details->dealer_id)->first();
            if(!$dealer) {
                return $this->helper_controller->send_response('failed', "Dealer not found", 422);
            }

            $shops = DealerShops::select([
                            'id', 'shop_name', 'address'
                        ])
                        ->where('dealer_id', $dealer->id)
                        ->where('status', 'active')
                        ->get();
                            
            return $this->helper_controller->send_response('success', 'Dealer shop list fetched', 200, $shops);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred '. $e->getMessage(), 422);
        }
    }


    public function update_existing_shops(Request $request) {
        $dealers = Dealer::where('latitude', '<>','')->where('longitude', '<>','')->get();
        foreach($dealers as $dealer) {
            $dealer_id  = $dealer->id;
            $longitude  = $dealer->longitude;
            $latitude   = $dealer->latitude;

            $dealer_shop = DealerShops::where('dealer_id', $dealer_id)
                            ->where('longitude', $dealer->longitude)
                            ->where('latitude', $dealer->latitude)->first();
            if(!$dealer_shop) {
                $dealer_shop                    = new DealerShops();
                $dealer_shop->dealer_id         = $dealer->id;
                $dealer_shop->shop_name         = $dealer->name;
                $dealer_shop->longitude         = $dealer->longitude;
                $dealer_shop->latitude          = $dealer->latitude;
                $dealer_shop->lat_long_image    = $dealer->lat_long_image;
                $dealer_shop->address = trim($dealer->address_line_1 . $dealer->address_line_2);
                $dealer_shop->status            = 'active';
                $dealer_shop->modified_by       = auth()->id() ?? null;
                $dealer_shop->ip                = $this->helper_controller->get_user_ip();
            }

        }
    }
}
