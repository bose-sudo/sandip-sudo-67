<?php

namespace App\Http\Controllers;

use App\Models\BorrowerKyc;
use App\Models\Dealer;
use App\Models\LenderBorrowerGroup;
use App\Models\LenderDirectors;
use App\Models\Lenders;
use App\Models\LendersDataExportRequest;
use App\Models\LendersDataExportLimit;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\LspExecutiveDetails;
use App\Models\ProductModel;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LenderBorrowerGroupController extends Controller
{
    protected $helper_controller;
    protected $fileTypes;

    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
        // Define fixed file types
        $this->fileTypes = [
            'borrower_doc_download_limit',
            'agreement_download_limit',
            'cam_sheet_download_limit',
            'vkyc_doc_download_limit',
            'bureau_borrower_download_limit',
            'bureau_coapplicant_download_limit',
            'bureau_guarantor_download_limit',
        ];
    }

    /**
     * Index API with filters and proper pagination.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:PENDING,APPROVED,REJECTED,HOLD',
            'borrower_id' => 'nullable|string|regex:/^[a-zA-Z0-9\-]+$/',
            'lender_id' => 'nullable|string|regex:/^[a-zA-Z0-9\-]+$/',
            'start_date' => 'nullable|date|before_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $query = LenderBorrowerGroup::join('loan_basic_details', 'lender_borrower_groups.borrower_id', '=', 'loan_basic_details.id')->select(['lender_borrower_groups.*'])
                        ->with([
                            'lender:id,lender_unq,name',
                            'modifiedBy:id,name,email',
                        ]);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('borrower_id')) {
                $query->where('borrower_id', $request->borrower_id);
            }
            if ($request->filled('lender_id')) {
                $query->where('lender_id', $request->lender_id);
            }
            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // Pagination
            $page       = $request->input('page', 1);
            $perPage    = $request->input('per_page', 10);
            $lenders    = $query->orderBy('id', 'desc')->paginate($perPage, ['*'], 'page', $page);

            $response_data = $lenders->map(function ($item) {
                $borrower = LoanBasicDetails::find($item->borrower_id);
                $borrower_details = BorrowerKyc::select('name')->where('borrower_id', $item->borrower_id)->first();
                return [
                    'id' => $item->id,
                    'borrower_id' => $borrower ? $borrower->id : '',
                    'borrower_unq' => $borrower ? $borrower->borrower_id : '',
                    'borrower_name' => $borrower_details ? $borrower_details->name : '',
                    'status' => $item->status,
                    'ip' => $item->ip,
                    // 'download_left' => $item->download_left,
                    'borrower_doc_download_left' => $item->borrower_doc_download_left,
                    'agreement_download_left' => $item->agreement_download_left,
                    'cam_sheet_download_left' => $item->cam_sheet_download_left,
                    'remarks' => $item->remarks,
                    'created_at' => date('d-m-Y H:i:s', strtotime($item->created_at)),
                    'updated_at' => date('d-m-Y H:i:s', strtotime($item->updated_at)),
                    'lender' => $item->lender ?? null,
                    'modified_by' => $item->modifiedBy ?? null,
                ];
            });

            $data = [
                'data' => $response_data,
                'total' => $lenders->total(),
                'from' => $lenders->firstItem(),
                'to' => $lenders->lastItem(),
                'current_page' => $lenders->currentPage(),
                'last_page' => $lenders->lastPage(),
                'per_page' => $lenders->perPage(),
            ];

            return $this->helper_controller->send_response('success', 'Lenders retrieved successfully', 200, $data);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    /**
     * Store API.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'borrower_id' => 'required|array|min:1', // Accepts single or multiple values as an array
            'borrower_id.*' => 'integer|exists:loan_basic_details,id', // Validates each ID in the array
            'lender_id' => 'required|integer|exists:lenders,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $groups = [];
            foreach ($request->borrower_id as $borrowerId) {
                $group_exists = LenderBorrowerGroup::where('borrower_id', $borrowerId)
                                    ->where('status', 'PENDING')
                                    ->exists();

                // where('lender_id', $request->lender_id)

                if (!$group_exists) {
                    $groups[] = LenderBorrowerGroup::create([
                        'borrower_id' => $borrowerId,
                        'lender_id' => $request->lender_id,
                        'status' => "PENDING",
                        'download_left' => 2,
                        'borrower_doc_download_left' => 2,
                        'agreement_download_left' => 2,
                        'cam_sheet_download_left' => 2,
                        'modified_by' => auth()->user()->id,
                        'ip' => $this->helper_controller->get_user_ip(),
                    ]);
                }
            }

            DB::commit();

            return $this->helper_controller->send_response(
                'success',
                'Group(s) created successfully',
                200,
                $groups // Return all created groups
            );
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    /**
     * Update API.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:PENDING,APPROVED,REJECTED,HOLD',
            'download_left' => 'nullable|integer|min:0|max:5',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $group = LenderBorrowerGroup::find($id);
            if(!$group) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $group->update([
                'status' => $request->status ?? $group->status,
                'download_left' => $request->download_left ?? $group->download_left,
                'modified_by' => auth()->user()->id,
                'ip' => $this->helper_controller->get_user_ip(),
            ]);
            DB::commit();

            return $this->helper_controller->send_response('success', 'Group updated successfully', 200, $group);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    /**
     * Delete API.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'remarks' => [
                    'required',
                    'string',
                    'max:1000',
                    'regex:/^[a-zA-Z0-9\s,.\-()]+$/'
                ],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $group = LenderBorrowerGroup::find($id);
            if(!$group) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            date_default_timezone_set('Asia/Kolkata');

            $group->remarks     = $request->remarks;
            $group->is_deleted  = true;
            $group->deleted_at  = date('Y-m-d H:i:s');
            $group->save();

            return $this->helper_controller->send_response('success', 'Group deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "Unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function get_borrower_list(Request $request) {
        date_default_timezone_set('Asia/Kolkata');
        try {
            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }

            // $borrower_ids = LenderBorrowerGroup::where('lender_id', $check_user->id)->pluck('borrower_id');
            // // if ($borrower_ids->isEmpty()) {
            // //     return $this->helper_controller->send_response('failed', "No borrower assigned", 422);
            // // }

            // $otherBorrower_ids = LoanBasicDetails::where('lender_id', $check_user->id)->pluck('id');
            // if($otherBorrower_ids->isEmpty() && $borrower_ids->isEmpty()) {
            //     return $this->helper_controller->send_response('failed', "No borrower assigned", 422);
            // }
            $borrower_ids = LenderBorrowerGroup::where('lender_id', $lender_id)
                ->pluck('borrower_id');

            $otherBorrower_ids = []; //LoanBasicDetails::where('lender_id', $lender_id)
                // ->pluck('id');

            $allBorrowerIds = $borrower_ids->merge($otherBorrower_ids)->unique();

            if ($allBorrowerIds->isEmpty()) {
                return $this->helper_controller->send_response('failed', "No borrower assigned", 422);
            }

            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
            $page = $request->input('page', 1); // Default to page 1
            $stepFilter = $request->input('step'); // Step filter, might be null

            // Validation (optional, can be expanded)
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'step' => 'integer|min:1|nullable',
                'borrower_name' => 'string|regex:/^[a-zA-Z\s]+$/|nullable', // Only characters and spaces
                'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable', // Alphanumeric and hyphens
                'mobile_no' => 'digits:10|nullable',
                'pan_no' => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|nullable',
                'aadhar_no' => 'string|min:4|max:12|nullable',
                'dob' => 'date|nullable',
                'dealer_id' => 'nullable|integer',
                'lsp_id' => 'nullable|integer',
                'product_type_id' => 'nullable|integer',
                'category' => 'nullable|string|in:B2B,B2C',
                'application_date' => 'date|nullable',
                'status' => 'nullable|string|in:NEW,APPROVED,REJECTED,HOLD,CLOSED,DISBURSED',
            ]);
    
            // Check if validation fails
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
    
            $query = LoanBasicDetails::with([
                    'externalIdentifier:id,dealer_unq'
                ])
                ->join('users', 'loan_basic_details.user_id', '=', 'users.id')
                ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
                ->leftJoin('dealers', 'loan_basic_details.dealer_id', '=', 'dealers.dealer_unq')
                ->select(
                    'loan_basic_details.id',
                    'borrower_kycs.name as borrower_name',
                    'loan_basic_details.borrower_id as borrower_unq',
                    'loan_basic_details.status',
                    'loan_basic_details.user_id as user_primary_key',
                    'borrower_kycs.mobile as mobile_no',
                    'borrower_kycs.dob_date as date_of_birth',
                    'borrower_kycs.marital_status',
                    'borrower_kycs.husband_name as spouses_name',
                    'borrower_kycs.father_name',
                    'borrower_kycs.gender',
                    'borrower_kycs.email as email_id',
                    'borrower_kycs.pan as pan_no',
                    'borrower_kycs.aadhar as aadhar_no',
                    'borrower_kycs.driving_license_no',
                    'borrower_kycs.alternate_number1',
                    'loan_basic_details.id as borrower_primary',
                    'loan_basic_details.loan_amount',
                    'loan_basic_details.model',
                    'loan_basic_details.loan_term',
                    'loan_basic_details.executive_id',
                    'loan_basic_details.dealer_id',
                    'loan_basic_details.loan_application_steps',
                    'loan_basic_details.created_at as created_on',
                    'dealers.id as dealer_primary_id',
                    'dealers.dealer_unq',
                    'users.mobile as user_mobile_number',
                    'users.name as user_name',
                )->whereIn('loan_basic_details.id', $allBorrowerIds);

            if($request->filled('category')) {
                if ($request->category == 'B2B') {
                    $query->whereNotNull('loan_basic_details.executive_id');
                } elseif ($request->category == 'B2C') {
                    $query->whereNull('loan_basic_details.executive_id');
                } else {
                    return $this->helper_controller->send_response('failed', "Invalid category", 422, []);
                }
            }

            if ($request->filled('product_type_id')) {
                $query->where('product_id', $request->product_type_id);
            }   
            
            // Apply date filters if provided
            if ($request->filled('start_date') && $startDate && !empty($startDate)) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('loan_basic_details.created_at', '>=', $startDate);
            }
            
            // Filter by end date (end of the day in Asia/Kolkata timezone)
            if ($request->filled('end_date') && $endDate && !empty($endDate)) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('loan_basic_details.created_at', '<=', $endDate);
            }

            // Apply additional filters
            if ($request->filled('dealer_id')) {
                $dealerDetails = Dealer::find($request->input('dealer_id'));
                if($dealerDetails) {
                    $query->where('loan_basic_details.dealer_id', $dealerDetails->dealer_unq);
                }
            }

            if ($request->filled('lsp_id')) {
                $lsp_executives_ids = LspExecutiveDetails::where('lsp_id', $request->input('lsp_id'))->pluck('id');

                if ($lsp_executives_ids->isNotEmpty()) {  // Use isNotEmpty() instead of count()
                    $query->whereIn('loan_basic_details.executive_id', $lsp_executives_ids);
                }
            }

            if ($request->filled('borrower_name')) {
                $query->where(function($query) use ($request) {
                    $query->whereRaw('LOWER(borrower_kycs.name) LIKE ?', ['%' . strtolower($request->input('borrower_name')) . '%'])
                          ->orWhereRaw('LOWER(users.name) LIKE ?', ['%' . strtolower($request->input('borrower_name')) . '%']);
                });
            }
            if ($request->filled('borrower_id')) {
                $query->whereRaw('LOWER(loan_basic_details.borrower_id) like ?', ['%' . strtolower($request->input('borrower_id')) . '%']);
            }
            if ($request->filled('mobile_no')) {
                $query->where(function($query) use ($request) {
                    $query->where('borrower_kycs.mobile', 'like', '%' . $request->input('mobile_no') . '%')
                          ->orWhere('users.mobile', 'like', '%' . $request->input('mobile_no') . '%');
                });
            }
            if ($request->filled('pan_no')) {
                $query->where('borrower_kycs.pan', 'like', '%' . $request->input('pan_no') . '%');
            }
            if ($request->filled('aadhar_no')) {
                $query->where('borrower_kycs.aadhar', 'like', '%' . $request->input('aadhar_no') . '%');
            }
            if ($request->filled('dob')) {
                $query->whereDate('borrower_kycs.dob_date', $request->input('dob'));
            }
            
            if ($request->filled('status')) {
                $query->where('loan_basic_details.status', $request->input('status'));
            }

            if ($request->filled('application_date')) {
                $query->whereDate('loan_basic_details.created_at', $request->input('application_date'));
            }

            $year = $request->input('year', date('Y'));
            $month = $request->input('month', date('F'));
            
            if(isset($request->year)) {
                $year = $request->input('year', date('Y'));;
                $query->whereYear('loan_basic_details.created_at', $year);
            }
            
            if(isset($request->month)) {
                $year = $request->input('year', date('Y'));
                $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                $query->whereMonth('loan_basic_details.created_at', $monthNumber);
            }
    
            // Fetch results before pagination
            $borrowers = $query->orderBy('created_on', 'desc')->get();

            // Transform the result to replace null or empty values and include step status
            $borrowers = $borrowers->map(function ($borrower) {
                $externalIdentifier = $borrower->externalIdentifier;
                $stepStatus = '';
                $lastStep = null;
                if ($borrower->loan_application_steps) {
                    $steps = json_decode($borrower->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int)$lastStep['step'];
                            if ($stepValue < 8) {
                                $stepStatus = 'step1';
                            } elseif ($stepValue >= 8 && $stepValue < 26) {
                                $stepStatus = 'step2';
                            } elseif ($stepValue >= 26 && $stepValue < 27) {
                                $stepStatus = 'step3';
                            } elseif ($stepValue >= 27) {
                                $stepStatus = 'step4';
                            }
                        }
                    }
                }

                $borrower_user_id = $borrower->user_primary_key;
                // $userDetails = User::find($borrower_user_id);
                $user_phone_number = $borrower->user_mobile_number ?? '';
                $user_name = $borrower->user_name ?? '';

                $borrower_primary = $borrower->borrower_primary;

                $credit_summary = LoanApplicationSummary::where('borrower_id', $borrower_primary)->first();
                $borrower_cibil = ($credit_summary->cibil_score ?? '') >= 0 
                    ? (($credit_summary->cibil_score == 0 || $credit_summary->cibil_score == '-1') ? 'NTC' : $credit_summary->cibil_score) 
                    : '';

                $coapplicant_cibil = ($credit_summary->coappli_cibil_score ?? '') >= 0 
                    ? (($credit_summary->coappli_cibil_score == 0 || $credit_summary->coappli_cibil_score == '-1') ? 'NTC' : $credit_summary->coappli_cibil_score) 
                    : '';

                $guarantor_cibil = ($credit_summary->guarantor_cibil_score ?? '') >= 0 
                    ? (($credit_summary->guarantor_cibil_score == 0 || $credit_summary->guarantor_cibil_score == '-1') ? 'NTC' : $credit_summary->guarantor_cibil_score) 
                    : '';

                $user_address_details = $this->helper_controller->user_address_details($borrower_primary, "BORROWER");

                $borrower_mobile = $borrower->mobile_no ?? $user_phone_number ?? '';
                $borrower_name = $borrower->borrower_name ?? $user_name ?? '';

                return [
                    'id' => $borrower->borrower_primary ?? '',
                    'borrower_name' => $borrower_name ?? '',
                    'borrower_id' => $borrower->borrower_unq ?? '',
                    'loan_status' => $borrower->status ?? '',
                    'model' => $borrower->model ?? '',
                    'mobile_no' => $borrower_mobile,
                    'alternate_number1' => $borrower->alternate_number1 ?? '',
                    'date_of_birth' => $borrower->date_of_birth ?? '',
                    'marital_status' => $borrower->marital_status ?? '',
                    'spouse_name' => $borrower->spouses_name ?? '',
                    'fathers_name' => $borrower->father_name ?? '',
                    'gender' => $borrower->gender ?? '',
                    'email_id' => $borrower->email_id ?? '',
                    'pan_no' => $borrower->pan_no ?? '',
                    'aadhar_no' => $borrower->aadhar_no ?? '',
                    'driving_license_no' => $borrower->driving_license_no ?? '',
                    'borrower_cibil' => $borrower_cibil ?? 'NTC',
                    'coapplicant_cibil' => $coapplicant_cibil ?? 'NTC',
                    'guarantor_cibil' => $guarantor_cibil ?? 'NTC',
                    'loan_amount' => $this->helper_controller->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
                    'address' => $user_address_details ?? [],
                    'down_payment' => $down_payment ?? 0,
                    'loan_id' => $loan_id ?? '',
                    'created_on' => $borrower->created_on ?? '',
                    'dealer_id' => $borrower->dealer_primary_id ?? '',
                    'dealer_unq' => $borrower->dealer_unq ?? '',
                    'step_status' => $stepStatus,
                    'last_step' => $lastStep ? [
                        'application_step' => $lastStep['step'],
                        'details' => $lastStep['details'],
                        'status' => $lastStep['status'],
                    ] : null, // Adding last step
                    'external_identifier'    => match (true) {
                        !empty($externalIdentifier) => 'Green Raha',
                        $borrower->executive_id !== null => 'B2B',
                        default => null,
                    },
                    'action' => '' // You can specify actions if needed, currently left empty
                ];
            });
    
            // Filter by step status if provided
            if ($stepFilter !== null) {
                $borrowers = $borrowers->filter(function ($borrower) use ($stepFilter) {
                    return $borrower['step_status'] === 'step' . $stepFilter;
                });
            }
    
            // Convert collection to array for pagination
            $borrowers = $borrowers->values();
    
            // Paginate the filtered results
            $total = $borrowers->count();
            $borrowers = array_slice($borrowers->toArray(), ($page - 1) * $numberOfRecords, $numberOfRecords);
    
            // Prepare pagination links
            $paginatedData = [
                'current_page' => $page,
                'data' => $borrowers,
                'first_page_url' => url()->current() . '?page=1',
                'from' => ($page - 1) * $numberOfRecords + 1,
                'last_page' => (int)ceil($total / $numberOfRecords),
                'last_page_url' => url()->current() . '?page=' . (int)ceil($total / $numberOfRecords),
                'links' => [
                    [
                        'url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                        'label' => '&laquo; Previous',
                        'active' => false
                    ],
                    [
                        'url' => url()->current() . '?page=1',
                        'label' => '1',
                        'active' => $page == 1
                    ],
                    [
                        'url' => url()->current() . '?page=' . $page,
                        'label' => (string)$page,
                        'active' => true
                    ],
                    [
                        'url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
                        'label' => 'Next &raquo;',
                        'active' => false
                    ]
                ],
                'next_page_url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
                'path' => url()->current(),
                'per_page' => $numberOfRecords,
                'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                'to' => min($page * $numberOfRecords, $total),
                'total' => $total
            ];
    
            return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $paginatedData);
            
        } catch (Exception $e) {
            // Handle errors
            return $this->helper_controller->send_response('failed', "An error occurred while fetching loan records: " . $e->getMessage(), 422);
        }
    }
    
    public function get_borrower_details($borrower_id = null) {
        try {
            if (is_null($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
            }

            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }

            $borrower_exist = LenderBorrowerGroup::join('loan_basic_details', 'lender_borrower_groups.borrower_id', '=', 'loan_basic_details.id')
                ->where('lender_borrower_groups.lender_id', $lender_id)
                ->where('loan_basic_details.borrower_id', $borrower_id)
                ->select(['loan_basic_details.id', 'lender_borrower_groups.borrower_doc_download_left', 'lender_borrower_groups.agreement_download_left', 'lender_borrower_groups.cam_sheet_download_left']) // Fetch only required columns
                ->first();

            if (!$borrower_exist) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }

            $loan_basic_details = LoanBasicDetails::with([
                'dealer',
                'latestBorrowerKyc',
                'latestBorrowerAddress',
                'latestBorrowerOccupation',
                'latestCoapplicantKyc',
                'latestCoapplicantOccupation',
                'latestGuarantor',
                'latestGuarantorOccupation',
                'latestDocuments'
            ])->find($borrower_exist->id);

            if (!$loan_basic_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $dealer_details = $loan_basic_details->dealer;
            $product_details = $dealer_details 
                ? ProductModel::where('dealer_id', $dealer_details->id)->where('name', $loan_basic_details->model)->first() 
                : null;
            $interest_rate = isset($product_details) ? $product_details->interest : 0; 

            $loan_summary_details = LoanApplicationSummary::where('borrower_id', $loan_basic_details->id)->latest()->first();
            
            $iot_amount = $product_details->iot ?? 0;
            $second_year_insurance = ($loan_basic_details->loan_term > 15 && $loan_basic_details->loan_term <= 24) ? $product_details->insurance_2nd_year : 0;
            $final_loan_amount = $loan_basic_details->loan_amount + $iot_amount + $second_year_insurance;

            $result_array = [
                'loan_details' => [
                    'id'                => $loan_basic_details->id,
                    'borrower_id'       => $borrower_id,
                    'dealer_name'       => $dealer_details->name ?? '',
                    'model_name'        => $product_details->name ?? $loan_basic_details->model,
                    'product_type'      => $product_details->product_type ?? '',
                    'loan_amount'       => $final_loan_amount,
                    'roi'               => $interest_rate,
                    'tenure'            => $loan_basic_details->loan_term,
                    'emi'               => number_format($this->helper_controller->calculateFlatInterestEMI($loan_basic_details->loan_amount, $interest_rate, $loan_basic_details->loan_term), 2, '.', ''),
                    'iot_amount'        => $product_details->iot ?? 0,
                    'second_year_insurance' => $second_year_insurance,
                    'processing_fee'    => $product_details->processing_fee ?? 0,
                    'subvention_amount' => $product_details->subvention_amount ?? 0,
                    'fldg' => $product_details->fldg ?? 0,
                    'first_year_insurance' => $product_details->insurance_1st_year ?? 0,
                    'paid_amount' => 0,
                    'amount_due' => 0,
                    'status' => $loan_basic_details->status,
                    'created_at' => date('d-m-Y H:i:s', strtotime($loan_basic_details->created_at)) ?? '',
                    // 'download_left' => $borrower_exist->download_left ?? 0,
                    'borrower_doc_download_left' => $borrower_exist->borrower_doc_download_left ?? 0,
                    'agreement_download_left' => $borrower_exist->agreement_download_left ?? 0,
                    'cam_sheet_download_left' => $borrower_exist->cam_sheet_download_left ?? 0,
                ],
                'borrower_details'      => $this->helper_controller->transformBorrowerDetails($loan_basic_details->latestBorrowerKyc),
                'borrower_address'      => $this->helper_controller->transformBorrowerAddress($loan_basic_details->latestBorrowerAddress, $loan_basic_details->id),
                'borrower_occupation'   => $this->helper_controller->transformBorrowerOccupation($loan_basic_details->latestBorrowerOccupation),
                'co_applicant_details'  => $this->helper_controller->transformCoApplicantDetails($loan_basic_details->latestCoapplicantKyc),
                'co_applicant_address'  => $this->helper_controller->transformCoApplicantAddress($loan_basic_details->latestCoapplicantOccupation, $loan_basic_details->id),
                'guarantor_details'     => $this->helper_controller->transformGuarantorDetails($loan_basic_details->latestGuarantor),
                'guarantor_occupation'  => $this->helper_controller->transformGuarantorOccupation($loan_basic_details->latestGuarantorOccupation, $loan_basic_details->id),
                'credit_details'        => $this->helper_controller->transformCreditSummary($loan_basic_details->latestCreditSummary),
                'documents'             => $this->helper_controller->transformDocuments($loan_basic_details->latestDocuments),
                'iot_details'           => $this->helper_controller->get_borrower_iot_details($loan_basic_details->id),
                'disbursement_details'  => $this->helper_controller->get_borrower_disbursement_details($loan_basic_details->id),
            ];

            return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $result_array);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function raise_download_permission_request(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'message' => [
                    'nullable',
                    'regex:/^[a-zA-Z\s\-,.()]+$/', // Allows alphabets, spaces, hyphens, commas, full stops, and brackets
                ],
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }
            // $lender_id = $check_user->id;

            $borrower_exist = LenderBorrowerGroup::join('loan_basic_details', 'lender_borrower_groups.borrower_id', '=', 'loan_basic_details.id')
                ->where('lender_borrower_groups.lender_id', $lender_id)
                ->select(['loan_basic_details.id', 'lender_borrower_groups.download_left']) // Fetch only required columns
                ->first();

            if (!$borrower_exist) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }

            DB::beginTransaction();
            $request_details                        = new LendersDataExportRequest();
            $request_details->lender_id             = $lender_id;
            $request_details->borrower_id           = $request->borrower_id;
            $request_details->lenders_message       = $request->message ?? '';
            $request_details->status                = 'PENDING';
            $request_details->request_user_ip       = $this->helper_controller->get_user_ip();
            $request_details->requested_by          = auth()->id();
            $request_details->save();

            DB::commit();
            return $this->helper_controller->send_response('success', "Download request sent successfully", 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function lenders_download_request_list(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'nullable|exists:loan_basic_details,id',
                'start_date' => 'nullable|date|before_or_equal:today',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'status' => 'nullable|in:PENDING,APPROVED,REJECTED', // Assuming these are the statuses
                'page' => 'nullable|integer|min:1',
                'number_of_records' => 'nullable|integer|min:1',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }
            // $lender_id = $check_user->id;
            $query = LendersDataExportRequest::where('lender_id', $lender_id);

            if($request->filled('borrower_id')) {
                $query->where('borrower_id', $request->borrower_id);
            }
            if($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            
            if($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }
            
            if($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $page = $request->input('page', 1); // Default to page 1 if not provided
            $number_of_records = $request->input('number_of_records', 10); // Default to 10 records per page

            // Adjust the pagination query
            $request_list = $query->orderBy('id', 'desc')
                ->paginate($number_of_records, ['*'], 'page', $page);

            $response_data = $request_list->map(function ($item) {
                $borrower = LoanBasicDetails::find($item->borrower_id);
                return [
                    'borrower_id' => $borrower ? $borrower->borrower_id : null,
                    'lender_message' => $item->lenders_message,
                    'status' => $item->status,
                    'admin_remarks' => $item->admin_remarks,
                    'admin_user_name' => $item->modifiedBy ? $item->modifiedBy->name : null,
                    'created_at' => date('d-m-Y H:i:s', strtotime($item->created_at)),
                    'updated_at' => date('d-m-Y H:i:s', strtotime($item->updated_at)),
                ];
            });

            $data = [
                'data' => $response_data,
                'total' => $request_list->total(),
                'from' => $request_list->firstItem(),
                'to' => $request_list->lastItem(),
                'current_page' => $request_list->currentPage(),
                'last_page' => $request_list->lastPage(),
                'per_page' => $request_list->perPage(),
            ];

            return $this->helper_controller->send_response('success', "Request list fetched", 200, $data);

        } catch(Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function all_lenders_download_request_list_(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'lender_id' => 'nullable|exists:lenders,id',
                'borrower_id' => 'nullable|exists:loan_basic_details,id',
                'start_date' => 'nullable|date|before_or_equal:today',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'status' => 'nullable|in:PENDING,APPROVED,REJECTED', // Assuming these are the statuses
                'page' => 'nullable|integer|min:1',
                'number_of_records' => 'nullable|integer|min:1',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            $query = LendersDataExportRequest::query();

            if($request->filled('lender_id')) {
                $query->where('lender_id', $request->lender_id);
            }
            
            if($request->filled('borrower_id')) {
                $query->where('borrower_id', $request->borrower_id);
            }
            
            if($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            
            if($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }
            
            if($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $page = $request->input('page', 1); // Default to page 1 if not provided
            $number_of_records = $request->input('number_of_records', 10); // Default to 10 records per page

            // Adjust the pagination query
            $request_list = $query->orderBy('id', 'desc')
                ->paginate($number_of_records, ['*'], 'page', $page);

            $response_data = $request_list->map(function ($item) {
                $borrower = LoanBasicDetails::find($item->borrower_id);
                return [
                    'id' => $item->id,
                    'borrower_id' => $borrower ? $borrower->borrower_id : null,
                    'lender_unq' => $item->lender ? $item->lender->lender_unq : null,
                    'lender_name' => $item->lender ? $item->lender->name : null,
                    'lender_message' => $item->lenders_message,
                    'status' => $item->status,
                    'admin_remarks' => $item->admin_remarks,
                    'admin_user_name' => $item->modifiedBy ? $item->modifiedBy->name : null,
                    'created_at' => date('d-m-Y H:i:s', strtotime($item->created_at)),
                    'updated_at' => date('d-m-Y H:i:s', strtotime($item->updated_at)),
                ];
            });

            $data = [
                'data' => $response_data,
                'total' => $request_list->total(),
                'from' => $request_list->firstItem(),
                'to' => $request_list->lastItem(),
                'current_page' => $request_list->currentPage(),
                'last_page' => $request_list->lastPage(),
                'per_page' => $request_list->perPage(),
            ];

            return $this->helper_controller->send_response('success', "Request list fetched", 200, $data);

        } catch(Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }
    
    public function handle_lenders_download_request(Request $request, $id) {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:APPROVED,REJECTED',
                // 'additional_downloads' => 'nullable|integer|min:0',
                'borrower_doc_download' => 'nullable|integer|min:0',
                'agreement_download' => 'nullable|integer|min:0',
                'cam_sheet_download' => 'nullable|integer|min:0',
                'remarks' => [
                    'nullable',
                    'regex:/^[a-zA-Z\s\-,.()]+$/', // Allows alphabets, spaces, hyphens, commas, full stops, and brackets
                ],
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();
            $download_request = LendersDataExportRequest::find($id);
            if(!$download_request) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            $lender_id      = $download_request->lender_id;
            $borrower_id    = $download_request->borrower_id;
            
            if($download_request->status !== "PENDING") {
                return $this->helper_controller->send_response('failed', "Request already processed", 422);
            }

            if ($request->status === 'APPROVED') {
                // $additional_downloads   = $request->additional_downloads ?? 1;
                $borrower_doc_download  = $request->borrower_doc_download ?? 0;
                $agreement_download     = $request->agreement_download ?? 0;
                $cam_sheet_download     = $request->cam_sheet_download ?? 0;
            } else {
                $additional_downloads = null;
                $borrower_doc_download  = 0;
                $agreement_download     = 0;
                $cam_sheet_download     = 0;
            }
            $download_request->status               = $request->status;
            $download_request->additional_downloads = $additional_downloads ?? null;
            $download_request->borrower_doc_download = $borrower_doc_download;
            $download_request->agreement_download   = $agreement_download;
            $download_request->cam_sheet_download   = $cam_sheet_download;
            $download_request->modified_by          = auth()->user()->id;
            $download_request->admin_remarks        = $request->remarks ?? '';
            $download_request->response_user_ip     = $this->helper_controller->get_user_ip();
            $download_request->save();

            if ($request->status === 'APPROVED') {
                $lender_borrower_group = LenderBorrowerGroup::where('lender_id', $lender_id)
                                            ->where('borrower_id', $borrower_id)
                                            ->first();
                if (!$lender_borrower_group) {
                    return $this->helper_controller->send_response('failed', "Lender-Borrower group not found", 422);
                }
                // $lender_borrower_group->download_left += (int) $additional_downloads ?? 0;
                $lender_borrower_group->borrower_doc_download_left  += (int) $borrower_doc_download;
                $lender_borrower_group->agreement_download_left     += (int) $agreement_download;
                $lender_borrower_group->cam_sheet_download_left     += (int) $cam_sheet_download;
                $lender_borrower_group->save();
            }

            DB::commit();
            return $this->helper_controller->send_response('success', "Request processed successfully", 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }

    public function lender_doc_download(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|integer|exists:loan_basic_details,id',
                'doc_type' => 'required|in:borrower_doc,agreement,cam_sheet',
            ]);
        
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }
            // $lender_id = $check_user->id;

            DB::beginTransaction();
            $update_number_of_downloads = LenderBorrowerGroup::where('borrower_id', $request->borrower_id)->where('lender_id', $lender_id)->first();

            if(!$update_number_of_downloads) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            if($request->doc_type === 'borrower_doc') {
                $update_number_of_downloads->borrower_doc_download_left -= 1;
            }

            if($request->doc_type === 'agreement') {
                $update_number_of_downloads->agreement_download_left    -= 1;
            }

            if($request->doc_type === 'cam_sheet') {
                $update_number_of_downloads->cam_sheet_download_left    -= 1;
            }
            $update_number_of_downloads->save();
            DB::commit();

            return $this->helper_controller->send_response('success', "Downloaded successfully", 200);
        } catch(Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: ". $e->getMessage(), 422);

        }
    }

    public function get_lender_dashboard_details(Request $request) {
        try {
            // Default to current year and month if not provided
            date_default_timezone_set('Asia/Kolkata');
            if($request->filled('year')) {
                $year = $request->input('year', date('Y'));
            } else {
                $year = date('Y');
            }

            if($request->filled('month')) {
                $month = $request->input('month', date('F'));
            } else {
                $month = date('F');
            }
    
            // Validate input
            $request->validate([
                'month' => 'nullable|date_format:F',
                'year' => 'nullable|integer',
            ]);

            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }
            // $lender_id = $check_user->id;

            $borrower_ids = LenderBorrowerGroup::where('lender_id', $lender_id)->pluck('borrower_id');
    
            // Convert month name to numeric representation
            $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    
            $total_rejected = LenderBorrowerGroup::where('lender_id', $lender_id)
                                ->whereYear('created_at', $year)
                                ->whereMonth('created_at', $monthNumber)
                                ->where('status', 'REJECTED')
                                ->count();
            // Get all loan details for the specified month and year
            $loanDetailsQuery = LoanBasicDetails::whereYear('created_at', $year)
                                            ->whereMonth('created_at', $monthNumber)
                                            ->whereIn('id', $borrower_ids);

            $loanDetails = $loanDetailsQuery->get();
    
            // Initialize counters for steps
            $totalApplicationCount = 0;
            $totalDisbursedCount = 0;
    
            // Count steps based on the last JSON value
            foreach ($loanDetails as $loanDetail) {
                $totalApplicationCount++;
                if($loanDetail->status == 'DISBURSED') {
                    $totalDisbursedCount++;
                }
            }
    
            // Get total loan amount
            $totalLoanAmountQuery = LoanBasicDetails::whereYear('created_at', $year)
                                         ->whereMonth('created_at', $monthNumber)
                                         ->whereIn('id', $borrower_ids);

            $totalDisbursedLoanAmount = $totalLoanAmountQuery->where('status', 'DISBURSED')->sum('loan_amount');
    
            // Get daily data for the month
            $dailyDataQuery = LoanBasicDetails::select(DB::raw('EXTRACT(DAY FROM created_at) as day'),
                                            DB::raw('COUNT(*) as total'),
                                            DB::raw('SUM(loan_amount) as loan_amount'));

            $dailyData = $dailyDataQuery->whereYear('created_at', $year)
                                       ->whereMonth('created_at', $monthNumber)
                                       ->whereIn('id', $borrower_ids)
                                       ->groupBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                                       ->orderBy(DB::raw('EXTRACT(DAY FROM created_at)'))
                                       ->get()->toArray();
    
            // Format daily data to ensure days with no data default to 0
            $formattedDailyData = $this->formatDailyData($dailyData, $monthNumber, $year);
    
            // Count steps daywise
            $dailyStepCounts = [
                'disbursed' => array_fill(0, count($formattedDailyData['total']), 0),
            ];
    
            foreach ($loanDetails as $loanDetail) {
                $day = Carbon::parse($loanDetail->created_at)->day - 1;
                if($loanDetail->status == 'DISBURSED') {
                    $dailyStepCounts['disbursed'][$day]++;
                }
            }
    
            return response()->json([
                'total_application' => $totalApplicationCount,
                'total_rejected_application' => $total_rejected,
                'total_disbursed_loan_amount' => $totalDisbursedLoanAmount,
                'total_disbursed_application' => $totalDisbursedCount,
                'daywise_totalApplication' => $formattedDailyData['total'],
                'daywise_disbursed_application' => $dailyStepCounts['disbursed'],
            ], 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('success', "An unknown error occurred: ". $e->getMessage(), 422);
        }
    }
    
    /**
     * Format daily data to ensure days with no data default to 0.
     *
     * @param array $dailyData
     * @param int $monthNumber
     * @param int $year
     * @return array
     */

    private function formatDailyData(array $dailyData, int $month, int $year) {
        $daysInMonth = Carbon::createFromDate($year, $month)->daysInMonth;
        $formattedDailyData = [
            'total' => array_fill(0, $daysInMonth, 0),
            'loan_amount' => array_fill(0, $daysInMonth, 0.0),
        ];
    
        foreach ($dailyData as $data) {
            $day = $data['day'] - 1; // Convert 1-based day to 0-based index
            $formattedDailyData['total'][$day] = (int)$data['total'];
            $formattedDailyData['loan_amount'][$day] = (float)$data['loan_amount'];
        }
    
        return $formattedDailyData;
    }

    public function get_lender_yearly_count(Request $request) {
        try {
            // Retrieve year input, default to null if not provided
            $year = $request->input('year');
    
            // Validate input
            $request->validate([
                'year' => 'nullable|integer',
            ]);

            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }
            // $lender_id = $check_user->id;

            $borrower_ids = LenderBorrowerGroup::where('lender_id', $lender_id)->pluck('borrower_id');
    
            // Get all loan details for the specified year or all years if no year is provided
            $loanDetailsQuery = LoanBasicDetails::whereIn('id', $borrower_ids);

            if ($year) {
                $loanDetailsQuery->whereYear('created_at', $year);
            }
            $loanDetails = $loanDetailsQuery->get();
    
            // Initialize counters for steps and total applications
            $totalApplicationCount = 0;
            $totalDisbursedCount = 0;
    
            // Count steps based on the last JSON value
            foreach ($loanDetails as $loanDetail) {
                $totalApplicationCount++;

                if($loanDetail->status == 'DISBURSED') {
                    $totalDisbursedCount++;
                }
            }
    
            // Get total loan amount for the specified year or all years
            $totalLoanAmountQuery = LoanBasicDetails::whereIn('id', $borrower_ids);
            $total_rejected_query = LenderBorrowerGroup::where('lender_id', $lender_id)
                                        ->where('status', 'REJECTED');
            if ($year) {
                $totalLoanAmountQuery->whereYear('created_at', $year);
                $total_rejected_query->whereYear('created_at', $year);
            }
            
            $totalLoanAmount = $totalLoanAmountQuery->where('status', 'DISBURSED')->sum('loan_amount');
            $totalRejectedAppplications = $total_rejected_query->count();
            $total_in_loan_pipeline = $totalApplicationCount - $totalRejectedAppplications;
    
            return response()->json([
                'total_application' => $totalApplicationCount,
                'total_loan_amount' => $totalLoanAmount,
                'loan_pipeline' => $total_in_loan_pipeline,
                'rejected_application' => $totalRejectedAppplications,
                'approved_application' => $totalDisbursedCount,
                'disbursed_application' => $totalDisbursedCount,
            ], 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }

    // public function getFileTypes()
    // {
    //     return $this->helper_controller->send_response('success', "File types fetched", 200, $this->fileTypes);
    // }
    public function getFileTypes() {
        $arr = [
            [
                'doc_type'=>'borrower_doc_download_limit',
                'label'=>'Borrower Documents',
            ],
            [
                'doc_type'=>'agreement_download_limit',
                'label'=>'Loan Agreement',
            ],
            [
                'doc_type'=>'cam_sheet_download_limit',
                'label'=>'CAM sheet',
            ],
            [
                'doc_type'=>'vkyc_doc_download_limit',
                'label'=>'Video KYC Report',
            ],
            [
                'doc_type'=>'bureau_borrower_download_limit',
                'label'=>'Borrower Bureau Report',
            ],
            [
                'doc_type'=>'bureau_coapplicant_download_limit',
                'label'=>'Co-applicant Bureau Report',
            ],
            [
                'doc_type'=>'bureau_guarantor_download_limit',
                'label'=>'Guarantor Bureau Report',
            ],
        ];

        return $this->helper_controller->send_response('success', "File types fetched", 200, $arr);
    }

    public function getDownloadLimits(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|integer|exists:loan_basic_details,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }
            
            $update_number_of_downloads = LenderBorrowerGroup::where('borrower_id', $request->borrower_id)->where('lender_id', $lender_id)->first();
            if(!$update_number_of_downloads) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $lenderId = $lender_id; // Assuming the lender is logged in
            
            $borrowerId = $request->borrower_id;
            $limits = [];
            
            foreach ($this->fileTypes as $fileType) {
                // Sum of all export limits for this file type
                $totalExportLimit = LendersDataExportLimit::where('lender_id', $lenderId)
                ->where('borrower_id', $borrowerId)
                ->where('file_type', $fileType)
                ->sum('export_limit');
                
                // Count total downloads for this file type
                $totalExportsUsed = LendersDataExportLimit::where('lender_id', $lenderId)
                ->where('borrower_id', $borrowerId)
                ->where('file_type', $fileType)
                ->sum('exports_used'); // Each row represents a download
                
                // If no record exists, set default export limit as 2
                if ($totalExportLimit == 0) {
                    $totalExportLimit = 2;
                }
                
                // Calculate remaining downloads
                $remaining = max(0, $totalExportLimit - $totalExportsUsed);
                
                $limits[$fileType] = [
                    'export_limit' => $totalExportLimit,
                    'exports_used' => $totalExportsUsed,
                    'remaining' => $remaining,
                ];
            }
            return $this->helper_controller->send_response('success', "Download limits fetched", 200, $limits);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknwon error occurred: ". $e->getMessage(), 422);

        }
    }

    public function getDownloadLimits_admin(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|integer|exists:loan_basic_details,id',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            $update_number_of_downloads = LenderBorrowerGroup::where('borrower_id', $request->borrower_id)->first();
            if(!$update_number_of_downloads) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $lenderId = $update_number_of_downloads->lender_id; // Assuming the lender is logged in
            
            $borrowerId = $request->borrower_id;
            $limits = [];
            
            foreach ($this->fileTypes as $fileType) {
                // Sum of all export limits for this file type
                $totalExportLimit = LendersDataExportLimit::where('lender_id', $lenderId)
                ->where('borrower_id', $borrowerId)
                ->where('file_type', $fileType)
                ->sum('export_limit');
                
                // Count total downloads for this file type
                $totalExportsUsed = LendersDataExportLimit::where('lender_id', $lenderId)
                ->where('borrower_id', $borrowerId)
                ->where('file_type', $fileType)
                ->sum('exports_used'); // Each row represents a download
                
                // If no record exists, set default export limit as 2
                if ($totalExportLimit == 0) {
                    $totalExportLimit = 2;
                }
                
                // Calculate remaining downloads
                $remaining = max(0, $totalExportLimit - $totalExportsUsed);
                
                $limits[$fileType] = [
                    'export_limit' => $totalExportLimit,
                    'exports_used' => $totalExportsUsed,
                    'remaining' => $remaining,
                ];
            }
            return $this->helper_controller->send_response('success', "Download limits fetched", 200, $limits);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknwon error occurred: ". $e->getMessage(), 422);

        }
    }

    public function trackDownload(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|integer|exists:loan_basic_details,id',
                'file_type'   => ['required', 'string', Rule::in($this->fileTypes)],
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $logged_in_user = auth()->user()->id;
            $check_user = Lenders::where('lender_auth_id', $logged_in_user)->where('is_active', true)->first();
            if (!$check_user) {
                $lender_executive = LenderDirectors::where('auth_id', $logged_in_user)->where('status', 'active')->first();
                if(!$lender_executive) {
                    return $this->helper_controller->send_response('failed', "You are not authorized to perform this action", 422);
                } else {
                    $lender_id = $lender_executive->lender_id;
                }
            } else {
                $lender_id = $check_user->id;
            }
            
            $update_number_of_downloads = LenderBorrowerGroup::where('borrower_id', $request->borrower_id)->where('lender_id', $lender_id)->first();
            if(!$update_number_of_downloads) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }

            $borrowerId = $request->borrower_id;
            $fileType = $request->file_type;
            $lenderId = $lender_id;

            // Get total export limit
            $totalExportLimit = LendersDataExportLimit::where('lender_id', $lenderId)
                ->where('borrower_id', $borrowerId)
                ->where('file_type', $fileType)
                ->sum('export_limit');

            // Get total downloads used
            $totalExportsUsed = LendersDataExportLimit::where('lender_id', $lenderId)
                ->where('borrower_id', $borrowerId)
                ->where('file_type', $fileType)
                ->sum('exports_used'); // 

            // If no record exists, assume default export limit of 2
            if ($totalExportLimit == 0) {
                $totalExportLimit = 2;
            }

            // Check if user has remaining downloads
            if ($totalExportsUsed >= $totalExportLimit) {
                return $this->helper_controller->send_response('failed', "Download limit exceeded for this file type", 422);
            }

            // Track new download
            LendersDataExportLimit::create([
                'lender_id' => $lenderId,
                'borrower_id' => $borrowerId,
                'file_type' => $fileType,
                'export_limit' => ($totalExportsUsed == 0) ? 2 : 0, // First time = 2, next = 0
                'exports_used' => 1, // Correctly tracking usage
                'export_by' => $lenderId,
                'exported_at' => date('Y-m-d H:i:s'),
                'exporter_ip' => $this->helper_controller->get_user_ip(),
            ]);

            return $this->helper_controller->send_response('success', "Download recorded successfully", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknwon error occurred: ". $e->getMessage(), 422);

        }
    }

    public function approveDownloadLimitIncrease(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:APPROVED,REJECTED',
                'remarks' => [
                    'nullable',
                    'regex:/^[a-zA-Z\s\-,.()]+$/', // Allows alphabets, spaces, hyphens, commas, full stops, and brackets
                ],
                'limits' => 'nullable|array',
                'limits.*' => 'nullable|integer|min:0', // Dynamic validation for all file types
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();
            $download_request = LendersDataExportRequest::find($id);
            if(!$download_request) {
                return $this->helper_controller->send_response('failed', "Invalid request", 422);
            }
            $lender_id      = $download_request->lender_id;
            if($download_request->status !== "PENDING") {
                return $this->helper_controller->send_response('failed', "Request already processed", 422);
            }

            $logged_in_user_id  = auth()->user()->id;
            $borrowerId         = $download_request->borrower_id;
            $ipAddress          = $this->helper_controller->get_user_ip();
            $limits             = $request->limits; // Dynamic file types

            $download_request->status               = $request->status;
            $download_request->modified_by          = $logged_in_user_id;
            $download_request->admin_remarks        = $request->remarks ?? '';
            $download_request->response_user_ip     = $ipAddress;
            $download_request->save();

            if($request->status === 'REJECTED') {
                DB::commit();
                return $this->helper_controller->send_response('success', "Request processed successfully", 200);
            }

            foreach ($limits as $fileType => $increaseValue) {
                if ($increaseValue < 1) {
                    continue; // Ignore invalid or zero values
                }

                // Create a new record ONLY with the increase value
                LendersDataExportLimit::create([
                    'lender_id' => $lender_id,
                    'borrower_id' => $borrowerId,
                    'file_type' => $fileType,
                    'export_limit' => $increaseValue, // Only the given increase value
                    'exports_used' => 0,
                    'limit_increased_by' => $logged_in_user_id,
                    'limit_increased_at' => date('Y-m-d H:i:s'),
                    'increaser_ip' => $ipAddress,
                ]);
            }

            DB::commit();
            return $this->helper_controller->send_response('success', "Request processed successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknwon error occurred: ". $e->getMessage(), 422);
        }
    }
}
