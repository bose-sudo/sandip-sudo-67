<?php

namespace App\Http\Controllers;

use App\Models\Manufacturers;
use App\Models\OemProducts;
use App\Models\OemProductUploadExcel;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class OemProductController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'oem_id' => 'nullable|integer|exists:manufacturers,id',
            'product_name' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/',
            ],
            'battery_type' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-Z\s]+$/',
            ],
            'oem_product_unq' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9]+$/',
            ],
            'product_type' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/',
            ],
            'start_date' => 'nullable|date|before_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date|before_or_equal:today',
            'page' => 'nullable|integer|min:1',
            'number_of_records' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $query = OemProducts::with(['modifiedBy', 'manufacturer'])->where('status', '<>', 'deleted');

            // Apply filters using request->filled()
            if ($request->filled('product_name')) {
                $productName = strtolower($request->product_name);
                $query->whereRaw('LOWER(product_name) LIKE ?', ['%' . $productName . '%']);
            }

            if ($request->filled('battery_type')) {
                $batteryType = strtolower($request->battery_type);
                $query->whereRaw('LOWER(battery_type)  LIKE ?', ['%' . $batteryType . '%']);
            }

            if ($request->filled('product_type')) {
                $productType = strtolower($request->product_type);
                $query->whereRaw('LOWER(product_type)  LIKE ?', ['%' . $productType . '%']);
            }

            if ($request->filled('oem_product_unq')) {
                $oem_product_unq = strtolower($request->oem_product_unq);
                $query->whereRaw('LOWER(oem_product_unq)  LIKE ?', ['%' . $oem_product_unq . '%']);
            }

            if ($request->filled('oem_id')) {
                $query->where('oem_id', $request->oem_id);
            }

            if ($request->filled('start_date')) {
                $query->where('created_at', '>=', $request->start_date);
            }

            if ($request->filled('end_date')) {
                $query->where('created_at', '<=', $request->end_date);
            }

            $number_of_records = $request->number_of_records ?? 10;
            $page = $request->page ?? 1;

            // Paginate results
            $products = $query->orderBy('id', 'desc')->paginate($number_of_records, ['*'], 'page', $page);

            return $this->helper_controller->send_response('success', 'OEM Products retrieved successfully.', 200, $products);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // Create
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'oem_id' => 'required|integer|exists:manufacturers,id',
            'product_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/', // Allows alphabet, number, space, hyphen, and brackets
            ],
            'battery_type' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z\s]+$/', // Allows only alphabet and space
            ],
            'ex_showroom_price' => 'required|numeric',
            'loan_to_value_ratio' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/', // Allows alphabet, number, space, hyphen, and brackets
            ],
            'min_loan_amount' => 'required|numeric',
            'max_loan_amount' => 'required|numeric',
            'min_loan_tenure' => 'required|numeric',
            'max_loan_tenure' => 'required|numeric',
            'product_type' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/', // Allows alphabet, number, space, hyphen, and brackets
            ],
            'interest_rate' => 'required|numeric',
            'processing_fee' => 'nullable|numeric',
            'subvention_amount' => 'nullable|numeric',
            'fldg_amount' => 'nullable|numeric',
            'iot_amount' => 'nullable|numeric',
            'insurance_1st_year' => 'nullable|numeric',
            'insurance_2nd_year' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // Check for existing product with the same details (case-insensitive)
        $existingProduct = OemProducts::whereRaw('LOWER(product_name)  LIKE ?', ['%' . strtolower($request->product_name) . '%'])
                            ->whereRaw('LOWER(battery_type)  LIKE ?', ['%' . strtolower($request->battery_type) . '%'])
                            ->whereRaw('LOWER(product_type)  LIKE ?', ['%' . strtolower($request->product_type) . '%'])
                            ->where('oem_id', $request->oem_id)
                            ->first();

        if ($existingProduct) {
            return $this->helper_controller->send_response('failed', 'The product with the same oem, product name, battery type, and product type already exists.', 422);
        }

        DB::beginTransaction();
        try {
            $oem_product                        = new OemProducts();
            $oem_product->oem_id                = $request->oem_id;
            $oem_product->product_name          = $request->product_name;
            $oem_product->battery_type          = $request->battery_type;
            $oem_product->ex_showroom_price     = $request->ex_showroom_price;
            $oem_product->loan_to_value_ratio   = $request->loan_to_value_ratio;
            $oem_product->min_loan_amount       = $request->min_loan_amount;
            $oem_product->max_loan_amount       = $request->max_loan_amount;
            $oem_product->min_loan_tenure       = $request->min_loan_tenure;
            $oem_product->max_loan_tenure       = $request->max_loan_tenure;
            $oem_product->product_type          = $request->product_type;
            $oem_product->interest_rate         = $request->interest_rate;
            $oem_product->processing_fee        = $request->processing_fee ?? 0;
            $oem_product->subvention_amount     = $request->subvention_amount ?? 0;
            $oem_product->fldg_amount           = $request->fldg_amount ?? 0;
            $oem_product->iot_amount            = $request->iot_amount ?? 0;
            $oem_product->insurance_1st_year    = $request->insurance_1st_year ?? 0;
            $oem_product->insurance_2nd_year    = $request->insurance_2nd_year ?? 0;
            $oem_product->ip                    = $this->helper_controller->get_user_ip();
            $oem_product->save();

            $oem_product_id                         = "OEMP".$oem_product->id;
            $oem_product_update                     = OemProducts::find($oem_product->id);
            $oem_product_update->oem_product_unq    = $oem_product_id;
            $oem_product_update->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'OEM Product created successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function show($id) {
        try {
            $oem_product = OemProducts::with(['manufacturer'])->find($id);

            if (!$oem_product) {
                return $this->helper_controller->send_response('failed', 'OEM Product not found.', 422);
            }

            return $this->helper_controller->send_response('success', 'OEM Product details fetched.', 200, $oem_product);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // Update
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'product_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/', // Allows alphabet, number, space, hyphen, and brackets
            ],
            'battery_type' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z\s]+$/', // Allows only alphabet and space
            ],
            'ex_showroom_price' => 'required|numeric',
            'loan_to_value_ratio' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/', // Allows alphabet, number, space, hyphen, and brackets
            ],
            'min_loan_amount' => 'required|numeric',
            'max_loan_amount' => 'required|numeric',
            'min_loan_tenure' => 'required|numeric',
            'max_loan_tenure' => 'required|numeric',
            'product_type' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9\s\-\(\)]+$/', // Allows alphabet, number, space, hyphen, and brackets
            ],
            'interest_rate' => 'required|numeric',
            'processing_fee' => 'nullable|numeric',
            'subvention_amount' => 'nullable|numeric',
            'fldg_amount' => 'nullable|numeric',
            'iot_amount' => 'nullable|numeric',
            'insurance_1st_year' => 'nullable|numeric',
            'insurance_2nd_year' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        // Check for existing product with the same details (case-insensitive), excluding the current one
        $existingProduct = OemProducts::whereRaw('LOWER(product_name)  LIKE ?', ['%' . strtolower($request->product_name) . '%'])
                                ->whereRaw('LOWER(battery_type)  LIKE ?', ['%' . strtolower($request->battery_type) . '%'])
                                ->whereRaw('LOWER(product_type)  LIKE ?', ['%' . strtolower($request->product_type) . '%'])
                                ->where('oem_id', $request->oem_id)
                                ->where('id', '!=', $id) // Ensure current product is excluded from the check
                                ->first();

        if ($existingProduct) {
            return $this->helper_controller->send_response('failed', 'The product with the same oem, product name, battery type, and product type already exists.', 422);
        }

        DB::beginTransaction();
        try {
            $oem_product = OemProducts::find($id);

            if (!$oem_product) {
                return $this->helper_controller->send_response('failed', 'OEM Product not found.', 422);
            }

            $oem_product->product_name          = $request->product_name ?? $oem_product->product_name;
            $oem_product->battery_type          = $request->battery_type ?? $oem_product->battery_type;
            $oem_product->ex_showroom_price     = $request->ex_showroom_price ?? $oem_product->ex_showroom_price;
            $oem_product->loan_to_value_ratio   = $request->loan_to_value_ratio ?? $oem_product->loan_to_value_ratio;
            $oem_product->min_loan_amount       = $request->min_loan_amount ?? $oem_product->min_loan_amount;
            $oem_product->max_loan_amount       = $request->max_loan_amount ?? $oem_product->max_loan_amount;
            $oem_product->min_loan_tenure       = $request->min_loan_tenure ?? $oem_product->min_loan_tenure;
            $oem_product->max_loan_tenure       = $request->max_loan_tenure ?? $oem_product->max_loan_tenure;
            $oem_product->product_type          = $request->product_type ?? $oem_product->product_type;
            $oem_product->interest_rate         = $request->interest_rate ?? $oem_product->interest_rate;
            $oem_product->processing_fee        = $request->processing_fee ?? $oem_product->processing_fee;
            $oem_product->subvention_amount     = $request->subvention_amount ?? $oem_product->subvention_amount;
            $oem_product->fldg_amount           = $request->fldg_amount ?? $oem_product->fldg_amount;
            $oem_product->iot_amount            = $request->iot_amount ?? $oem_product->iot_amount;
            $oem_product->insurance_1st_year    = $request->insurance_1st_year ?? $oem_product->insurance_1st_year;
            $oem_product->insurance_2nd_year    = $request->insurance_2nd_year ?? $oem_product->insurance_2nd_year;
            $oem_product->modified_by           = auth()->id();
            $oem_product->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'OEM Product updated successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // Status Change
    public function changeStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'remarks' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $oem_product = OemProducts::find($id);

            if (!$oem_product) {
                return $this->helper_controller->send_response('failed', 'OEM Product not found.', 422);
            }

            $oem_product->status        = ($oem_product->status == 'active') ? 'inactive' : 'active';
            $oem_product->modified_by   = auth()->id();
            $oem_product->admin_remarks = $request->remarks;
            $oem_product->ip            = $this->helper_controller->get_user_ip();
            $oem_product->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'OEM Product status updated successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // Method to upload and validate Excel file
    public function upload_excel_and_validate(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');
        try {
            // Validate the incoming request
            $validator = Validator::make($request->all(), [
                'file' => 'required|mimes:xlsx'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }
    
            DB::beginTransaction();
            // Store the file
            $file = $request->file('file');
            $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $timestamp = date('Y_m_d_H_i_s');
            $fileName = "{$originalFileName}_{$timestamp}.{$file->getClientOriginalExtension()}";
            $path = $file->storeAs('OemProduct/excel', $fileName, 'public');
            
            
            // Load and validate the Excel file
            $rows = Excel::toCollection(null, $file)[0];
            $validationResult = $this->validateExcelRows($rows);
    
            // Check if validation errors exist
            if (!empty($validationResult['errors'])) {
                // If validation errors exist, store the upload record with status 'REJECTED'
                $uploadRecord = OemProductUploadExcel::create([
                    'file_name' => $fileName,
                    'status' => 'REJECTED',
                    'uploaded_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                    'errors' => "Validation errors",
                    'reason' => 'Validation errors occurred',
                    'file_path' => 'storage/' . $path
                ]);
                
                DB::commit();
                return $this->helper_controller->send_response('failed', 'Validation errors', 422, ['errors' => $validationResult['errors'], 'record' => $uploadRecord]);
            }
    
            // Create a record with status 'PENDING' if no validation errors
            $uploadRecord = OemProductUploadExcel::create([
                'file_name' => $fileName,
                'status' => 'PENDING',
                'uploaded_by' => auth()->id(),
                'updated_by' => null,
                'errors' => null,
                'reason' => null,
                'file_path' => 'storage/' . $path
            ]);
            
            DB::commit();
            return $this->helper_controller->send_response('success', 'File uploaded successfully', 200, $uploadRecord);
    
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    private function validateExcelRows($rows)
    {
        $errors = [];
        $validRows = [];
        $headers = [
            'oem_id', 'product_name', 'battery_type', 'ex_showroom_price', 'loan_to_value_ratio', 'min_loan_amount', 'max_loan_amount', 'min_loan_tenure', 'max_loan_tenure', 'product_type', 'interest', 'processing_fee', 'subvention_amount', 'fldg', 'iot', 'insurance_1st_year', 'insurance_2nd_year'
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
            $oem_id = $mappedRow['oem_id'];
            $name = $mappedRow['product_name'];
            $battery_type = $mappedRow['battery_type'];
            $ex_showroom_price = $mappedRow['ex_showroom_price'];
            $loan_to_value_ratio = $mappedRow['loan_to_value_ratio'];
            $min_loan_amount = $mappedRow['min_loan_amount'];
            $max_loan_amount = $mappedRow['max_loan_amount'];
            $min_loan_tenure = $mappedRow['min_loan_tenure'];
            $max_loan_tenure = $mappedRow['max_loan_tenure'];
            $product_type = $mappedRow['product_type'];
            $interest = $mappedRow['interest'];
            $processing_fee = $mappedRow['processing_fee'] ?? 0;
            $subvention_amount = $mappedRow['subvention_amount'] ?? 0;
            $fldg = $mappedRow['fldg'] ?? 0;
            $iot = $mappedRow['iot'] ?? 0;
            $insurance_1st_year = $mappedRow['insurance_1st_year'] ?? 0;
            $insurance_2nd_year = !empty($mappedRow['insurance_2nd_year']) ? $mappedRow['insurance_2nd_year'] : 0;
    
            // Validate oem_id
            if (empty($oem_id)) {
                $errors[] = "Row {$rowNumber}: OEM ID is required";
            } else {
                // Validate oem existence
                $oem = Manufacturers::where('oem_unq', $oem_id)->first();
                if (!$oem) {
                    $errors[] = "Row {$rowNumber}: OEM with unique number '{$oem_id}' not found";
                } else {
                    // Check for existing product
                    $existing_product = OemProducts::where('oem_id', $oem->id)
                        ->where('product_name', $name)
                        ->where('battery_type', $battery_type)
                        ->first();
                    
                    if ($existing_product) {
                        $errors[] = "Row {$rowNumber}: Product with name '{$name}', battery type '{$battery_type}' already exists for oem '{$oem_id}'";
                    }
                }
            }
    
            // Validate required string fields
            $stringFields = [
                'name' => $name,
                'battery_type' => $battery_type,
                'product_type' => $product_type,
            ];
            foreach ($stringFields as $fieldName => $value) {
                if (empty($value) || !is_string($value)) {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $fieldName)) . " is required and must be a string";
                }
            }
    
            // Validate numeric fields
            $numericFields = [
                'ex_showroom_price' => $ex_showroom_price,
                'loan_to_value_ratio' => $loan_to_value_ratio ?? 0,
                'min_loan_amount' => $min_loan_amount,
                'max_loan_amount' => $max_loan_amount,
                'min_loan_tenure' => $min_loan_tenure,
                'max_loan_tenure' => $max_loan_tenure,
                'interest_rate' => $interest,
                'processing_fee' => $processing_fee,
                'subvention_amount' => $subvention_amount ?? 0,
                'fldg_amount' => $fldg ?? 0,
                'iot_amount' => $iot ?? 0,
                'insurance_1st_year' => $insurance_1st_year ?? 0,
                'insurance_2nd_year' => $insurance_2nd_year ?? 0,
            ];

            foreach ($numericFields as $fieldName => $value) {
                if ($value === null || $value === '') {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $fieldName)) . " is required and must be a numeric value";
                } elseif (!is_numeric($value)) {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $fieldName)) . " must be a numeric value";
                }
            }
    
            // Add valid row to the validRows array if no errors
            if (empty($errors)) {
                $validRows[] = $mappedRow;
            }
        }
    
        // If errors exist and valid rows are empty, notify of the issues
        if (!empty($errors) && empty($validRows)) {
            $errors[] = "No valid rows found.";
        }
    
        return [
            'errors' => $errors,
            'validRows' => $validRows
        ];
    }
    
    public function get_uploaded_files(Request $request)
    {
        try {
            $query = OemProductUploadExcel::query();

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
            $files = $query->leftJoin('admin_users as uploader', 'oem_product_upload_excels.uploaded_by', '=', 'uploader.id')
                        ->leftJoin('admin_users as updater', 'oem_product_upload_excels.updated_by', '=', 'updater.id')
                        ->select('oem_product_upload_excels.*', 
                                    'uploader.name as uploaded_by_name', 
                                    'updater.name as updated_by_name')
                        ->orderBy('oem_product_upload_excels.created_at', 'desc')->paginate($records_per_page, ['*'], 'page', $page);
            $files->getCollection()->transform(function ($file) {
                // Assuming file_path is the path stored in the database like 'storage/folder/subfolder'
                $file->file_path = url($file->file_path); // Or use asset($file->file_path)
                return $file;
            });
            
            return $this->helper_controller->send_response('success', 'Files retrieved successfully', 200, $files);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    public function reject_product_excel(Request $request, $id)
    {
        try {
            // Validate the incoming request
            $validator = Validator::make($request->all(), [
                'remarks' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();
            // Find the file record by ID
            $uploadRecord = OemProductUploadExcel::find($id);

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

            DB::commit();
            return $this->helper_controller->send_response('success', 'File rejected successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    // Method to approve and process the file
    public function approve_excel_and_process(Request $request, $id)
    {
        try {
            // Validate request input
            $validator = Validator::make($request->all(), [
                'remarks' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();
            // Find the upload record
            $uploadRecord = OemProductUploadExcel::find($id);

            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            $file_path = 'public/OemProduct/excel/' . $uploadRecord->file_name;
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

                DB::commit();
                return $this->helper_controller->send_response('failed', 'Validation errors', 422, $validationResult['errors']);
            }

            // Process valid rows
            $validRows = $validationResult['validRows'];
            foreach ($validRows as $row) {
                $oem = Manufacturers::where('oem_unq', $row['oem_id'])->first();

                if (!$oem) {
                    $errors[] = "oem with ID '{$row['oem_id']}' not found.";
                    continue;
                }

                // Check for existing product
                $existing_product = OemProducts::where('oem_id', $oem->id)
                    ->where('product_name', $row['product_name'])
                    ->where('battery_type', $row['battery_type'])
                    ->first();

                if (!$existing_product) {
                    $productModel = new OemProducts([
                        'oem_id' => $oem->id,
                        'oem_product_unq' => '',
                        'product_name' => $row['product_name'],
                        'battery_type' => $row['battery_type'],
                        'ex_showroom_price' => is_numeric($row['ex_showroom_price']) ? $row['ex_showroom_price'] : 0,
                        'loan_to_value_ratio' => $row['loan_to_value_ratio'],
                        'min_loan_amount' => $row['min_loan_amount'],
                        'max_loan_amount' => $row['max_loan_amount'],
                        'min_loan_tenure' => $row['min_loan_tenure'],
                        'max_loan_tenure' => $row['max_loan_tenure'],
                        'product_type' => $row['product_type'],
                        'interest_rate' => $row['interest'],
                        'processing_fee' => $row['processing_fee'] ?? 0,
                        'subvention_amount' => $row['subvention_amount'] ?? 0,
                        'fldg_amount' => $row['fldg'] ?? 0,
                        'iot_amount' => $row['iot'] ?? 0,
                        'insurance_1st_year' => $row['insurance_1st_year'] ?? 0,
                        'insurance_2nd_year' => $row['insurance_2nd_year'] ?? 0,
                    ]);

                    $productModel->save();

                    // Generate unique oem_product_unq
                    $productModel->oem_product_unq = 'FIN-OMOD-' . $productModel->id;
                    $productModel->save();
                }
            }

            // Update upload record status to APPROVED
            $uploadRecord->status = 'APPROVED';
            $uploadRecord->reason = $request->input('remarks', 'Approved');
            $uploadRecord->updated_by = auth()->id();
            $uploadRecord->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Products imported successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            // Handle exceptions
            return $this->helper_controller->send_response('failed', 'An unknown error occurred: '. $e->getMessage(), 422);
        }
    }

    public function get_oem_product_excel_template()
    {
        try {
            $fileName = 'oem-product-excel.xlsx';
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
            // Log the exception for debugging purposes

            return $this->helper_controller->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }
}
