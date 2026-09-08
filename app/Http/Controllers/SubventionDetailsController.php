<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use App\Models\LoanBasicDetails;
use App\Models\ProductModel;
use App\Models\SubventionDetails;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\BorrowerVisibilityService;
use App\Traits\ApiResponse;

class SubventionDetailsController extends Controller
{
    use ApiResponse;
    // protected $helper_controller;
    public function __construct(
        private readonly HelperController        $helper_controller,
        private readonly BorrowerVisibilityService $visibilityService
    ) {
        // $this->helper_controller = $helper_controller;
    }

    public function index(Request $request)
    {
        $authId          = auth()->id();
        $user            = auth()->user();

        // Validate the inputs
        $validator = Validator::make($request->all(), [
            'utr_number' => 'nullable|string|regex:/^[a-zA-Z0-9]+$/|max:50',
            'borrower_id' => 'nullable|string',
            'bank_name' => 'nullable|string|regex:/^[a-zA-Z\s]+$/',
            'subvention_amount' => 'nullable|numeric',
            'status' => 'nullable|in:Active,Inactive',
            'start_date' => 'nullable|date|before_or_equal:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            // 'transaction_id' => 'nullable|string|regex:/^[a-zA-Z0-9]+$/|max:50',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // ── Resolve visibility ────────────────────────────────────────────
        $isFullAccess      = $user->is_all_borrower_accessible;
        $visibleDealerUnqs = null;

        if (!$isFullAccess) {
            $resolved = $this->visibilityService->resolveVisibleDealerUnqs($authId);

            if ($resolved->isEmpty()) {
                return $this->send_response('success', 'Subvention details retrieved', 200, [
                    'current_page' => $request->page ?? 1,
                    'data'         => [],
                    'total'        => 0,
                    'per_page'     => $request->limit ?? 10,
                    'last_page'    => 1,
                    'from'         => 0,
                    'to'           => 0,
                ]);
            }

            $visibleDealerUnqs = $resolved;
        }

        // Get pagination inputs with default values
        $page = $request->input('page', 1); // Default to page 1 if not provided
        $limit = $request->input('limit', 10); // Default to 10 records per page if not provided

        $query = SubventionDetails::select(
            [
                'subvention_details.*',
                'loan_basic_details.borrower_id as borrower_unq'
            ]
        )
            ->join('loan_basic_details', 'subvention_details.borrower_id', '=', 'loan_basic_details.id')
            ->where('subvention_details.status', '<>', 'deleted')
            ->with(['modifiedBy:id,name,email']);

        if (!is_null($visibleDealerUnqs)) {
            // Hierarchy: restrict to tagged dealers' products
            $query->whereIn('loan_basic_details.dealer_id', $visibleDealerUnqs);
        }

        // Apply filters only if inputs are filled
        if ($request->filled('utr_number')) {
            $query->whereRaw('LOWER(utr_number) LIKE ?', ['%' . strtolower($request->utr_number) . '%']);
        }

        if ($request->filled('transaction_id')) {
            $query->whereRaw('LOWER(transaction_id) LIKE ?', ['%' . strtolower($request->transaction_id) . '%']);
        }

        if ($request->filled('borrower_id')) {
            $borrowerId = $request->borrower_id;
            if (is_numeric($borrowerId)) {
                $query->where('subvention_details.borrower_id', $borrowerId)
                    ->orWhereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ['%' . $borrowerId . '%']);
            } else {
                $borrowerId = strtolower($request->borrower_id);
                $query->WhereRaw('LOWER(loan_basic_details.borrower_id) LIKE ?', ['%' . $borrowerId . '%']);
            }
        }

        if ($request->filled('subvention_amount')) {
            $query->where('subvention_amount', $request->subvention_amount);
        }
        if ($request->filled('bank_name')) {
            $query->whereRaw('LOWER(bank_name) LIKE ?', ['%' . strtolower($request->bank_name) . '%']);
        }

        if ($request->filled('status')) {
            $query->whereRaw('LOWER(subvention_details.status) LIKE ?',  ['%' . strtolower($request->status) . '%']);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('payment_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('payment_date', '<=', $request->end_date);
        }

        // Apply pagination
        $subvention_details = $query->orderBy('subvention_details.id', 'desc')->paginate($limit, ['*'], 'page', $page);
        $transformedData = $subvention_details->map(function ($item) {
            return [
                'id' => $item->id,
                'utr_number' => $item->utr_number,
                'subvention_amount' => $item->subvention_amount,
                'payment_date' => $item->payment_date ? date('d-m-Y', strtotime($item->payment_date)) : null,
                'transaction_id' => $item->transaction_id,
                'borrower_id' => $item->borrower_id,
                'borrower_unq' => $item->borrower_unq,
                'bank_name' => $item->bank_name,
                'status' => $item->status,
                'payment_receipt_img' => $item->payment_receipt_img,
                'modified_by' => $item->modifiedBy, // Use modifiedBy relationship
                'created_at' => $item->created_at ? date('d-m-Y H:i:s', strtotime($item->created_at)) : null,
                'updated_at' => $item->updated_at ? date('d-m-Y H:i:s', strtotime($item->updated_at)) : null,
            ];
        });

        $data = [
            'data' => $transformedData,
            'total' => $subvention_details->total(),
            'from' => $subvention_details->firstItem(),
            'to' => $subvention_details->lastItem(),
            'current_page' => $subvention_details->currentPage(),
            'last_page' => $subvention_details->lastPage(),
            'per_page' => $subvention_details->perPage(),
        ];

        return $this->helper_controller->send_response('success', 'Subvention details retrieved', 200, $data);
    }

    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'borrower_id' => 'required|integer|exists:loan_basic_details,id',  // Ensures the borrower exists
    //         'utr_number' => 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50',
    //         'subvention_amount' => 'required|numeric|min:0',
    //         'payment_date' => [
    //             'required',
    //             'date',
    //             'before_or_equal:today',  // Ensures the date is not in the future
    //         ],
    //         'bank_name' => 'required|string|regex:/^[a-zA-Z\s]+$/',
    //         'payment_receipt_img' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
    //     ]);

    //     if ($validator->fails()) {
    //         return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //     }

    //     $existingRecord = SubventionDetails::where('borrower_id', $request->borrower_id)->first();

    //     if ($existingRecord) {
    //         return $this->helper_controller->send_response(
    //             'failed',
    //             'A subvention record for this borrower already exists.',
    //             422
    //         );
    //     }

    //     DB::beginTransaction();
    //     try {

    //         $loan_basic_details = LoanBasicDetails::find($request->borrower_id);
    //         if (!$loan_basic_details) {
    //             return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
    //         }

    //         if ($request->hasFile('payment_receipt_img')) {
    //             $base_dir   = 'subvention_receipts';
    //             $filename   = $loan_basic_details->borrower_id . '_payment_receipt.' . $request->payment_receipt_img->getClientOriginalExtension();
    //             $path       = $request->payment_receipt_img->storeAs($base_dir, $filename, 'public');
    //             $filePaths  = 'storage/' . $path;
    //         }

    //         $subvention_detail = new SubventionDetails();
    //         $subvention_detail->borrower_id = $request->borrower_id;
    //         $subvention_detail->subvention_amount = $request->subvention_amount;
    //         $subvention_detail->payment_date = $request->payment_date;
    //         $subvention_detail->utr_number = $request->utr_number;
    //         $subvention_detail->payment_receipt_img = $filePaths ?? null;
    //         $subvention_detail->bank_name = $request->bank_name;
    //         $subvention_detail->modified_by = auth()->id();
    //         $subvention_detail->status = 'active';
    //         $subvention_detail->ip = $this->helper_controller->get_user_ip();
    //         $subvention_detail->save();

    //         DB::commit();
    //         return $this->helper_controller->send_response('success', 'Subvention details added successfully', 200, $subvention_detail);
    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         // Log::error('Subvention details store failed: ' . $e->getMessage());
    //         return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
    //     }
    // }

    // ═══════════════════════════════════════════════════════════
    //  UPDATED store()
    // ═══════════════════════════════════════════════════════════

    public function store(Request $request)
    {
        $baseRules = [
            'borrower_id'         => 'required|integer|exists:loan_basic_details,id',
            'utr_number'          => 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50',
            'subvention_amount'   => 'required|numeric|min:0',
            'payment_date'        => 'required|date|before_or_equal:today',
            'bank_name'           => 'required|string|regex:/^[a-zA-Z\s]+$/',
            'payment_receipt_img' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
        ];

        $rules = array_merge($baseRules, $this->rcChequeRules($request));

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        $existing = SubventionDetails::where('borrower_id', $request->borrower_id)->first();
        if ($existing) {
            return $this->helper_controller->send_response('failed', 'A subvention record for this borrower already exists.', 422);
        }

        DB::beginTransaction();
        try {
            $loan = LoanBasicDetails::find($request->borrower_id);
            if (!$loan) {
                return $this->helper_controller->send_response('failed', 'Invalid borrower id', 422);
            }

            $detail = new SubventionDetails();
            $detail->borrower_id        = $request->borrower_id;
            $detail->subvention_amount  = $request->subvention_amount;
            $detail->payment_date       = $request->payment_date;
            $detail->utr_number         = $request->utr_number;
            $detail->bank_name          = $request->bank_name;
            $detail->payment_receipt_img = $this->storeReceiptImage($request, 'payment_receipt_img', $loan->borrower_id, 'subvention')
                ?? null;

            // ── RC ────────────────────────────────────────────────────
            if ($request->filled('rc_amount')) {
                $detail->rc_amount       = $request->rc_amount;
                $detail->rc_utr_number   = $request->rc_utr_number;
                $detail->rc_payment_date = $request->rc_payment_date;
                $detail->rc_bank_name    = $request->rc_bank_name;
                $detail->rc_payment_receipt_img = $this->storeReceiptImage($request, 'rc_payment_receipt_img', $loan->borrower_id, 'rc');
            }

            // ── Cheque ────────────────────────────────────────────────
            if ($request->filled('cheque_amount')) {
                $detail->cheque_amount        = $request->cheque_amount;
                $detail->cheque_utr_number    = $request->cheque_utr_number;
                $detail->cheque_payment_date  = $request->cheque_payment_date;
                $detail->cheque_bank_name     = $request->cheque_bank_name;
                $detail->cheque_payment_receipt_img = $this->storeReceiptImage($request, 'cheque_payment_receipt_img', $loan->borrower_id, 'cheque');
            }

            $detail->modified_by = auth()->id();
            $detail->status      = 'active';
            $detail->ip          = $this->helper_controller->get_user_ip();
            $detail->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Subvention details added successfully', 200, $detail);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }


    public function show($id)
    {
        $subvention_detail = SubventionDetails::with(['modifiedBy:id,name,email'])->find($id);

        if (!$subvention_detail) {
            return $this->helper_controller->send_response('failed', 'Subvention details not found', 422);
        }

        return $this->helper_controller->send_response('success', 'Subvention details retrieved', 200, $subvention_detail);
    }


    // public function update(Request $request, $id)
    // {
    //     $subvention_detail = SubventionDetails::find($id);

    //     if (!$subvention_detail) {
    //         return $this->helper_controller->send_response('failed', 'Subvention details not found', 422);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'utr_number' => 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50',
    //         'subvention_amount' => 'required|numeric|min:0',
    //         'payment_date' => [
    //             'required',
    //             'date',
    //             'before_or_equal:today', // Ensures the date is not in the future
    //         ],
    //         'borrower_id' => 'required|integer|exists:loan_basic_details,id',
    //         'bank_name' => 'required|string|regex:/^[a-zA-Z\s]+$/',
    //         'payment_receipt_img' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120', // Optional file input
    //     ]);

    //     if ($validator->fails()) {
    //         return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         // Handle payment receipt image upload
    //         if ($request->hasFile('payment_receipt_img')) {
    //             $loan_basic_details = LoanBasicDetails::find($request->borrower_id);

    //             if (!$loan_basic_details) {
    //                 return $this->helper_controller->send_response('failed', 'Invalid borrower ID', 422);
    //             }

    //             $base_dir = 'subvention_receipts';
    //             $filename = $loan_basic_details->borrower_id . '_payment_receipt.' . $request->payment_receipt_img->getClientOriginalExtension();
    //             $path = $request->payment_receipt_img->storeAs($base_dir, $filename, 'public');
    //             $filePaths = 'storage/' . $path;

    //             // Update the file path if a new file is uploaded
    //             $subvention_detail->payment_receipt_img = $filePaths;
    //         }

    //         // Explicitly update fields
    //         $subvention_detail->utr_number = $request->utr_number;
    //         $subvention_detail->subvention_amount = $request->subvention_amount;
    //         $subvention_detail->payment_date = $request->payment_date;
    //         $subvention_detail->borrower_id = $request->borrower_id;
    //         $subvention_detail->bank_name = $request->bank_name;
    //         // $subvention_detail->transaction_id = $request->transaction_id ?? $subvention_detail->transaction_id; // Optional field
    //         $subvention_detail->modified_by = auth()->id();
    //         $subvention_detail->ip = $this->helper_controller->get_user_ip();
    //         $subvention_detail->save();

    //         DB::commit();
    //         return $this->helper_controller->send_response('success', 'Subvention details updated successfully', 200, $subvention_detail);
    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
    //     }
    // }


    // ═══════════════════════════════════════════════════════════
    //  UPDATED update()
    // ═══════════════════════════════════════════════════════════

    public function update(Request $request, $id)
    {
        $detail = SubventionDetails::find($id);
        if (!$detail) {
            return $this->helper_controller->send_response('failed', 'Subvention details not found', 422);
        }

        $baseRules = [
            'borrower_id'         => 'required|integer|exists:loan_basic_details,id',
            'utr_number'          => 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50',
            'subvention_amount'   => 'required|numeric|min:0',
            'payment_date'        => 'required|date|before_or_equal:today',
            'bank_name'           => 'required|string|regex:/^[a-zA-Z\s]+$/',
            'payment_receipt_img' => 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120',
        ];

        $rules = array_merge($baseRules, $this->rcChequeRules($request));

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $loan = LoanBasicDetails::find($request->borrower_id);
            if (!$loan) {
                return $this->helper_controller->send_response('failed', 'Invalid borrower ID', 422);
            }

            // ── Core subvention fields ────────────────────────────────
            $detail->borrower_id       = $request->borrower_id;
            $detail->utr_number        = $request->utr_number;
            $detail->subvention_amount = $request->subvention_amount;
            $detail->payment_date      = $request->payment_date;
            $detail->bank_name         = $request->bank_name;

            $newReceipt = $this->storeReceiptImage($request, 'payment_receipt_img', $loan->borrower_id, 'subvention');
            if ($newReceipt) {
                $detail->payment_receipt_img = $newReceipt;
            }

            // ── RC ────────────────────────────────────────────────────
            if ($request->filled('rc_amount')) {
                $detail->rc_amount       = $request->rc_amount;
                $detail->rc_utr_number   = $request->rc_utr_number;
                $detail->rc_payment_date = $request->rc_payment_date;
                $detail->rc_bank_name    = $request->rc_bank_name;
                $newRcReceipt = $this->storeReceiptImage($request, 'rc_payment_receipt_img', $loan->borrower_id, 'rc');
                if ($newRcReceipt) {
                    $detail->rc_payment_receipt_img = $newRcReceipt;
                }
            } else {
                // If rc_amount is cleared, wipe the entire RC block
                $detail->rc_amount              = null;
                $detail->rc_utr_number          = null;
                $detail->rc_payment_date        = null;
                $detail->rc_bank_name           = null;
                $detail->rc_payment_receipt_img = null;
            }

            // ── Cheque ────────────────────────────────────────────────
            if ($request->filled('cheque_amount')) {
                $detail->cheque_amount        = $request->cheque_amount;
                $detail->cheque_utr_number    = $request->cheque_utr_number;
                $detail->cheque_payment_date  = $request->cheque_payment_date;
                $detail->cheque_bank_name     = $request->cheque_bank_name;
                $newChequeReceipt = $this->storeReceiptImage($request, 'cheque_payment_receipt_img', $loan->borrower_id, 'cheque');
                if ($newChequeReceipt) {
                    $detail->cheque_payment_receipt_img = $newChequeReceipt;
                }
            } else {
                $detail->cheque_amount              = null;
                $detail->cheque_utr_number          = null;
                $detail->cheque_payment_date        = null;
                $detail->cheque_bank_name           = null;
                $detail->cheque_payment_receipt_img = null;
            }

            $detail->modified_by = auth()->id();
            $detail->ip          = $this->helper_controller->get_user_ip();
            $detail->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Subvention details updated successfully', 200, $detail);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function destroy($id)
    {
        $subvention_detail = SubventionDetails::find($id);

        if (!$subvention_detail) {
            return $this->helper_controller->send_response('failed', 'Subvention details not found', 422);
        }

        DB::beginTransaction();
        try {
            $subvention_detail->status = 'deleted';
            $subvention_detail->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Subvention details deleted successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function update_status(Request $request, $id)
    {
        $subvention_detail = SubventionDetails::find($id);

        if (!$subvention_detail) {
            return $this->helper_controller->send_response('failed', 'Subvention details not found', 422);
        }

        DB::beginTransaction();
        try {
            $subvention_detail->status = $subvention_detail->status === 'active' ? 'inactive' : 'active';
            $subvention_detail->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Subvention details status updated', 200, $subvention_detail);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function get_borrower_details_for_subvention_entry($borrower_id)
    {
        if (!is_numeric($borrower_id) || empty($borrower_id)) {
            return $this->helper_controller->send_response('failed', 'Invalid request', 422);
        }

        $loan_basic_details = LoanBasicDetails::find($borrower_id);
        if (!$loan_basic_details) {
            return $this->helper_controller->send_response('failed', 'Borrower not found', 422);
        }

        $dealer_detail = Dealer::where('dealer_unq', $loan_basic_details->dealer_id)->first();
        if (!$dealer_detail) {
            return $this->helper_controller->send_response('failed', 'Invalid request', 422);
        }

        $product_model_query = ProductModel::where('dealer_id', $dealer_detail->id);
        if ($loan_basic_details->model_id != '' && $loan_basic_details->model_id != null) {
            $product_model_query->where('model_unq', $loan_basic_details->model_id);
        } else {
            $product_model_query->where('name', $loan_basic_details->model);
        }
        $product_model = $product_model_query->first();

        $result = [
            'dealer_name' => $dealer_detail->name ?? '',
            'dealer_id' => $loan_basic_details->dealer_id ?? '',
            'dealer_city' => $dealer_detail->city ?? '',
            'product_name' => $product_model->name ?? '',
            'product_battery_type' => $product_model->battery_type ?? '',
            'subvention_amount' => $product_model->subvention_amount ?? '',
        ];

        return $this->helper_controller->send_response('success', 'Details fetched', 200, $result);
    }

    public function check_subvention_payment_status($borrower_id)
    {
        if (!is_numeric($borrower_id) || empty($borrower_id)) {
            return $this->helper_controller->send_response('failed', 'Invalid request', 422);
        }

        $loan_basic_details = LoanBasicDetails::find($borrower_id);
        if (!$loan_basic_details) {
            return $this->helper_controller->send_response('failed', 'Borrower not found', 422);
        }

        $payment_status = 'PAID';
        $subvention_detail = SubventionDetails::where('borrower_id', $borrower_id)->first();
        if (!$subvention_detail) {
            $payment_status = 'UNPAID';
        }

        $result = [
            'payment_status' => $payment_status,
            'utr_number' => $subvention_detail->utr_number ?? '',
            'subvention_amount' => $subvention_detail->subvention_amount ?? '',
            'payment_date' => $subvention_detail->payment_date ?? '',
            'bank_name' => $subvention_detail->bank_name ?? '',
        ];

        return $this->helper_controller->send_response('success', 'Details fetched', 200, $result);
    }

    
    // ═══════════════════════════════════════════════════════════════════════════
    //  DROP-IN REPLACEMENTS for store() and update() in SubventionDetailsController
    //  Add this trait helper to the class or paste the methods directly.
    // ═══════════════════════════════════════════════════════════════════════════
    
    // ── Shared validation rules for RC / Cheque conditional blocks ──────────────

    /**
     * Place this private method inside SubventionDetailsController.
     */
    private function rcChequeRules(Request $request): array
    {
        $rules = [];

        // ── RC: if rc_amount is present, the rest of the RC block is mandatory
        if ($request->filled('rc_amount')) {
            $rules['rc_amount']        = 'required|numeric|min:0';
            $rules['rc_utr_number']    = 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50';
            $rules['rc_payment_date']  = 'required|date|before_or_equal:today';
            $rules['rc_bank_name']     = 'required|string|regex:/^[a-zA-Z\s]+$/';
            $rules['rc_payment_receipt_img'] = 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120';
        } else {
            // Not provided — ensure no partial data slips through
            $rules['rc_amount']       = 'nullable|numeric|min:0';
            $rules['rc_utr_number']   = 'nullable|string|regex:/^[a-zA-Z0-9]+$/|max:50';
            $rules['rc_payment_date'] = 'nullable|date|before_or_equal:today';
            $rules['rc_bank_name']    = 'nullable|string|regex:/^[a-zA-Z\s]+$/';
        }

        // ── Cheque: same pattern
        if ($request->filled('cheque_amount')) {
            $rules['cheque_amount']        = 'required|numeric|min:0';
            $rules['cheque_utr_number']    = 'required|string|regex:/^[a-zA-Z0-9]+$/|max:50';
            $rules['cheque_payment_date']  = 'required|date|before_or_equal:today';
            $rules['cheque_bank_name']     = 'required|string|regex:/^[a-zA-Z\s]+$/';
            $rules['cheque_payment_receipt_img'] = 'nullable|file|mimes:jpg,png,jpeg,pdf|max:5120';
        } else {
            $rules['cheque_amount']       = 'nullable|numeric|min:0';
            $rules['cheque_utr_number']   = 'nullable|string|regex:/^[a-zA-Z0-9]+$/|max:50';
            $rules['cheque_payment_date'] = 'nullable|date|before_or_equal:today';
            $rules['cheque_bank_name']    = 'nullable|string|regex:/^[a-zA-Z\s]+$/';
        }

        return $rules;
    }

    /**
     * Helper to handle a receipt image upload.
     * Returns the storage path string, or null if no file.
     */
    private function storeReceiptImage(Request $request, string $fieldName, string $borrowerUnq, string $prefix): ?string
    {
        if (!$request->hasFile($fieldName)) {
            return null;
        }

        $ext      = $request->file($fieldName)->getClientOriginalExtension();
        $filename = "{$borrowerUnq}_{$prefix}_receipt.{$ext}";
        $path     = $request->file($fieldName)->storeAs('subvention_receipts', $filename, 'public');

        return 'storage/' . $path;
    }
}
