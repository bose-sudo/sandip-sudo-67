<?php

namespace App\Http\Controllers;

use App\Models\BorrowerCheques;
use App\Models\LoanBasicDetails;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BorrowerChequeController extends Controller
{
    protected $helper_controller;
    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function show_borrower_cheques($borrower_id)
    {
        try {
            $validator = Validator::make(
                ['borrower_id' => $borrower_id],
                ['borrower_id' => 'required|exists:loan_basic_details,id']
            );

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
            
            // $cheques = BorrowerCheques::with(['modifiedBy:id,name'])
            //     ->where('borrower_id', $borrower_id)
            //     ->where('status', '!=', 'deleted')
            //     ->get();
            $cheques = BorrowerCheques::with(['modifiedBy:id,name'])
                        ->join('loan_basic_details', 'loan_basic_details.id', '=', 'borrower_cheques.borrower_id')
                        ->where('borrower_cheques.borrower_id', $borrower_id)
                        ->where('borrower_cheques.status', '!=', 'deleted')
                        ->select('borrower_cheques.*', 'loan_basic_details.borrower_id as borrower_primary')
                        ->get();

            if ($cheques->isEmpty()) {
                return $this->helper_controller->send_response('failed', "No cheques found for this borrower", 422);
            }

            return $this->helper_controller->send_response('success', "Cheques retrieved successfully", 200, $cheques);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('success', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }

    public function store_borrower_cheques(Request $request)
    {
        try {
            // Step 1: Basic validation
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'files' => 'required|array|min:1',
                'files.*.file' => 'required|file|mimes:jpg,png,jpeg,pdf,webp|max:5120',
                'files.*.cheque_no' => 'required|numeric',
                'files.*.ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                'files.*.account_number' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }

            $borrower_key = $loan_details->borrower_id;

            // Step 2: Pre-check for duplicate cheque numbers (not deleted)
            foreach ($request->input('files') as $index => $item) {
                $cheque_no = $item['cheque_no'];
                $exists = BorrowerCheques::where('borrower_id', $request->borrower_id)
                    ->where('cheque_no', $cheque_no)
                    ->where('status', '<>', 'deleted')
                    ->exists();

                if ($exists) {
                    return $this->helper_controller->send_response('failed', "Cheque no {$cheque_no} already exists for this borrower", 422);
                }
            }

            DB::beginTransaction();

            $cheques = [];
            foreach ($request->input('files') as $index => $item) {
                $uploadedFile = $request->file("files.$index.file");
                if (!$uploadedFile) {
                    return $this->helper_controller->send_response('failed', "Missing uploaded file at index $index", 422);
                }

                $cheque_no = $item['cheque_no'];

                // Save file
                $extension = $uploadedFile->getClientOriginalExtension();
                $fileName = $cheque_no . '.' . $extension;
                $path = "Borrower/{$borrower_key}/Borrower_cheques";
                $storedPath = Storage::disk('public')->putFileAs($path, $uploadedFile, $fileName);

                // Save DB record
                $cheque = new BorrowerCheques();
                $cheque->borrower_id = $request->borrower_id;
                $cheque->file = 'storage/'.$storedPath;
                $cheque->cheque_no = $cheque_no;
                $cheque->ifsc = $item['ifsc'];
                $cheque->account_number = $item['account_number'];
                $cheque->status = 'active';
                $cheque->ip = $request->ip();
                $cheque->modified_by = null;
                $cheque->save();

            }


            DB::commit();
            return $this->helper_controller->send_response('success', "Cheques stored successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function update_borrower_cheque(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'file' => 'required|file|mimes:jpg,png,jpeg,pdf,webp|max:5120',
                'cheque_no' => 'required|string',
                'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
                'account_number' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'failed', 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $borrowerCheque = BorrowerCheques::where('id', $id)
                ->where('borrower_id', $request->borrower_id)
                ->where('status', '<>', 'deleted')
                ->first();
            if (!$borrowerCheque) {
                return $this->helper_controller->send_response('failed', "Invalid request, cheque not found", 422);
            }

            $isChequeExist = BorrowerCheques::where('id', '<>', $id)
                ->where('borrower_id', $request->borrower_id)
                ->where('cheque_no', $request->cheque_no)
                ->first();
            if ($isChequeExist) {
                return $this->helper_controller->send_response('failed', "This cheque is already exist for this borrower", 422);
            }

            $loan_details = LoanBasicDetails::find($request->borrower_id);
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "Invalid borrower id", 422);
            }
            $borrower_key = $loan_details->borrower_id;
            // Handle file upload
            $filePath = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $extension = $file->getClientOriginalExtension();
                $fileName = $request->cheque_no . '.' . $extension;
                $relativePath = 'Borrower/' . $borrower_key . '/Borrower_cheques/' . $fileName;

                // Save the file to the public disk
                Storage::disk('public')->putFileAs(
                    'Borrower/' . $borrower_key . '/Borrower_cheques',
                    $file,
                    $fileName
                );

                $filePath = $relativePath; // Save this in the database
            }
            // Insert updated cheques
            $borrowerCheque->borrower_id    = $request->borrower_id;
            $borrowerCheque->file           = 'storage/'.$filePath;
            $borrowerCheque->cheque_no      = $request->cheque_no;
            $borrowerCheque->ifsc           = $request->ifsc;
            $borrowerCheque->account_number = $request->account_number;
            $borrowerCheque->ip             = $this->helper_controller->get_user_ip();
            $borrowerCheque->modified_by    = auth()->id();
            $borrowerCheque->save();

            DB::commit();
            return $this->helper_controller->send_response('success', "Cheque updated successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('success', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }

    public function delete_cheque(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'remarks' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $borrowerCheque = BorrowerCheques::where('id', $id)->where('status', '<>', 'deleted')->first();

            if (!$borrowerCheque) {
                return $this->helper_controller->send_response('failed', "Cheque not found", 422);
            }
            DB::beginTransaction();

            // Soft delete cheques

            $borrowerCheque->remarks        = $request->remarks ?? null;
            $borrowerCheque->status         = 'deleted';
            $borrowerCheque->modified_by    = auth()->id();
            $borrowerCheque->ip             = $this->helper_controller->get_user_ip();
            $borrowerCheque->save();
            DB::commit();

            return $this->helper_controller->send_response('success', "Cheque deleted successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('success', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }

    public function update_cheque_status(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'remarks' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $borrowerCheque = BorrowerCheques::where('id', $id)->where('status', '<>', 'deleted')->first();

            if (!$borrowerCheque) {
                return $this->helper_controller->send_response('failed', "Cheque not found", 422);
            }
            DB::beginTransaction();

            // Soft delete cheques

            $borrowerCheque->remarks        = $request->remarks ?? null;
            $borrowerCheque->status         = ($borrowerCheque->status == 'active') ? 'inactive' : 'active';
            $borrowerCheque->modified_by    = auth()->id();
            $borrowerCheque->ip             = $this->helper_controller->get_user_ip();
            $borrowerCheque->save();
            DB::commit();

            return $this->helper_controller->send_response('success', "Cheque status updated successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('success', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }

    public function update_cheque_used_status(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'used_on' => 'required|date|before_or_equal:today',
                'remarks' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $borrowerCheque = BorrowerCheques::where('id', $id)->where('status', '<>', 'deleted')->first();

            if (!$borrowerCheque) {
                return $this->helper_controller->send_response('failed', "Cheque not found", 422);
            }
            DB::beginTransaction();

            $borrowerCheque->remarks = $request->remarks ?? null;
            $borrowerCheque->is_used = ($borrowerCheque->is_used == false) ? true : false;
            $borrowerCheque->used_on = $request->used_on;
            $borrowerCheque->ip      = $this->helper_controller->get_user_ip();
            $borrowerCheque->save();
            DB::commit();

            return $this->helper_controller->send_response('success', "Cheque used status updated successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('success', "An unknwon error occurred: " . $e->getMessage(), 422);
        }
    }
}
