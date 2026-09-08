<?php

namespace App\Http\Controllers;

use App\Models\DealerDesignatedAuthority;
use App\Models\Lenders;
use App\Models\LenderDirectors;
use Exception;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;

class DealerDesignatedAuthorityController extends Controller
{
    private $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    // Store a new designated authority
    public function store(Request $request)
    {
        try {
            $inputs = $request->all();
            $rules = [
                'dealer_id' => 'required|integer|exists:dealers,id',
                'name' => 'required|string|max:255',
                'mobile' => 'required|string|digits:10|unique:dealer_designated_authorities',
                'alternative_mobile' => 'nullable|digits:10|different:mobile',
                'aadhar' => 'required|string|digits:12|unique:dealer_designated_authorities',
                // 'pan' => 'required|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:dealer_designated_authorities',
                // 'bank_statement' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'itr_file' => 'required|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'photo' => 'required|file|mimes:jpg,png,jpeg|max:5120',
            ];

            $validator = Validator::make($inputs, $rules);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // if($request->mobile)

            $dealer_unq = 'FIN-DEA-' . $inputs['dealer_id'];
            $base_dir = 'Dealers/' . $dealer_unq . '/authority';

            $dealer_designated_authority = new DealerDesignatedAuthority();
            $dealer_designated_authority->fill($inputs);

            // Handle file uploads
            $fileKeys = ['bank_statement', 'itr_file', 'photo'];
            foreach ($fileKeys as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs($base_dir, $filename, 'public');
                    $dealer_designated_authority->$fileKey = 'storage/' . $path;
                }
            }

            $dealer_designated_authority->save();

            return $this->helper_controller->send_response('success', 'Dealer Designated Authority created successfully', 201, $dealer_designated_authority);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    // Update an existing designated authority
    public function update(Request $request)
    {
        try {
            $inputs = $request->all();
            if(!isset($inputs['id']) || empty($inputs['id'])) {
                return $this->helper_controller->send_response('failed', "Id is required", 422);
            }
            $id = $inputs['id'];

            $rules = [
                'id' => 'required|integer',
                'dealer_id' => 'required|integer|exists:dealers,id',
                'name' => 'required|string|max:255',
                'mobile' => 'required|string|digits:10|unique:dealer_designated_authorities,mobile,'. $id,
                // 'alternative_mobile' => 'nullable|digits:10|sometimes|different:mobile',
                // 'aadhar' => 'required|string|digits:12|unique:dealer_designated_authorities,aadhar,'. $id,
                // 'pan' => 'required|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|unique:dealer_designated_authorities,pan,'. $id,
                // 'bank_statement' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'itr_file' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
                // 'photo' => 'nullable|file|mimes:jpg,png,jpeg|max:5120',
            ];

            $validator = Validator::make($inputs, $rules);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            
            if(isset($inputs['alternative_mobile']) && !empty($inputs['alternative_mobile']) && strlen($inputs['alternative_mobile']) > 5) {
                if(isset($inputs['mobile']) && !empty($inputs['mobile']) && $inputs['alternative_mobile'] == $inputs['mobile']) {
                    $errors['alternative_mobile'] = "Mobile number and alternative mobile number can not be same";
                    return $this->helper_controller->send_response('failed', $errors['alternative_mobile'], 422);
                    $errorCount++;
                }

                if (isset($inputs['alternative_mobile']) && !preg_match('/^\d{10}$/', $inputs['alternative_mobile'])) {
                    $errors['alternative_mobile'] = "Alternative mobile number must be 10 digits";
                    return $this->helper_controller->send_response('failed', $errors['alternative_mobile'], 422);
                    $errorCount++;
                }
            }
        
            
            $dealer_designated_authority = DealerDesignatedAuthority::find($id);
            if(!$dealer_designated_authority) {
                return $this->helper_controller->send_response('failed', "Not found", 422);
            }
            $dealer_unq = 'FIN-DEA-' . $inputs['dealer_id'];
            $base_dir = 'Dealers/' . $dealer_unq . '/authority';

            $dealer_designated_authority->fill($inputs);

            // Handle file uploads
            $fileKeys = ['bank_statement', 'itr_file', 'photo'];
            foreach ($fileKeys as $fileKey) {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $filename = $fileKey . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs($base_dir, $filename, 'public');
                    $dealer_designated_authority->$fileKey = 'storage/' . $path;
                }
            }

            $dealer_designated_authority->save();

            return $this->helper_controller->send_response('success', 'Dealer Designated Authority updated successfully', 200, $dealer_designated_authority);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    // Delete a designated authority
    public function destroy($id)
    {
        try {
            $dealer_designated_authority = DealerDesignatedAuthority::find($id);
            if(!$dealer_designated_authority) {
                return $this->helper_controller->send_response('failed', "Not found", 422);
            }
            $dealer_designated_authority->delete();

            return $this->helper_controller->send_response('success', 'Dealer Designated Authority deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    // Retrieve a designated authority
    public function show($id)
    {
        try {
            $dealer_designated_authority = DealerDesignatedAuthority::find($id);
            if(!$dealer_designated_authority) {
                return $this->helper_controller->send_response('failed', "Not found", 422);
            }

            return $this->helper_controller->send_response('success', "Records fetched successfully", 200, $dealer_designated_authority);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    // List all designated authorities
    public function index()
    {
        try {
            $dealer_designated_authorities = DealerDesignatedAuthority::all()->orderBy('created_at', 'desc');

            return $this->helper_controller->send_response('success', "Record fetched successfully", 200, $dealer_designated_authorities);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function get_delaer_authorities($dealer_id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if ($user) {
                $logged_in_user =  response()->json($user);
                $logged_in_user_id = auth()->id();

                $lender_details = Lenders::where('lender_auth_id', $logged_in_user_id)->first();
                if(!$lender_details) {
                    $lender_director_details = LenderDirectors::where('auth_id', $logged_in_user_id)->first();
                    if($lender_director_details) {
                        $lender_id = $lender_director_details->lender_id;
                    }
                } else {
                    $lender_id = $lender_details->id;
                }

                if(isset($lender_id)) {
                    return $this->helper_controller->send_response('failed', 'Unauthorized access', 401);
                }
                    // return $this->helper_controller->send_response('success', '', 200);
                // return $this->helper_controller->send_response('failed', 'Unauthorized access', 401);
            }
            $dealer_designated_authorities = DealerDesignatedAuthority::where('dealer_id', $dealer_id)
                                                ->with(['dealer:id,name,dealer_unq'])
                                                ->get();

            return $this->helper_controller->send_response('success', "Record fetched successfully", 200, $dealer_designated_authorities);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }
    
}
