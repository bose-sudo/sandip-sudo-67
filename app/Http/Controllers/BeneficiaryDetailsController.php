<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryDetails;
use App\Models\Dealer;
use App\Models\Manufacturers;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BeneficiaryDetailsController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    // Index Method with Validation for Filters
    public function index(Request $request)
    {
        try {
            // Validate input filters
            $validator = Validator::make($request->all(), [
                'beneficiary_name'  => ['nullable', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Allow alphabets and spaces only
                'account_number'    => 'nullable|integer', // Account number should be integer
                'ifsc_code'         => ['nullable', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'], // Alphanumeric for IFSC code
                'micr_code'         => 'nullable|digits_between:3,9', // MICR code should be numeric (9 digits)
                'status'            => 'nullable|in:active,inactive',
                'user_type'         => 'nullable|in:DEALER,OEM',
                'per_page'          => 'nullable|integer|min:1|max:100', // Pagination limit
                'page'              => 'nullable|integer|min:1', // Page number
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $query = BeneficiaryDetails::query()->with([
                            'actionTakenBy:id,name,email',
                        ]);

            // Apply filters
            if ($request->filled('account_number')) {
                $query->where('account_number', 'like', '%' . $request->account_number . '%');
            }
            if ($request->filled('beneficiary_name')) {
                $query->whereRaw('LOWER(beneficiary_name) LIKE ?', ['%' . strtolower($request->beneficiary_name) . '%']);
            }
            if ($request->filled('ifsc_code')) {
                $query->where('ifsc_code', 'like', '%' . $request->ifsc_code . '%');
            }
            if ($request->filled('micr_code')) {
                $query->where('micr_code', 'like', '%' . $request->micr_code . '%');
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('user_type')) {
                $query->where('user_type', $request->user_type);
            }
            date_default_timezone_set('Asia/Kolkata');

            // Pagination
            $perPage = $request->get('per_page', 10); // Default 10 records per page
            $page = $request->get('page', 1); // Default to first page

            // Apply pagination and fetch results
            $beneficiaries = $query->orderBy('id', 'desc')->paginate($perPage, ['*'], 'page', $page);

            
            $response_data = $beneficiaries->map(function ($item) {
                if($item->user_type == 'DEALER') {
                    $dealer = Dealer::find($item->user_id);
                    $user_name = $dealer ? $dealer->name : '';
                    $unq_id = $dealer ? $dealer->dealer_unq : '';
                } else {
                    $manufacturer = Manufacturers::find($item->user_id);
                    $user_name = $manufacturer ? $manufacturer->manufacturer : '';
                    $unq_id = $manufacturer ? $manufacturer->oem_unq : '';
                }
                return [
                    "id" => $item->id,
                    "user_type" => $item->user_type,
                    "dealer_name" => $user_name ?? '',
                    "dealer_unq" => $unq_id ?? '',
                    "beneficiary_name" => $item->beneficiary_name,
                    "account_number" => $item->account_number,
                    "ifsc_code" => $item->ifsc_code,
                    "micr_code" => $item->micr_code,
                    "status" => $item->status,
                    "remarks" => $item->remarks,
                    "action_taken_by" => $item->action_taken_by,
                    "ip" => $item->ip,
                    "created_at" => date('d-m-Y H:i:s', strtotime($item->created_at)),
                    "updated_at" => date('d-m-Y H:i:s', strtotime($item->updated_at)),
                    'modified_by' => $item->actionTakenBy ?? null,
                ];
            });
            $data = [
                'data' => $response_data,
                'total' => $beneficiaries->total(),
                'from' => $beneficiaries->firstItem(),
                'to' => $beneficiaries->lastItem(),
                'current_page' => $beneficiaries->currentPage(),
                'last_page' => $beneficiaries->lastPage(),
                'per_page' => $beneficiaries->perPage(),
            ];

            return $this->helper_controller->send_response('success', "Beneficiary list fetched", 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // Store method
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_type' => 'required|in:DEALER,OEM',
            'user_id' => 'required|integer',        
            'beneficiary_name' => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Allow alphabets and spaces only
            'account_number' => 'required|integer', // Account number should be integer
            'ifsc_code' => ['required', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'], // Alphanumeric for IFSC code
            'micr_code' => 'nullable|regex:/^\d{9}$/', // MICR code should be numeric (9 digits)
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $beneficiary                    = new BeneficiaryDetails();
            $beneficiary->user_type         = $request->user_type;
            $beneficiary->user_id           = $request->user_id;
            $beneficiary->beneficiary_name  = $request->beneficiary_name;
            $beneficiary->account_number    = $request->account_number;
            $beneficiary->ifsc_code         = $request->ifsc_code;
            $beneficiary->micr_code         = $request->micr_code;
            $beneficiary->status            = 'active';
            $beneficiary->action_taken_by   = auth()->id(); // Logged-in user ID
            $beneficiary->ip                = $this->helper_controller->get_user_ip();
            $beneficiary->save();

            DB::commit();
            return $this->helper_controller->send_response('success', "Beneficiary added successfully", 200, $beneficiary);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // Show method
    public function show($id)
    {
        try {
            $beneficiary = BeneficiaryDetails::find($id);
            if(!$beneficiary) {
                return $this->helper_controller->send_response('failed', "Beneficiary not found", 422);
            }
            
            return $this->helper_controller->send_response('success', "Beneficiary details fetched", 200, $beneficiary);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // Update method
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'user_type'         => 'required|in:DEALER,OEM',        
            'beneficiary_name'  => ['required', 'regex:/^[a-zA-Z\s]+$/', 'max:255'], // Allow alphabets and spaces only
            'account_number'    => 'required|integer', // Account number should be integer
            'ifsc_code'         => ['required', 'regex:/^[a-zA-Z0-9]+$/', 'max:25'], // Alphanumeric for IFSC code
            'micr_code'         => 'nullable|regex:/^\d{9}$/', // MICR code should be numeric (9 digits)
            // 'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $beneficiary = BeneficiaryDetails::find($id);
            if(!$beneficiary) {
                return $this->helper_controller->send_response('failed', "Beneficiary not found", 422);
            }

            $beneficiary->user_type         = $request->user_type ?? $beneficiary->user_type;
            $beneficiary->beneficiary_name  = $request->beneficiary_name ?? $beneficiary->beneficiary_name;
            $beneficiary->account_number    = $request->account_number ?? $beneficiary->account_number;
            $beneficiary->ifsc_code         = $request->ifsc_code ?? $beneficiary->ifsc_code;
            $beneficiary->micr_code         = $request->micr_code ?? $beneficiary->micr_code;
            $beneficiary->status            = $request->status ?? $beneficiary->status;
            $beneficiary->action_taken_by   = auth()->id(); // Logged-in user ID
            $beneficiary->ip                = $this->helper_controller->get_user_ip();
            $beneficiary->save();

            DB::commit();

            return $this->helper_controller->send_response('success', "Beneficiary details updated", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // Delete method
    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $beneficiary = BeneficiaryDetails::find($id);
            if(!$beneficiary) {
                return $this->helper_controller->send_response('failed', "Beneficiary not found", 422);
            }

            $beneficiary->status            = 'deleted';
            $beneficiary->action_taken_by   = auth()->id(); // Logged-in user ID
            $beneficiary->ip                = $this->helper_controller->get_user_ip();
            $beneficiary->save();

            DB::commit();

            return $this->helper_controller->send_response('success', "Beneficiary deleted successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();

            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    // Update Status Method
    public function update_status(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'remarks' => [
                    'required',
                    'regex:/^[a-zA-Z\s\-,.()]+$/', // Allows alphabets, spaces, hyphens, commas, full stops, and brackets
                ],
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            // Find the beneficiary record
            $beneficiary = BeneficiaryDetails::find($id);
            if(!$beneficiary) {
                return $this->helper_controller->send_response('failed', "Beneficiary not found", 422);
            }

            // Update the status
            $beneficiary->status            = ($beneficiary->status == 'active') ? 'inactive' : 'active';
            $beneficiary->remarks           = $request->remarks ?? null; // Logged-in user ID
            $beneficiary->action_taken_by   = auth()->id(); // Logged-in user ID
            $beneficiary->ip                = $this->helper_controller->get_user_ip();
            $beneficiary->save();

            DB::commit();

            return $this->helper_controller->send_response('success', "Beneficiary status updated successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();

            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

}
