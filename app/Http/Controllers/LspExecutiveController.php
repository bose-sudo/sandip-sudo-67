<?php

namespace App\Http\Controllers;

use App\Mail\Mailer;
use App\Models\AdminUser;
use App\Models\Dealer;
use App\Models\Lenders;
use App\Models\LenderServiceProvider;
use App\Models\LoanServiceProvider;
use App\Models\LspExecutiveDetails;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class LspExecutiveController extends Controller
{
    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    public function get_lsp_screener(Request $request) {
        try {
            $lenders = LoanServiceProvider::select(['id', 'name'])->get();
            if(!$lenders || $lenders->count() < 0) {
                return $this->helper_controller->send_response('failed', "No records found", 200);
            }
            return $this->helper_controller->send_response('success', "Lenders Fetched", 200, $lenders);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function get_lsp_executive_list(Request $request)
    {
        try {            
            $validator = Validator::make($request->all(), [
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'name' => 'nullable|string|max:255|regex:/^[\pL\s\-\'\.]+$/u',
                'mobile' => 'nullable|numeric|digits_between:4,10',
                'alternative_number' => 'nullable|numeric|digits_between:4,10',
                'pan' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                'aadhar' => 'nullable|numeric|digits_between:4,12',
                'branch_id' => 'nullable|integer|min:1',
                'dealer_id' => 'nullable|integer|min:1',
                'lsp_id' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
                'number_of_records' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // Query to get LSP list
            $query = LspExecutiveDetails::with(['branchLocation', 'state:id,state']);

            $logged_in_user = auth()->user()->id;
            $lsp_details = LoanServiceProvider::where('lsp_auth_id', $logged_in_user)->first();
            if($lsp_details) {
                $query->where('lsp_id', $lsp_details->id);
            }
            
            $logged_in_user = auth()->user()->id;
            $dealer_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
            if($dealer_details) {
                $query->where('dealer_id', $dealer_details->id);
            }

            if($request->filled('dealer_id')) {
                $query->where('dealer_id', $request->dealer_id);
            }

            if($request->filled('lsp_id')) {
                $query->where('lsp_id', $request->lsp_id);
            }
            // Filters
            if ($request->has('start_date') && $request->has('end_date') && !empty($request->start_date) && !empty($request->end_date)) {
                $query->whereBetween('date_of_joining', [$request->start_date, $request->end_date]);
            }

            if ($request->has('name') && !empty($request->name)) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->name) . '%']);
            }

            if ($request->has('mobile') && !empty($request->mobile)) {
                $mobile = trim($request->mobile);
                $query->where('mobile', 'like', '%'.$mobile.'%');
            }
            
            if ($request->has('branch_id') && !empty($request->branch_id)) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('alternative_number') && !empty($request->alternative_number)) {
                $query->where('alternative_number', 'LIKE', '%' . $request->alternative_number . '%');
            }

            if ($request->has('pan') && !empty($request->pan)) {
                $query->where('pan', 'LIKE', '%' . $request->pan . '%');
            }

            if ($request->has('aadhar') && !empty($request->aadhar)) {
                $query->where('aadhar', 'LIKE', '%' . $request->aadhar . '%');
            }

            // Pagination
            $numberOfRecords = $request->get('number_of_records', 10);
            $page = $request->get('page', 1);

            $lspList = $query->orderBy('id', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);

            return $this->helper_controller->send_response('success', 'LSP List retrieved successfully', 200, $lspList);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
    

    public function store(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|regex:/^[\pL\s\-\'\.]+$/u',
            'mobile' => 'required|digits:10|unique:lsp_executive_details,mobile',
            'email' => 'required|email|max:255|unique:lsp_executive_details,email',
            'password' => 'required|string|min:6',
            // 'department' => 'required|integer',
            // 'user_type' => 'required|integer',
            'date_of_joining' => 'nullable|date|before_or_equal:today',
            'city' => 'nullable|string|max:100|regex:/^[\pL\s\-\'\.]+$/u',
            'district' => 'nullable|string|max:100|regex:/^[\pL\s\-\'\.]+$/u',
            'pincode' => 'nullable|string|digits:6',
            'address' => 'nullable|string|max:255',
            'alternative_number' => 'nullable|digits:10|different:mobile',
            'pan' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
            'aadhar' => 'nullable|digits:12',
            'lsp_id' => 'nullable|integer',
            'dealer_id' => 'nullable|integer',
            'executive_type' => 'required|string|in:Dealer,LSP',
            'branch_id' => 'required|integer',

            'state' => 'required|integer|exists:state_masters,id',
        ]);


        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {

            $logged_in_user = auth()->user()->id;

            // $executive_type = $request->executive_type;
            // if($request->filled('executive_type')) {
            //     if($executive_type != 'Dealer' || $executive_type != 'LSP') {
            //         $executive_type = '';
            //     }
            // }

            $lsp_details = LoanServiceProvider::where('lsp_auth_id', $logged_in_user)->first();
            if($lsp_details) {
                $lsp_id = $lsp_details->id;
                // if($executive_type == '') {
                //     $executive_type = 'LSP';
                // }
            } else {
                $lsp_id = $request->lsp_id;
            }
            if(isset($request->date_of_joining) && !empty($request->date_of_joining)) {

                $date_of_joining = DateTime::createFromFormat('Y-m-d', $request->date_of_joining);
                if (!$date_of_joining || $date_of_joining->format('Y-m-d') !== $request->date_of_joining) {
                    $errors['date_of_joining'] = "Date of joining must be in the format YYYY-MM-DD";
                    $errorCount++;
                    return $this->helper_controller->send_response('failed', $errors['date_of_joining'], 422);
                } elseif ($date_of_joining > new DateTime()) {
                    $errors['date_of_joining'] = "Date of joining cannot be a future date";
                    return $this->helper_controller->send_response('failed', $errors['date_of_joining'], 422);
                    $errorCount++;
                }
            } else {
                $date_of_joining = null;
            }

            // if($request->executive_type == 'Dealer') {
            $dealer_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
            if($dealer_details) {
                $dealer_id = $dealer_details->id;
                // if($executive_type == '') {
                //     $executive_type = 'Dealer';
                // }
            } else {
                $dealer_id = $request->dealer_id;
            }
            

            if(empty($dealer_id)) {
                $dealer_id = null;
            }
            if(empty($lsp_id)) {
                $lsp_id = null;
            }

            // Create LspExecutiveDetails
            $executiveDetails               = new LspExecutiveDetails();
            $executiveDetails->executive_id = 0;
            $executiveDetails->lsp_id       = $lsp_id ?? null;
            $executiveDetails->date_of_joining = $date_of_joining ?? null;
            $executiveDetails->city         = $request->city;
            $executiveDetails->district     = $request->district;
            $executiveDetails->pincode      = $request->pincode;
            $executiveDetails->address      = $request->address;
            $executiveDetails->alternative_number = $request->alternative_number ?? '';
            $executiveDetails->pan          = $request->pan;
            $executiveDetails->aadhar       = $request->aadhar;
            $executiveDetails->user_id      = auth()->user()->id ?? '';
            $executiveDetails->name         = $request->name;
            $executiveDetails->mobile       = $request->mobile;
            $executiveDetails->email        = $request->email;
            $executiveDetails->password     = Hash::make($request->password);
            $executiveDetails->is_active    = true;
            $executiveDetails->branch_id    = $request->branch_id ?? null;
            $executiveDetails->executive_type    = $request->executive_type ?? null;
            $executiveDetails->dealer_id    = $dealer_id ?? null;
            $executiveDetails->state        = $request->state ?? null;

            if ($executiveDetails->save()) {
                    $emailData = [
                    'name' => $request->name,
                    'admin_url' => 'http://uat.finayo.tech',
                    'user_id' => $request->email,
                    'password' => $request->password
                ];
                $viewName = 'emails.example';
                $subject = "Finayo Login Credential";
            
                // Mail::to($request->email)->send(new Mailer($viewName, $emailData, $subject));
            
                // return response()->json(['message' => 'Email sent successfully']);
            }

            return $this->helper_controller->send_response('success', 'LSP Executive created successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Read an LSP Executive
    public function show($id)
    {
        try {
            $executiveDetails = LspExecutiveDetails::find($id);
            if(!$executiveDetails) {
                return $this->helper_controller->send_response('failed', "Executive not found", 422);
            }
            return $this->helper_controller->send_response('success', 'LSP Executive retrieved successfully', 200, $executiveDetails);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Update an LSP Executive
    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'state' => 'required|integer|exists:state_masters,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            // Get all input data
            $input = $request->all();
    
            $errorCount = 0;
            $errorArray = [];
    
            // Manual validation
            if (!isset($input['id']) || empty($input['id'])) {
                $errorCount++;
                $errorArray['id'] = "Id is required";
                return $this->helper_controller->send_response('failed', $errorArray['id'], 422);
            }
            if (!isset($input['branch_id']) || empty($input['branch_id'])) {
                $errorCount++;
                $errorArray['branch_id'] = "Branch id is required";
                return $this->helper_controller->send_response('failed', $errorArray['branch_id'], 422);
            }
            
            if (!isset($input['executive_type']) || empty($input['executive_type'])) {
                $errorCount++;
                $errorArray['executive_type'] = "Executive type is required";
                return $this->helper_controller->send_response('failed', $errorArray['executive_type'], 422);
            } elseif (!in_array($input['executive_type'], ['Dealer', 'LSP'])){
                $errorCount++;
                $errorArray['executive_type'] = "Invalid executive type";
                return $this->helper_controller->send_response('failed', $errorArray['executive_type'], 422);

            }
            // $executive_type = $request->executive_type;
            // if($request->filled('executive_type')) {
            //     if($executive_type != 'Dealer' || $executive_type != 'LSP') {
            //         $executive_type = '';
            //     }
            // }

            if (!isset($input['name']) || empty($input['name']) || !preg_match('/^[a-zA-Z\s]+$/', $input['name'])) {
                $errorCount++;
                $errorArray['name'] = "Name is required and should contain only alphabets and spaces";
                return $this->helper_controller->send_response('failed', $errorArray['name'], 422);
            }
    
            if (!isset($input['mobile']) || empty($input['mobile']) || !preg_match('/^\d{10}$/', $input['mobile']) || LspExecutiveDetails::where('mobile', $input['mobile'])->where('id', '<>', $input['id'])->exists()) {
                $errorCount++;
                $errorArray['mobile'] = "Mobile number is required, must be exactly 10 digits, and must be unique";
                return $this->helper_controller->send_response('failed', $errorArray['mobile'], 422);
            }
    
            if (!isset($input['email']) || empty($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL) || LspExecutiveDetails::where('email', $input['email'])->where('id', '<>', $input['id'])->exists()) {
                $errorCount++;
                $errorArray['email'] = "Email is required, must be a valid email address, and must be unique";
                return $this->helper_controller->send_response('failed', $errorArray['email'], 422);
            }
    
            if(isset($request->date_of_joining) && !empty($request->date_of_joining)) {

                $date_of_joining = DateTime::createFromFormat('Y-m-d', $request->date_of_joining);
                if (!$date_of_joining || $date_of_joining->format('Y-m-d') !== $request->date_of_joining) {
                    $errors['date_of_joining'] = "Date of joining must be in the format YYYY-MM-DD";
                    $errorCount++;
                    return $this->helper_controller->send_response('failed', $errors['date_of_joining'], 422);
                } elseif ($date_of_joining > new DateTime()) {
                    $errors['date_of_joining'] = "Date of joining cannot be a future date";
                    return $this->helper_controller->send_response('failed', $errors['date_of_joining'], 422);
                    $errorCount++;
                }
            } else {
                $date_of_joining->date_of_joining = null;
            }
    
            if (isset($input['city']) && !empty($input['city']) && !preg_match('/^[\pL\s\-\'\.]+$/u', $input['city'])) {
                $errorCount++;
                $errorArray['city'] = "City should contain only alphabets, spaces, hyphens, apostrophes, and periods";
                return $this->helper_controller->send_response('failed', $errorArray['city'], 422);
            }
    
            if (isset($input['district']) && !empty($input['district']) && !preg_match('/^[\pL\s\-\'\.]+$/u', $input['district'])) {
                $errorCount++;
                $errorArray['district'] = "District should contain only alphabets, spaces, hyphens, apostrophes, and periods";
                return $this->helper_controller->send_response('failed', $errorArray['district'], 422);
            }
    
            if (isset($input['pincode']) && !empty($input['pincode']) && !preg_match('/^\d{6}$/', $input['pincode'])) {
                $errorCount++;
                $errorArray['pincode'] = "Pincode must be exactly 6 digits";
                return $this->helper_controller->send_response('failed', $errorArray['pincode'], 422);
            }
    
            if (isset($input['alternative_number']) && $input['alternative_number'] != 'null' && !empty($input['alternative_number']) && !preg_match('/^\d{10}$/', $input['alternative_number'])) {
                $errorCount++;
                $errorArray['alternative_number'] = "Alternative number must be exactly 10 digits";
                return $this->helper_controller->send_response('failed', $errorArray['alternative_number'], 422);
            }
            if(isset($input['mobile']) && isset($input['alternative_number']) && $input['alternative_number'] != 'null' && ($input['mobile'] == $input['alternative_number'])) {
                $errorArray['alternative_number'] = "Alternative number and mobile number must be different";
                return $this->helper_controller->send_response('failed', $errorArray['alternative_number'], 422);    
            }
    
            if (isset($input['pan']) && $input['pan'] != 'null' && !empty($input['pan']) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $input['pan'])) {
                $errorCount++;
                $errorArray['pan'] = "PAN must be in the format AAAAA1234A";
                return $this->helper_controller->send_response('failed', $errorArray['pan'], 422);
            }
    
            if (isset($input['aadhar']) && $input['aadhar'] != 'null' && !empty($input['aadhar']) && !preg_match('/^\d{12}$/', $input['aadhar'])) {
                $errorCount++;
                $errorArray['aadhar'] = "Aadhar must be exactly 12 digits";
                return $this->helper_controller->send_response('failed', $errorArray['aadhar'], 422);
            }
            if($input['aadhar'] == null) {
                $input['aadhar'] = '';
            }
            if($input['pan'] == null) {
                $input['pan'] = '';
            }
            if($input['alternative_number'] == null) {
                $input['alternative_number'] = '';
            }
    
            $logged_in_user = auth()->user()->id;
            $lsp_details = LoanServiceProvider::where('lsp_auth_id', $logged_in_user)->first();
            if($lsp_details) {
                $lsp_id = $lsp_details->id;
                // if($executive_type == '') {
                //     $executive_type = 'LSP';
                // }
            } else {
                if (isset($input['lsp_id']) && !empty($input['lsp_id']) && !is_numeric($input['lsp_id'])) {
                    $errorCount++;
                    $errorArray['lsp_id'] = "LSP ID must be an integer";
                    return $this->helper_controller->send_response('failed', $errorArray['lsp_id'], 422);
                }
                $lsp_id = $input['lsp_id'] ?? null;
            }

            if($request->executive_type == 'Dealer') {
                $dealer_details = Dealer::where('dealer_auth_id', $logged_in_user)->first();
                if($dealer_details) {
                    $dealer_id = $dealer_details->id;
                    // if($executive_type == '') {
                    //     $executive_type = 'Dealer';
                    // }
                } else {
                    if (isset($input['dealer_id']) && !empty($input['dealer_id']) && !is_numeric($input['dealer_id'])) {
                        $errorCount++;
                        $errorArray['dealer_id'] = "Dealer ID must be an integer";
                        return $this->helper_controller->send_response('failed', $errorArray['dealer_id'], 422);
                    }
                    $dealer_id = $input['dealer_id'] ?? null;
                }
            } else {
                $lsp_id = $lsp_id;
            }
            
            $id = $request->id;
    
            // Find the LspExecutiveDetails record
            $executiveDetails = LspExecutiveDetails::find($id);
            if (!$executiveDetails) {
                return $this->helper_controller->send_response('failed', "Executive not found", 422);
            }
    
            $executive_id = $executiveDetails->executive_id;
    
    
            // Update AdminUser
    
            // Update LspExecutiveDetails
            $executiveDetails->lsp_id           = $lsp_id ?? null;
            $executiveDetails->date_of_joining  = $date_of_joining ?? null;
            $executiveDetails->city             = $request->city;
            $executiveDetails->district         = $request->district;
            $executiveDetails->pincode          = $request->pincode;
            $executiveDetails->address          = $request->address;
            $executiveDetails->alternative_number = $input['alternative_number'];
            $executiveDetails->pan              = $input['pan'] ?? '';
            $executiveDetails->aadhar           = $input['aadhar'] ?? '';
            $executiveDetails->name             = $request->name;
            $executiveDetails->mobile           = $request->mobile;
            $executiveDetails->email            = $request->email;
            $executiveDetails->branch_id        = $request->branch_id ?? '';
            $executiveDetails->executive_type   = $request->executive_type ?? null;
            $executiveDetails->dealer_id        = $dealer_id ?? null;
            $executiveDetails->state            = $request->state ?? $executiveDetails->state;
            $executiveDetails->save();
    
            return $this->helper_controller->send_response('success', 'LSP Executive updated successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    // Delete an LSP Executive
    public function destroy($id)
    {
        try {
            $executiveDetails = LspExecutiveDetails::find($id);
            if(!$executiveDetails) {
                return $this->helper_controller->send_response('failed', "Executive not found", 422);
            }
            $executiveDetails->delete();
            
            // $adminUser = AdminUser::find($id);
            // if(!$adminUser) {
            //     return $this->helper_controller->send_response('failed', "Record not found", 422);
            // }
            // $adminUser->delete();

            return $this->helper_controller->send_response('success', 'LSP Executive deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function update_status($id)
    {
        try {
            $executiveDetails = LspExecutiveDetails::find($id);
            if(!$executiveDetails) {
                return $this->helper_controller->send_response('failed', "Executive not found", 422);
            }
            if($executiveDetails->is_active == true) {
                $executiveDetails->is_active = false;
            } else {
                $executiveDetails->is_active = true;
            }
            $executiveDetails->save();
    
            return $this->helper_controller->send_response('success', 'LSP Executive status updated', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }    
}
