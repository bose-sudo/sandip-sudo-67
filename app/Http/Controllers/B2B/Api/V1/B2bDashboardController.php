<?php

namespace App\Http\Controllers\B2B\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\LoanApplicationSummary;
use App\Models\LoanBasicDetails;
use App\Models\User;
use App\Models\Dealer;
use App\Models\ProductModel;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class B2bDashboardController extends Controller
{
    private $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    // public function get_borrower_list(Request $request) {
    //     date_default_timezone_set('Asia/Kolkata');
    //     try {
    //         $executive_id = $request->user()->id;
    //         $startDate = $request->get('start_date');
    //         $endDate = $request->get('end_date');
    //         $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
    //         $page = $request->input('page', 1); // Default to page 1
    //         $stepFilter = $request->input('step'); // Step filter, might be null

    //         // Validation (optional, can be expanded)
    //         $validator = Validator::make($request->all(), [
    //             'page' => 'integer|min:1',
    //             'number_of_records' => 'integer|min:1',
    //             'start_date' => 'date|nullable',
    //             'end_date' => 'date|nullable|after_or_equal:start_date',
    //             'step' => 'integer|min:1|nullable',
    //             'borrower_name' => 'string|regex:/^[a-zA-Z\s]+$/|nullable', // Only characters and spaces
    //             'borrower_id' => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable', // Alphanumeric and hyphens
    //             'mobile_no' => 'digits:10|nullable',
    //             'pan_no' => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|nullable',
    //             'aadhar_no' => 'string|min:4|max:12|nullable',
    //             'dob' => 'date|nullable',
    //             'application_date' => 'date|nullable'
    //         ]);
    
    //         // Check if validation fails
    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
    //         }
    
    //         // $query = LoanBasicDetails::query()
    //             // ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
    //             // ->select(
    //             //     'borrower_kycs.name as borrower_name',
    //             //     'loan_basic_details.borrower_id as borrower_unq',
    //             //     'loan_basic_details.user_id as user_primary_key',
    //             //     'borrower_kycs.mobile as mobile_no',
    //             //     'borrower_kycs.dob_date as date_of_birth',
    //             //     'borrower_kycs.marital_status',
    //             //     'borrower_kycs.husband_name as spouses_name',
    //             //     'borrower_kycs.father_name',
    //             //     'borrower_kycs.gender',
    //             //     'borrower_kycs.email',
    //             //     'borrower_kycs.pan as pan_no',
    //             //     'borrower_kycs.aadhar as aadhar_no',
    //             //     'borrower_kycs.driving_license_no',
    //             //     'borrower_kycs.alternate_number1',
    //             //     'loan_basic_details.id',
    //             //     'loan_basic_details.loan_amount',
    //             //     'loan_basic_details.model',
    //             //     'loan_basic_details.loan_term',
    //             //     'loan_basic_details.loan_application_steps',
    //             //     'loan_basic_details.created_at as created_on',
    //             //     'loan_basic_details.user_id',
    //             // );
    //             // ->where('executive_id', $executive_id);
    //         $query = User::query()
    //             ->leftJoin('loan_basic_details' , 'users.id', '=', 'loan_basic_details.user_id')
    //             ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
    //             ->leftJoin('product_models', 'loan_basic_details.model_id', '=', 'product_models.model_unq')
    //             ->leftJoin('loan_application_summaries', 'loan_basic_details.id', '=', 'loan_application_summaries.borrower_id')
    //             ->select(
    //                 'users.name as user_name',
    //                 'users.mobile as user_mobile',
    //                 'users.email as user_email',
    //                 'users.id as user_primary_id',
    //                 'borrower_kycs.name as borrower_name',
    //                 'loan_basic_details.borrower_id as borrower_unq',
    //                 'loan_basic_details.user_id as user_primary_key',
    //                 'borrower_kycs.mobile as mobile_no',
    //                 'borrower_kycs.dob_date as date_of_birth',
    //                 'borrower_kycs.marital_status',
    //                 'borrower_kycs.husband_name as spouses_name',
    //                 'borrower_kycs.father_name',
    //                 'borrower_kycs.gender',
    //                 'borrower_kycs.email',
    //                 'borrower_kycs.pan as pan_no',
    //                 'borrower_kycs.aadhar as aadhar_no',
    //                 'borrower_kycs.driving_license_no',
    //                 'borrower_kycs.alternate_number1',
    //                 'loan_basic_details.id',
    //                 'loan_basic_details.loan_amount',
    //                 'loan_basic_details.model',
    //                 'loan_basic_details.loan_term',
    //                 'loan_basic_details.loan_application_steps',
    //                 'loan_basic_details.created_at as created_on',
    //                 'users.created_at as user_created_on',
    //                 'loan_basic_details.user_id',
    //                 'loan_basic_details.down_payment',
    //                 'loan_application_summaries.cibil_score',
    //                 'product_models.iot',
    //             )->where('users.executive_id', $executive_id)->orWhere('loan_basic_details.executive_id', $executive_id);

    
    //         // Apply date filters if provided

    //         if ($startDate && !empty($startDate)) {
    //             $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
    //             $query->where('users.created_at', '>=', $startDate);
    //         }
            
    //         // Filter by end date (end of the day in Asia/Kolkata timezone)
    //         if ($endDate && !empty($endDate)) {
    //             $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
    //             $query->where('users.created_at', '<=', $endDate);
    //         }

    //         if(empty($startDate) && empty($endDate)) {
    //             $query->whereYear('users.created_at', '=', date('Y'));
    //         }

    //         // Apply additional filters
    //         if ($request->has('borrower_name') && !empty($request->input('borrower_name'))) {
    //             $query->where('borrower_kycs.name', 'like', '%' . $request->input('borrower_name') . '%');
    //         }
    //         if ($request->has('borrower_id') && !empty($request->input('borrower_id'))) {
    //             $query->where('loan_basic_details.borrower_id', 'like', '%' . $request->input('borrower_id') . '%');
    //         }
    //         if ($request->has('mobile_no') && !empty($request->input('mobile_no'))) {
    //             $query->where('borrower_kycs.mobile', 'like', '%' . $request->input('mobile_no') . '%');
    //         }
    //         if ($request->has('pan_no') && !empty($request->input('pan_no'))) {
    //             $query->where('borrower_kycs.pan', 'like', '%' . $request->input('pan_no') . '%');
    //         }
    //         if ($request->has('aadhar_no') && !empty($request->input('aadhar_no'))) {
    //             $query->where('borrower_kycs.aadhar', 'like', '%' . $request->input('aadhar_no') . '%');
    //         }
    //         if ($request->has('dob') && !empty($request->input('dob'))) {
    //             $query->whereDate('borrower_kycs.dob_date', $request->input('dob'));
    //         }
    //         if ($request->has('application_date') && !empty($request->input('application_date'))) {
    //             $query->whereDate('loan_basic_details.created_at', $request->input('application_date'));
    //         }

    //         $year = $request->input('year', date('Y'));
    //         $month = $request->input('month', date('F'));
            
    //         if(isset($request->year)) {
    //             $year = $request->input('year', date('Y'));;
    //             $query->whereYear('loan_basic_details.created_at', $year);
    //         }
            
    //         if(isset($request->month)) {
    //             $year = $request->input('year', date('Y'));
    //             $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    //             $query->whereMonth('loan_basic_details.created_at', $monthNumber);
    //         }
    
    //         // Fetch results before pagination
    //         $borrowers = $query->orderBy('users.created_at', 'desc')->get();

    //         // Transform the result to replace null or empty values and include step status
    //         $borrowers = $borrowers->map(function ($borrower) {
    //             $stepStatus = '';
    //             $lastStep = null;
    //             if ($borrower->loan_application_steps) {
    //                 $steps = json_decode($borrower->loan_application_steps, true);
    //                 if (is_array($steps) && !empty($steps)) {
    //                     $lastStep = end($steps);
    //                     if (isset($lastStep['step'])) {
    //                         $stepValue = (int)$lastStep['step'];
    //                         if ($stepValue < 8) {
    //                             $stepStatus = 'step1';
    //                         } elseif ($stepValue >= 8 && $stepValue < 26) {
    //                             $stepStatus = 'step2';
    //                         } elseif ($stepValue >= 26 && $stepValue < 27) {
    //                             $stepStatus = 'step3';
    //                         } elseif ($stepValue >= 27) {
    //                             $stepStatus = 'step4';
    //                         }
    //                     }
    //                 }
    //             }

    //             $borrower_user_id = $borrower->user_primary_key;
    //             if($borrower_user_id == '') {
    //                 $borrower_user_id = $borrower->user_primary_id;
    //             }
    //             $user_email = $borrower->user_email ?? '';

    //             // $credit_summary = LoanApplicationSummary::where('borrower_id', $borrower->id)->first();
    //             // $borrower_cibil = $credit_summary->cibil_score ?? 'NTC';
    //             // if($borrower_cibil == 0) {
    //             //     $borrower_cibil = 'NTC';
    //             // }

    //             // $borrower_cibil = strval($borrower_cibil);

    //             $user_address_details = null; //$this->helper_controller->user_address_details($borrower->id, "BORROWER");

    //             if(!empty($borrower->mobile_no)) {
    //                 $borrower_mobile = $borrower->mobile_no;
    //             } elseif(!empty($borrower->user_mobile)) {
    //                 $borrower_mobile = $borrower->user_mobile;
    //             } else {
    //                 $borrower_mobile = '';
    //             }

    //             if(!empty($borrower->borrower_name)) {
    //                 $borrower_name = $borrower->borrower_name;
    //             } elseif(!empty($borrower->user_name)) {
    //                 $borrower_name = $borrower->user_name;
    //             } else {
    //                 $borrower_name = '';
    //             }
    //             return [
    //                 'id' => $borrower->id ?? null,
    //                 'user_id' => $borrower_user_id ?? null,
    //                 'borrower_name' => $borrower_name ?? '',
    //                 'borrower_id' => $borrower->borrower_unq ?? null,
    //                 'model' => $borrower->model ?? '',
    //                 'mobile_no' => $borrower_mobile ?? '',
    //                 'alternate_number1' => $borrower->alternate_number1 ?? '',
    //                 'email' => isset($borrower->email) ? $borrower->email : $user_email,
    //                 'address' => $user_address_details ?? null,
    //                 'loan_id' => $loan_id ?? '',
    //                 'created_on' => isset($borrower->created_on) ? $borrower->created_on : $borrower->user_created_on,
    //                 'step_status' => $lastStep ? $lastStep['details'] : $stepStatus,
    //                 'down_payment' => $borrower->down_payment,
    //                 'loan_amount' => $borrower->loan_amount + $borrower->iot,
    //                 'borrower_cibil' => $borrower->cibil_score,
    //                 'last_step' => $lastStep ? [
    //                     'application_step' => $lastStep['step'],
    //                     'details' => $lastStep['details'],
    //                     'status' => $lastStep['status'],
    //                 ] : null, // Adding last step
    //                 'action' => '' // You can specify actions if needed, currently left empty
    //             ];
    //         });
    
    //         // Filter by step status if provided
    //         if ($stepFilter !== null) {
    //             $borrowers = $borrowers->filter(function ($borrower) use ($stepFilter) {
    //                 return $borrower['step_status'] === 'step' . $stepFilter;
    //             });
    //         }
    
    //         // Convert collection to array for pagination
    //         $borrowers = $borrowers->values();
    
    //         // Paginate the filtered results
    //         $total = $borrowers->count();
    //         $borrowers = array_slice($borrowers->toArray(), ($page - 1) * $numberOfRecords, $numberOfRecords);
    
    //         // Prepare pagination links
    //         $paginatedData = [
    //             'current_page' => $page,
    //             'data' => $borrowers,
    //             'first_page_url' => url()->current() . '?page=1',
    //             'from' => ($page - 1) * $numberOfRecords + 1,
    //             'last_page' => (int)ceil($total / $numberOfRecords),
    //             'last_page_url' => url()->current() . '?page=' . (int)ceil($total / $numberOfRecords),
    //             'links' => [
    //                 [
    //                     'url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
    //                     'label' => '&laquo; Previous',
    //                     'active' => false
    //                 ],
    //                 [
    //                     'url' => url()->current() . '?page=1',
    //                     'label' => '1',
    //                     'active' => $page == 1
    //                 ],
    //                 [
    //                     'url' => url()->current() . '?page=' . $page,
    //                     'label' => (string)$page,
    //                     'active' => true
    //                 ],
    //                 [
    //                     'url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
    //                     'label' => 'Next &raquo;',
    //                     'active' => false
    //                 ]
    //             ],
    //             'next_page_url' => $page < (int)ceil($total / $numberOfRecords) ? url()->current() . '?page=' . ($page + 1) : null,
    //             'path' => url()->current(),
    //             'per_page' => $numberOfRecords,
    //             'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
    //             'to' => min($page * $numberOfRecords, $total),
    //             'total' => $total
    //         ];
    
    //         return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $paginatedData);
    
    //     } catch (Exception $e) {
    //         // Handle errors
    //         return response()->json([
    //             'error' => 'An error occurred while fetching loan records.',
    //             'message' => $e->getMessage()
    //         ], 400);
    //     }
    // }

    public function get_borrower_list(Request $request) {
        date_default_timezone_set('Asia/Kolkata');
        try {
            $executive_id = $request->user()->id;
            $startDate    = $request->get('start_date');
            $endDate      = $request->get('end_date');
            $numberOfRecords = $request->input('number_of_records', 10);
            $page         = $request->input('page', 1);
            $stepFilter   = $request->input('step');

            $validator = Validator::make($request->all(), [
                'page'             => 'integer|min:1',
                'number_of_records'=> 'integer|min:1',
                'start_date'       => 'date|nullable',
                'end_date'         => 'date|nullable|after_or_equal:start_date',
                'step'             => 'integer|min:1|nullable',
                'borrower_name'    => 'string|regex:/^[a-zA-Z\s]+$/|nullable',
                'borrower_id'      => 'string|regex:/^[a-zA-Z0-9\-]+$/|nullable',
                'mobile_no'        => 'digits:10|nullable',
                'pan_no'           => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/|nullable',
                'aadhar_no'        => 'string|min:4|max:12|nullable',
                'status'            => 'nullable|string|in:NEW,APPROVED,REJECTED,HOLD,CLOSED,DISBURSED,CANCELLED',
                'dob'              => 'date|nullable',
                'application_date' => 'date|nullable',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
            }

            // ---------------------------------------------------------------
            // FIX 1: Wrap the executive_id OR condition in a grouped where()
            // so that every subsequent filter is AND'd on top of it, not OR'd.
            // ---------------------------------------------------------------
            $query = User::query()
                ->leftJoin('loan_basic_details',        'users.id',              '=', 'loan_basic_details.user_id')
                ->leftJoin('borrower_kycs',             'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
                ->leftJoin('product_models',            'loan_basic_details.model_id', '=', 'product_models.model_unq')
                ->leftJoin('loan_application_summaries','loan_basic_details.id', '=', 'loan_application_summaries.borrower_id')
                ->select(
                    'users.name as user_name',
                    'users.mobile as user_mobile',
                    'users.email as user_email',
                    'users.id as user_primary_id',
                    'users.is_active',
                    'borrower_kycs.name as borrower_name',
                    'loan_basic_details.borrower_id as borrower_unq',
                    'loan_basic_details.user_id as user_primary_key',
                    'borrower_kycs.mobile as mobile_no',
                    'borrower_kycs.dob_date as date_of_birth',
                    'borrower_kycs.marital_status',
                    'borrower_kycs.husband_name as spouses_name',
                    'borrower_kycs.father_name',
                    'borrower_kycs.gender',
                    'borrower_kycs.email',
                    'borrower_kycs.pan as pan_no',
                    'borrower_kycs.aadhar as aadhar_no',
                    'borrower_kycs.driving_license_no',
                    'borrower_kycs.alternate_number1',
                    'loan_basic_details.id',
                    'loan_basic_details.loan_amount',
                    'loan_basic_details.model',
                    'loan_basic_details.loan_term',
                    'loan_basic_details.loan_application_steps',
                    'loan_basic_details.created_at as created_on',
                    'users.created_at as user_created_on',
                    'loan_basic_details.user_id',
                    'loan_basic_details.down_payment',
                    'loan_application_summaries.cibil_score',
                    'product_models.iot',
                )
                ->where(function ($q) use ($executive_id) {
                    $q->where('users.executive_id', $executive_id)
                      ->orWhere('loan_basic_details.executive_id', $executive_id);
                });

            // ---------------------------------------------------------------
            // FIX 2: Date filters — based on users.created_at as stated.
            // All ->where() calls below are now safely AND'd.
            // ---------------------------------------------------------------
            if (!empty($startDate)) {
                $start = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('users.created_at', '>=', $start);
            }

            if (!empty($endDate)) {
                $end = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('users.created_at', '<=', $end);
            }

            if (empty($startDate) && empty($endDate)) {
                $query->whereYear('users.created_at', '=', date('Y'));
            }

            // application_date filters on loan_basic_details.created_at
            if ($request->filled('application_date')) {
                $query->whereDate('loan_basic_details.created_at', $request->input('application_date'));
            }

            if ($request->filled('status')) {
                $query->where('loan_basic_details.status', $request->status);
            }

            // Other search filters
            if ($request->filled('borrower_name')) {
                $name = $request->input('borrower_name');
                $query->where(function ($q) use ($name) {
                    $q->where('borrower_kycs.name', 'ilike', '%' . $name . '%')
                      ->orWhere('users.name', 'ilike', '%' . $name . '%');
                });
            }

            // ---------------------------------------------------------------
            // FIX 3: borrower_id — use loan_basic_details.id if purely numeric,
            // otherwise use loan_basic_details.borrower_id.
            // ---------------------------------------------------------------
            if ($request->filled('borrower_id')) {
                $borrowerIdInput = $request->input('borrower_id');
                if (ctype_digit($borrowerIdInput)) {
                    $query->where('loan_basic_details.id', $borrowerIdInput);
                } else {
                    $query->where('loan_basic_details.borrower_id', 'like', '%' . $borrowerIdInput . '%');
                }
            }

            if ($request->filled('mobile_no')) {
                $query->where('borrower_kycs.mobile', 'like', '%' . $request->input('mobile_no') . '%');
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

            // Year / month filters
            if ($request->filled('year')) {
                $query->whereYear('loan_basic_details.created_at', $request->input('year'));
            }

            if ($request->filled('month')) {
                $year        = $request->input('year', date('Y'));
                $month       = $request->input('month', date('F'));
                $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
                $query->whereMonth('loan_basic_details.created_at', $monthNumber);
            }

            $borrowers = $query->orderBy('users.created_at', 'desc')->get();

            $borrowers = $borrowers->map(function ($borrower) {
                $stepStatus = '';
                $lastStep   = null;

                if ($borrower->loan_application_steps) {
                    $steps = json_decode($borrower->loan_application_steps, true);
                    if (is_array($steps) && !empty($steps)) {
                        $lastStep   = end($steps);
                        if (isset($lastStep['step'])) {
                            $stepValue = (int) $lastStep['step'];
                            if ($stepValue < 8) {
                                $stepStatus = 'step1';
                            } elseif ($stepValue < 26) {
                                $stepStatus = 'step2';
                            } elseif ($stepValue < 27) {
                                $stepStatus = 'step3';
                            } else {
                                $stepStatus = 'step4';
                            }
                        }
                    }
                }

                $borrower_user_id = !empty($borrower->user_primary_key)
                    ? $borrower->user_primary_key
                    : $borrower->user_primary_id;

                $borrower_mobile = !empty($borrower->mobile_no)
                    ? $borrower->mobile_no
                    : ($borrower->user_mobile ?? '');

                $borrower_name = !empty($borrower->borrower_name)
                    ? $borrower->borrower_name
                    : ($borrower->user_name ?? '');

                return [
                    'id'               => $borrower->id ?? null,
                    'is_active'        => $borrower->is_active ?? false,
                    'user_id'          => $borrower_user_id ?? null,
                    'borrower_name'    => $borrower_name,
                    'borrower_id'      => $borrower->borrower_unq ?? null,
                    'model'            => $borrower->model ?? '',
                    'mobile_no'        => $borrower_mobile,
                    'alternate_number1'=> $borrower->alternate_number1 ?? '',
                    'email'            => $borrower->email ?? $borrower->user_email ?? '',
                    'address'          => null,
                    'loan_id'          => $borrower->id ?? '',  // FIX 4: was undefined $loan_id
                    'created_on'       => $borrower->created_on ?? $borrower->user_created_on,
                    'step_status'      => $lastStep ? $lastStep['details'] : $stepStatus,
                    'down_payment'     => $borrower->down_payment,
                    'loan_amount'      => ($borrower->loan_amount ?? 0) + ($borrower->iot ?? 0),
                    'borrower_cibil'   => $borrower->cibil_score,
                    'last_step'        => $lastStep ? [
                        'application_step' => $lastStep['step'],
                        'details'          => $lastStep['details'],
                        'status'           => $lastStep['status'],
                    ] : null,
                    'action'           => '',
                ];
            });

            if ($stepFilter !== null) {
                $borrowers = $borrowers->filter(function ($borrower) use ($stepFilter) {
                    return $borrower['step_status'] === 'step' . $stepFilter;
                });
            }

            $borrowers = $borrowers->values();
            $total     = $borrowers->count();
            $borrowers = array_slice($borrowers->toArray(), ($page - 1) * $numberOfRecords, $numberOfRecords);

            $lastPage = (int) ceil($total / $numberOfRecords);

            $paginatedData = [
                'current_page'  => $page,
                'data'          => $borrowers,
                'first_page_url'=> url()->current() . '?page=1',
                'from'          => ($page - 1) * $numberOfRecords + 1,
                'last_page'     => $lastPage,
                'last_page_url' => url()->current() . '?page=' . $lastPage,
                'links'         => [
                    ['url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null, 'label' => '&laquo; Previous', 'active' => false],
                    ['url' => url()->current() . '?page=1',    'label' => '1',           'active' => $page == 1],
                    ['url' => url()->current() . '?page=' . $page, 'label' => (string) $page, 'active' => true],
                    ['url' => $page < $lastPage ? url()->current() . '?page=' . ($page + 1) : null, 'label' => 'Next &raquo;', 'active' => false],
                ],
                'next_page_url' => $page < $lastPage ? url()->current() . '?page=' . ($page + 1) : null,
                'path'          => url()->current(),
                'per_page'      => $numberOfRecords,
                'prev_page_url' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                'to'            => min($page * $numberOfRecords, $total),
                'total'         => $total,
            ];

            return $this->helper_controller->send_response('success', "Borrower details fetched", 200, $paginatedData);

        } catch (Exception $e) {
            return response()->json([
                'status'   => 'failed',
                'message' => "An error occurred while fetching loan records: ". $e->getMessage(),
            ], 422);
        }
    }

    private function calculateNetLoanAmount($loan_amount, $loan_term) {
        $iot_amount = 3000;
        $second_year_insurance = ($loan_term > 15 && $loan_term <= 24) ? 6000 : 0;
        return $loan_amount + $iot_amount + $second_year_insurance;
    }

    public function getDashboardDetails(Request $request) {
        try {
            $executive_id = $request->user()->id;
            // Default to current year and month if not provided
            $year = $request->input('year', date('Y'));
            $month = $request->input('month', date('F'));
    
            // Validate input
            $request->validate([
                'month' => 'nullable|date_format:F',
                'year' => 'nullable|integer',
            ]);
    
            // Convert month name to numeric representation
            $monthNumber = Carbon::parse("{$year}-{$month}-01")->month;
    
            // Get all loan details for the specified month and year
            $loanDetails = LoanBasicDetails::whereYear('created_at', $year)
                                            ->whereMonth('created_at', $monthNumber)
                                            ->where('executive_id', $executive_id);

            $total_application = $loanDetails->count();
            $approved_application = $loanDetails->where('status', 'APPROVED')->count();
            $rejected_application = $loanDetails->where('status', 'REJECTED')->count();
            $disbursed_application = $loanDetails->where('status', 'ACTIVE')->count();
            $totalLoanAmount = LoanBasicDetails::whereYear('created_at', $year)
                                    ->whereMonth('created_at', $monthNumber)
                                    ->where('executive_id', $executive_id)
                                    ->sum('loan_amount');
            $resultData = [
                'total_application' => $total_application ?? 0,
                'approved_application' => $approved_application ?? 0,
                'rejected_application' => $rejected_application ?? 0,
                'disbursed_application' => $disbursed_application ?? 0,
                'total_loan_amount' => intval($totalLoanAmount) ?? 0,
            ];
            return $this->helper_controller->send_response('success', "Data fetched successfully", 200, $resultData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function get_loan_list(Request $request) {
        date_default_timezone_set('Asia/Kolkata');
        try {
            $executive_id = $request->user()->id;

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
                'application_date' => 'date|nullable'
            ]);
    
            // Check if validation fails
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 400);
            }

            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $numberOfRecords = $request->input('number_of_records', 10); // Default to 10 records per page
            $page = $request->input('page', 1); // Default to page 1
            $stepFilter = $request->input('step'); // Step filter, might be null
            $page = is_numeric($page) ? (int)$page : 1;
    
            $query = LoanBasicDetails::query()
                ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
                ->select(
                    'borrower_kycs.name as borrower_name',
                    'loan_basic_details.borrower_id as borrower_unq',
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
                    'loan_basic_details.id',
                    'loan_basic_details.loan_amount',
                    'loan_basic_details.model',
                    'loan_basic_details.loan_term',
                    'loan_basic_details.loan_application_steps',
                    'loan_basic_details.created_at as created_on',
                    'loan_basic_details.user_id',
                )
                ->where('loan_basic_details.executive_id', $executive_id)
                ->where('loan_basic_details.status', 'ACTIVE');
    
            // Apply date filters if provided

            if ($startDate && !empty($startDate)) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('loan_basic_details.created_at', '>=', $startDate);
            }
            
            // Filter by end date (end of the day in Asia/Kolkata timezone)
            if ($endDate && !empty($endDate)) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('loan_basic_details.created_at', '<=', $endDate);
            }

            // Apply additional filters
            if ($request->has('borrower_name') && !empty($request->input('borrower_name'))) {
                $query->where('borrower_kycs.name', 'like', '%' . $request->input('borrower_name') . '%');
            }
            if ($request->has('borrower_id') && !empty($request->input('borrower_id'))) {
                $query->where('loan_basic_details.borrower_id', 'like', '%' . $request->input('borrower_id') . '%');
            }
            if ($request->has('mobile_no') && !empty($request->input('mobile_no'))) {
                $query->where('borrower_kycs.mobile', 'like', '%' . $request->input('mobile_no') . '%');
            }
            if ($request->has('pan_no') && !empty($request->input('pan_no'))) {
                $query->where('borrower_kycs.pan', 'like', '%' . $request->input('pan_no') . '%');
            }
            if ($request->has('aadhar_no') && !empty($request->input('aadhar_no'))) {
                $query->where('borrower_kycs.aadhar', 'like', '%' . $request->input('aadhar_no') . '%');
            }
            if ($request->has('dob') && !empty($request->input('dob'))) {
                $query->whereDate('borrower_kycs.dob_date', $request->input('dob'));
            }
            if ($request->has('application_date') && !empty($request->input('application_date'))) {
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
                $userDetails = User::find($borrower_user_id);
                $user_phone_number = $userDetails->mobile ?? '';
                $user_name = $userDetails->name ?? '';

                $credit_summary = LoanApplicationSummary::where('borrower_id', $borrower->id)->first();
                $borrower_cibil = $credit_summary->cibil_score ?? 'NTC';
                if($borrower_cibil == 0) {
                    $borrower_cibil = 'NTC';
                }
                $borrower_cibil = strval($borrower_cibil);

                $user_address_details = null; //$this->helper_controller->user_address_details($borrower->id, "BORROWER");

                if(!empty($borrower->mobile_no)) {
                    $borrower_mobile = $borrower->mobile_no;
                } elseif(!empty($user_phone_number)) {
                    $borrower_mobile = $user_phone_number;
                } else {
                    $borrower_mobile = '';
                }

                if(!empty($borrower->borrower_name)) {
                    $borrower_name = $borrower->borrower_name;
                } elseif(!empty($user_name)) {
                    $borrower_name = $user_name;
                } else {
                    $borrower_name = '';
                }
                return [
                    'id' => $borrower->id ?? '',
                    'user_id' => $borrower->user_id ?? '',
                    'loan_id' => $borrower->loan_id ?? '',
                    'borrower_name' => $borrower_name ?? '',
                    'borrower_id' => $borrower->borrower_unq ?? '',
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
                    'loan_amount' => $this->calculateNetLoanAmount($borrower->loan_amount, $borrower->loan_term),
                    'address' => $user_address_details ?? null,
                    'down_payment' => $down_payment ?? 0,
                    'loan_id' => $loan_id ?? '',
                    'created_on' => $borrower->created_on ?? '',
                    'step_status' => $stepStatus,
                    'last_step' => $lastStep ? [
                        'application_step' => $lastStep['step'],
                        'details' => $lastStep['details'],
                        'status' => $lastStep['status'],
                    ] : null, // Adding last step
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
    
            return $this->helper_controller->send_response('success', "Loan list fetched", 200, $paginatedData);
            
        } catch (Exception $e) {
            // Handle errors
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function getYearlyCount(Request $request) {
        try {
            // Retrieve year input, default to null if not provided
            $year = $request->input('year');
    
            // Validate input
            $request->validate([
                'year' => 'nullable|integer',
            ]);
    
            // Get total loan amount for the specified year or all years
            $loanDetails = LoanBasicDetails::query();
            if ($year) {
                $loanDetails->whereYear('created_at', $year);
            }
            $total_application = $loanDetails->count();
            $approved_application = $loanDetails->where('status', 'APPROVED')->count();
            $rejected_application = $loanDetails->where('status', 'REJECTED')->count();
            $disbursed_application = $loanDetails->where('status', 'ACTIVE')->count();

            $totalLoanAmountQuery = LoanBasicDetails::query();
            if ($year) {
                $totalLoanAmountQuery->whereYear('created_at', $year);
            }
            $totalLoanAmount = $totalLoanAmountQuery->sum('loan_amount');
            
            $resultData = [
                'total_application' => $total_application ?? 0,
                'approved_application' => $approved_application ?? 0,
                'rejected_application' => $rejected_application ?? 0,
                'disbursed_application' => $disbursed_application ?? 0,
                'total_loan_amount' => $totalLoanAmount ?? 0,
            ];
    
            return $this->helper_controller->send_response('success', "Data fetched successfully", 200, $resultData);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
