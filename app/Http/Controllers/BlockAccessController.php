<?php

namespace App\Http\Controllers;

use App\Models\BlockedContactExcel;
use App\Models\BlockedContacts;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class BlockAccessController extends Controller
{
    protected $helper_controller;
    public function __construct(HelperController $helper_controller) {
        $this->helper_controller = $helper_controller;
    }

    public function index(Request $request)
    {
        // Validate the inputs
        $validator = Validator::make($request->all(), [
            'mobile' => 'nullable|string|regex:/^[0-9]+$/|min:4|max:10',
            'status' => 'nullable|in:Active,Inactive',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
        }

        // Get pagination inputs with default values
        $page = $request->input('page', 1); // Default to page 1 if not provided
        $limit = $request->input('limit', 10); // Default to 10 records per page if not provided

        $query = BlockedContacts::with(['modifiedBy:id,name'])->where('status', '<>', 'deleted');

        // Apply filters only if inputs are filled
        if ($request->filled('mobile')) {
            $query->whereRaw('mobile LIKE ?', ['%' . $request->mobile . '%']);
        }

        if ($request->filled('status')) {
            $query->whereRaw('LOWER(status) LIKE ?',  ['%' . strtolower($request->status) . '%']);
        }

        // Apply pagination
        $contacts = $query->orderBy('id', 'desc')->paginate($limit, ['*'], 'page', $page);

        return $this->helper_controller->send_response('success', 'Blocked contacts retrieved', 200, $contacts);
    }

    public function show($id)
    {
        $contact = BlockedContacts::find($id);

        if (!$contact) {
            return $this->helper_controller->send_response('failed', 'Contact not found', 422);
        }

        return $this->helper_controller->send_response('success', 'Contact retrieved', 200, $contact);
    }

    public function update(Request $request, $id)
    {
        $contact = BlockedContacts::find($id);

        if (!$contact) {
            return $this->helper_controller->send_response('failed', 'Contact not found', 422);
        }

        $validator = Validator::make($request->all(), [
            'mobile' => 'required|digits:10',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('error', $validator->errors()->first(), 422);
        }

        $check_duplicate = BlockedContacts::where('mobile', $request->mobile)
                            ->where('id', '<>', $id)
                            ->exists();
        if($check_duplicate) {
            return $this->helper_controller->send_response('error', "This number is already exist", 422);
        }

        DB::beginTransaction();
        try {
            $contact->mobile    = $request->mobile ?? $contact->mobile;
            $contact->ip        = $this->helper_controller->get_user_ip();
            $contact->updated_by = auth()->id();
            
            $contact->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'contact updated successfully', 200, $contact);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', 'Failed to update contact', 422);
        }
    }

    public function destroy($id)
    {
        $contact = BlockedContacts::find($id);

        if (!$contact) {
            return $this->helper_controller->send_response('error', 'Contact not found', 422);
        }

        DB::beginTransaction();
        try {
            $contact->status = 'deleted';
            $contact->ip        = $this->helper_controller->get_user_ip();
            $contact->updated_by = auth()->id();
            $contact->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Contact deleted successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('error', 'Unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $contact = BlockedContacts::find($id);

        if (!$contact) {
            return $this->helper_controller->send_response('failed', 'Contact not found', 422);
        }

        DB::beginTransaction();
        try {
            $contact->status = $contact->status === 'active' ? 'inactive' : 'active';
            $contact->ip        = $this->helper_controller->get_user_ip();
            $contact->updated_by = auth()->id();
            $contact->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Contact status updated', 200, $contact);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    // Method to upload and validate Excel file
    public function upload_excel_and_validate(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');
        try {
            // Validate the incoming request
            $request->validate([
                'file' => 'required|mimes:xlsx'
            ]);
    
            // Store the file
            $file = $request->file('file');
            $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $timestamp = date('Y_m_d_H_i_s');
            $fileName = "{$originalFileName}_{$timestamp}.{$file->getClientOriginalExtension()}";
            $path = $file->storeAs('Block_Contacts/excel', $fileName, 'public');
            
            
            // Load and validate the Excel file
            $rows = Excel::toCollection(null, $file)[0];
            $validationResult = $this->validateExcelRows($rows);
    
            // Check if validation errors exist
            if (!empty($validationResult['errors'])) {
                // If validation errors exist, store the upload record with status 'REJECTED'
                $uploadRecord = BlockedContactExcel::create([
                    'file_name' => $fileName,
                    'status' => 'REJECTED',
                    'uploaded_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                    'errors' => "Validation errors",
                    'reason' => 'Validation errors occurred',
                    'file_path' => 'storage/' . $path
                ]);
    
                return $this->helper_controller->send_response('failed', 'Validation errors', 422, ['errors' => $validationResult['errors'], 'record' => $uploadRecord]);
            }
    
            // Create a record with status 'PENDING' if no validation errors
            $uploadRecord = BlockedContactExcel::create([
                'file_name' => $fileName,
                'status' => 'PENDING',
                'uploaded_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'errors' => null,
                'reason' => null,
                'file_path' => 'storage/' . $path
            ]);
    
            return $this->helper_controller->send_response('success', 'File uploaded successfully', 200, ['record' => $uploadRecord]);
    
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    private function validateExcelRows($rows)
    {
        $errors = [];
        $validRows = [];

        $headers = [
            'mobile'
        ];
        $headerCount = count($headers);
    
        // Skip the header row
        $rows = $rows->skip(1);
    
        $maxEmptyRows = 3; // Define a threshold for maximum consecutive empty rows
        $emptyRowCount = 0;
    
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1; // Adjust row number to account for the header row
    
            // Convert $row from Collection to Array
            $rowArray = $row->toArray();
    
            // Trim or pad the row to match header count
            $rowArray = array_slice($rowArray, 0, $headerCount);
            $rowArray = array_pad($rowArray, $headerCount, null);
    
            // Remove empty rows
            if (empty(array_filter($rowArray))) {
                $emptyRowCount++;
                if ($emptyRowCount > $maxEmptyRows) {
                    // Exit early if consecutive empty rows exceed the threshold
                    $errors[] = "Processing stopped after encountering {$emptyRowCount} consecutive empty rows.";
                    break; // Exit loop early
                }
                continue; // Skip to the next row
            } else {
                // Reset the empty row counter if a valid row is found
                $emptyRowCount = 0;
            }
    
            // Map row data to headers
            $mappedRow = array_combine($headers, $rowArray);
    
            if ($mappedRow === false) {
                $errors[] = "Row {$rowNumber}: Could not map row to headers.";
                continue;
            }
    
            // Validate based on the mapped data
            $mobile            = $mappedRow['mobile'];
            
            // Validate dealer_id
            
            if ($mobile === null || $mobile === '') {
                $errors[] = "Row {$rowNumber}: " . "Mobile number is required";
            } elseif(!preg_match('/^\d{10}$/', $mobile)) {
                $errors[] = "Row {$rowNumber}: " . "Invalid format , it should be 10 digit number";
            } else {
                $mobile_exist = BlockedContacts::where('mobile', $mobile)->exists();
                if($mobile_exist) {
                    $errors[] = "Row {$rowNumber}: " . "Mobile number is already exist";
                }
            }
           
            // Set the timezone to Asia/Kolkata
            date_default_timezone_set('Asia/Kolkata');
            // Add valid row to the validRows array if no errors
            if (empty($errors)) {
                $validRows[] = $mappedRow;
            }
        }
    
        // If errors exist and valid rows are empty, notify of the issues
        if (!empty($errors) || empty($validRows)) {
            $errors[] = "No valid rows found.";
        }
    
        return [
            'errors' => $errors,
            'validRows' => $validRows
        ];
    }

    // Method to approve and process the file
    public function approve_excel_and_process(Request $request, $id) {
        try {
            DB::beginTransaction();
            // Validate request input
            $request->validate([
                'remarks' => 'nullable|string'
            ]);

            // Find the upload record
            $uploadRecord = BlockedContactExcel::find($id);

            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            $file_path = 'public/Block_Contacts/excel/' . $uploadRecord->file_name;
            if (!Storage::exists($file_path)) {
                return $this->helper_controller->send_response('failed', 'File not found', 422);
            }

            // Load the Excel file from storage
            $rows = Excel::toCollection(null, Storage::path($file_path))[0];
            $validationResult = $this->validateExcelRows($rows);

            // Check for validation errors
            if (!empty($validationResult['errors'])) {
                $uploadRecord->update([
                    'status' => 'REJECTED',
                    'reason' => $request->input('remarks', 'Validation errors occurred'),
                    'errors' => "Validation errors",
                    'updated_by' => auth()->id()
                ]);

                return $this->helper_controller->send_response('failed', 'Validation errors', 422, $validationResult['errors']);
            }

            // Process valid rows
            $validRows = $validationResult['validRows'];
            foreach ($validRows as $row) {
                
                $contact = BlockedContacts::where('mobile', $row['mobile'])->first();
                if(!$contact) {
                    $contact           = new BlockedContacts();
                    $contact->mobile   = $row['mobile'];
                    $contact->ip       = $this->helper_controller->get_user_ip();
                    $contact->updated_by = auth()->id();
                    $contact->save();  
                }
            }

            // Update upload record status to APPROVED
            $uploadRecord->status       = 'APPROVED';
            $uploadRecord->reason       = $request->input('remarks', 'Approved');
            $uploadRecord->updated_by   = auth()->id();
            $uploadRecord->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Mobile numbers added to block list', 200);
        } catch (Exception $e) {
            // Handle exceptions
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    public function get_uploaded_files(Request $request)
    {
        try {
            $query = BlockedContactExcel::query();

            // Optional date filter
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $start_date = Carbon::parse($request->input('start_date', 'Asia/Kolkata'))->startOfDay();
                $end_date = Carbon::parse($request->input('end_date', 'Asia/Kolkata'))->endOfDay();
                $query->whereBetween('created_at', [$start_date, $end_date]);
            }

            // Pagination parameters
            $page = $request->input('page', 1);
            $records_per_page = $request->input('records_per_page', 10);

            // Join with admin_user table to fetch names
            $files = $query->leftJoin('admin_users as uploader', 'blocked_contact_excels.uploaded_by', '=', 'uploader.id')
                        ->leftJoin('admin_users as updater', 'blocked_contact_excels.updated_by', '=', 'updater.id')
                        ->select('blocked_contact_excels.*', 
                                    'uploader.name as uploaded_by_name', 
                                    'updater.name as updated_by_name')
                        ->orderBy('blocked_contact_excels.created_at', 'desc')->paginate($records_per_page, ['*'], 'page', $page);
            $files->getCollection()->transform(function ($file) {
                // Assuming file_path is the path stored in the database like 'storage/folder/subfolder'
                $file->file_path = url($file->file_path); // Or use asset($file->file_path)
                return $file;
            });
            
            return $this->helper_controller->send_response('success', 'Files retrieved successfully', 200, $files);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unknwon error occurred: '. $e->getMessage(), 422);
        }
    }

    public function reject_product_excel(Request $request, $id)
    {
        try {
            // Validate the incoming request
            $request->validate([
                'remarks' => 'required|string|max:255'
            ]);

            // Find the file record by ID
            $uploadRecord = BlockedContactExcel::find($id);

            // Check if the file record exists
            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            // Check if the file is already rejected
            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            // Update the file record to 'REJECTED' status
            $uploadRecord->update([
                'status' => 'REJECTED',
                'reason' => $request->input('remarks', 'Rejected'),
                'updated_by' => auth()->id()
            ]);

            return $this->helper_controller->send_response('success', 'File rejected successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    public function getTemplateDownloadLink()
    {
        try {
            $fileName = 'blocked-contact-excel.xlsx';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {
                
                return $this->helper_controller->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->helper_controller->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unexpected error occurred: ". $e->getMessage(), 422);
        }
    }
}
