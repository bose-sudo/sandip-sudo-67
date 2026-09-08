<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\{BatteryTypes, Manufacturers, OemDynamicProduct, OemProductUploadExcel, ProductTypes};
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Maatwebsite\Excel\Facades\Excel;

class OemProductExcelController extends Controller
{
    use ApiResponse;
    // internal keys the validator/persist layer expects
    private const REQUIRED = [
        'oem_id', 'product_name', 'product_type', 'product_sub_type', 'battery_type',
        'ex_showroom_price', 'loan_to_value_ratio',
        'min_loan_amount', 'max_loan_amount', 'min_loan_tenure', 'max_loan_tenure',
        'interest_type', 'interest', 'processing_fee', 'subvention_amount',
        'fldg', 'iot', 'insurance_1st_year', 'insurance_2nd_year',
    ];
    // optional keys (not in the current template)
    private const OPTIONAL = ['product_id', 'max_dealer_borrower_km', 'manufacture_name'];
    // sheet header label -> internal key
    private const ALIAS = ['name' => 'product_name'];

    public function __construct() {}

    /** Upload + validate. Stores PENDING (or REJECTED on validation errors). */
    public function upload_excel_and_validate(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');
        try {
            $request->validate(['file' => 'required|mimes:xlsx']);

            $file = $request->file('file');
            $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $fileName = "{$originalFileName}_" . date('Y_m_d_H_i_s') . ".{$file->getClientOriginalExtension()}";
            $path = $file->storeAs('OemProduct/excel', $fileName, 'public');

            $rows = Excel::toCollection(null, $file)[0];
            $result = $this->validateExcelRows($rows);

            if (!empty($result['errors'])) {
                $record = OemProductUploadExcel::create([
                    'file_name'   => $fileName,
                    'status'      => 'REJECTED',
                    'uploaded_by' => auth()->id(),
                    'updated_by'  => auth()->id(),
                    'errors'      => 'Validation errors',
                    'reason'      => 'Validation errors occurred',
                    'file_path'   => 'storage/' . $path,
                ]);
                return $this->send_response('failed', 'Validation errors', 422, ['errors' => $result['errors'], 'record' => $record]);
            }

            $record = OemProductUploadExcel::create([
                'file_name'   => $fileName,
                'status'      => 'PENDING',
                'uploaded_by' => auth()->id(),
                'updated_by'  => auth()->id(),
                'file_path'   => 'storage/' . $path,
            ]);
            return $this->send_response('success', 'File uploaded successfully', 200, ['record' => $record]);
        } catch (Exception $e) {
            return $this->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
        }
    }

    /** Approve + persist (bulk insert for create, per-row update via product_id). */
    public function approve_excel_and_process(Request $request, $id)
    {
        try {
            $request->validate(['remarks' => 'nullable|string']);

            $record = OemProductUploadExcel::find($id);
            if (!$record)                        return $this->send_response('failed', 'File record not found', 422);
            if ($record->status === 'REJECTED')  return $this->send_response('failed', 'File already rejected', 422);
            if ($record->status === 'APPROVED')  return $this->send_response('failed', 'File already approved', 422);

            $file_path = 'public/OemProduct/excel/' . $record->file_name;
            if (!Storage::exists($file_path))    return $this->send_response('failed', 'File not found', 422);

            $rows = Excel::toCollection(null, Storage::path($file_path))[0];
            $result = $this->validateExcelRows($rows);

            if (!empty($result['errors'])) {
                $record->update([
                    'status'     => 'REJECTED',
                    'reason'     => $request->input('remarks', 'Validation errors occurred'),
                    'errors'     => 'Validation errors',
                    'updated_by' => auth()->id(),
                ]);
                return $this->send_response('failed', 'Validation errors', 422, $result['errors']);
            }

            $now = now();
            $insert = [];

            DB::beginTransaction();

            foreach ($result['validRows'] as $row) {
                $oem = Manufacturers::where('oem_unq', $row['oem_id'])->first();
                if (!$oem) continue;

                $payload = [
                    'oem_id'                 => $oem->id,
                    'product_name'           => trim($row['product_name']),
                    'product_type'           => trim($row['product_type']),
                    'product_sub_type'       => trim($row['product_sub_type'] ?? ''),
                    'battery_type'           => trim($row['battery_type'] ?? ''),
                    'ex_showroom_price'      => is_numeric($row['ex_showroom_price']) ? $row['ex_showroom_price'] : 0,
                    'loan_to_value_ratio'    => $row['loan_to_value_ratio'] ?? 0,
                    'max_dealer_borrower_km' => $row['max_dealer_borrower_km'] ?? 0,
                    'min_loan_amount'        => $row['min_loan_amount'],
                    'max_loan_amount'        => $row['max_loan_amount'],
                    'min_loan_tenure'        => $row['min_loan_tenure'],
                    'max_loan_tenure'        => $row['max_loan_tenure'],
                    'interest_type'          => trim($row['interest_type']),
                    'interest'               => (float) $row['interest'],
                    'processing_fee'         => $row['processing_fee'] ?? 0,
                    'subvention_amount'      => $row['subvention_amount'] ?? 0,
                    'fldg'                   => $row['fldg'] ?? 0,
                    'iot'                    => $row['iot'] ?? 0,
                    'insurance_1st_year'     => $row['insurance_1st_year'] ?? 0,
                    'insurance_2nd_year'     => $row['insurance_2nd_year'] ?? 0,
                    'pricing_source'         => 'oem_dynamic',
                    'updated_at'             => $now,
                ];

                if (!empty($row['is_update']) && !empty($row['product_id'])) {
                    DB::table('oem_dynamic_products')->where('oem_product_unq', $row['product_id'])->update($payload);
                } else {
                    $payload['oem_product_unq'] = '';
                    $payload['status']          = 'active';
                    $payload['created_at']      = $now;
                    $insert[] = $payload;
                }
            }

            if (!empty($insert)) {
                DB::table('oem_dynamic_products')->insert($insert);
                DB::update("UPDATE oem_dynamic_products SET oem_product_unq = 'FIN-DYP-' || id WHERE oem_product_unq = '' OR oem_product_unq IS NULL");
            }

            DB::commit();

            $record->update([
                'status'     => 'APPROVED',
                'reason'     => $request->input('remarks', 'Approved'),
                'updated_by' => auth()->id(),
            ]);

            return $this->send_response('success', 'Products imported successfully', 200);
        } catch (Exception $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            return $this->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
        }
    }

    public function reject_excel(Request $request, $id)
    {
        try {
            $request->validate(['remarks' => 'nullable|string|max:255']);

            $record = OemProductUploadExcel::find($id);
            if (!$record)                       return $this->send_response('failed', 'File record not found', 422);
            if ($record->status === 'REJECTED') return $this->send_response('failed', 'File already rejected', 422);
            if ($record->status === 'APPROVED') return $this->send_response('failed', 'File already approved', 422);

            $record->update([
                'status'     => 'REJECTED',
                'reason'     => $request->input('remarks', 'Rejected'),
                'updated_by' => auth()->id(),
            ]);
            return $this->send_response('success', 'File rejected successfully', 200);
        } catch (Exception $e) {
            return $this->send_response('failed', 'Unable to reject the file', 422, ['message' => $e->getMessage()]);
        }
    }

    public function get_uploaded_files(Request $request)
    {
        try {
            $files = OemProductUploadExcel::query()
                ->when($request->filled(['start_date', 'end_date']), function ($q) use ($request) {
                    $q->whereBetween('created_at', [
                        \Carbon\Carbon::parse($request->start_date, 'Asia/Kolkata')->startOfDay(),
                        \Carbon\Carbon::parse($request->end_date, 'Asia/Kolkata')->endOfDay(),
                    ]);
                })
                ->leftJoin('admin_users as uploader', 'oem_product_upload_excels.uploaded_by', '=', 'uploader.id')
                ->leftJoin('admin_users as updater', 'oem_product_upload_excels.updated_by', '=', 'updater.id')
                ->select('oem_product_upload_excels.*', 'uploader.name as uploaded_by_name', 'updater.name as updated_by_name')
                ->orderByDesc('oem_product_upload_excels.created_at')
                ->paginate($request->input('records_per_page', 10), ['*'], 'page', $request->input('page', 1));

            $files->getCollection()->transform(function ($f) {
                $f->file_path = url($f->file_path);
                return $f;
            });
            return $this->send_response('success', 'Files retrieved successfully', 200, $files);
        } catch (Exception $e) {
            return $this->send_response('failed', 'Unable to retrieve files', 422, ['message' => $e->getMessage()]);
        }
    }

    public function get_template()
    {
        $fileName = 'oem-product-excel.xlsx';
        $filePath = public_path("Assets/templates/{$fileName}");
        if (!file_exists($filePath) || !is_file($filePath)) {
            return $this->send_response('failed', 'File not found', 404);
        }
        return $this->send_response('success', 'Template url retrieved', 200, ['download_link' => asset("Assets/templates/{$fileName}")]);
    }

    private function validateExcelRows($rows): array
    {
        $errors = [];
        $validRows = [];

        // map sheet's own header row -> column index (order-independent, alias-aware)
        $rawHeader = $rows->first()?->toArray() ?? [];
        $colIndex  = [];
        foreach ($rawHeader as $i => $label) {
            $key = strtolower(trim(str_replace(' ', '_', (string) $label)));
            if ($key === '') continue;
            $key = self::ALIAS[$key] ?? $key;
            $colIndex[$key] = $i;
        }
        $missing = array_diff(self::REQUIRED, array_keys($colIndex));
        if (!empty($missing)) {
            return ['errors' => ['Missing columns: ' . implode(', ', $missing)], 'validRows' => []];
        }

        $rows = $rows->skip(1);
        $maxEmptyRows = 3;
        $emptyRowCount = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $cells = $row->toArray();

            if (empty(array_filter($cells))) {
                if (++$emptyRowCount > $maxEmptyRows) {
                    $errors[] = "Processing stopped after encountering {$emptyRowCount} consecutive empty rows.";
                    break;
                }
                continue;
            }
            $emptyRowCount = 0;

            $r = [];
            foreach ([...self::REQUIRED, ...self::OPTIONAL] as $h) {
                $r[$h] = isset($colIndex[$h]) ? ($cells[$colIndex[$h]] ?? null) : null;
            }

            $oem_id            = trim((string) $r['oem_id']);
            $product_name      = trim((string) $r['product_name']);
            $product_type      = trim((string) $r['product_type']);
            $product_sub_type  = trim((string) ($r['product_sub_type'] ?? ''));
            $battery_type      = trim((string) ($r['battery_type'] ?? ''));
            $interest_type     = trim((string) $r['interest_type']);
            $product_id        = trim((string) ($r['product_id'] ?? ''));
            $isUpdate          = $product_id !== '';

            // update flow: product_id must resolve to an existing dynamic product
            if ($isUpdate && !OemDynamicProduct::where('oem_product_unq', $product_id)->exists()) {
                $errors[] = "Row {$rowNumber}: Product with product id '{$product_id}' not found";
            }

            // OEM (by oem_unq)
            $oem = null;
            if ($oem_id === '') {
                $errors[] = "Row {$rowNumber}: OEM ID is required";
            } else {
                $oem = Manufacturers::where('oem_unq', $oem_id)->first();
                if (!$oem) {
                    $errors[] = "Row {$rowNumber}: OEM with unique number '{$oem_id}' not found";
                } elseif (!$isUpdate) {
                    $dup = OemDynamicProduct::where('oem_id', $oem->id)
                        ->where('product_type', $product_type)
                        ->where('product_name', $product_name)
                        ->where('product_sub_type', $product_sub_type)
                        ->where('battery_type', $battery_type)
                        ->first();
                    if ($dup) {
                        $errors[] = "Row {$rowNumber}: Product '{$product_name}' ({$product_type}/{$product_sub_type}/{$battery_type}) already exists for OEM '{$oem_id}'";
                    }
                }
            }

            // required strings
            foreach (['product_name' => $product_name, 'product_type' => $product_type] as $field => $value) {
                if ($value === '') $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $field)) . " is required";
            }

            if ($product_type !== '' && !ProductTypes::where('product_type', $product_type)->exists()) {
                $errors[] = "Row {$rowNumber}: Invalid product type";
            }

            // E-Auto (L5) requires a valid sub type (parity with legacy)
            if ($product_type === 'E-Auto (L5)') {
                $valid = ['E-Auto P L5', 'E-Auto C Cargo', 'EKA L5-3S', 'EKA L5-6S'];
                if ($product_sub_type === '') {
                    $errors[] = "Row {$rowNumber}: Product sub type is required for E-Auto (L5)";
                } elseif (!in_array($product_sub_type, $valid, true)) {
                    $errors[] = "Row {$rowNumber}: Invalid product sub type for E-Auto (L5). Must be one of: " . implode(', ', $valid);
                }
            }

            if ($battery_type !== '' && !BatteryTypes::where('battery_type', $battery_type)->exists()) {
                $errors[] = "Row {$rowNumber}: Invalid battery type";
            }

            // numerics
            $numeric = [
                'ex_showroom_price'   => $r['ex_showroom_price'],
                'loan_to_value_ratio' => $r['loan_to_value_ratio'],
                'min_loan_amount'     => $r['min_loan_amount'],
                'max_loan_amount'     => $r['max_loan_amount'],
                'min_loan_tenure'     => $r['min_loan_tenure'],
                'max_loan_tenure'     => $r['max_loan_tenure'],
                'interest'            => $r['interest'],
                'processing_fee'      => $r['processing_fee'] ?? 0,
                'subvention_amount'   => $r['subvention_amount'] ?? 0,
                'fldg'                => $r['fldg'] ?? 0,
                'iot'                 => $r['iot'] ?? 0,
                'insurance_1st_year'  => $r['insurance_1st_year'] ?? 0,
                'insurance_2nd_year'  => $r['insurance_2nd_year'] ?? 0,
                'max_dealer_borrower_km' => $r['max_dealer_borrower_km'] ?? 0,
            ];
            foreach ($numeric as $field => $value) {
                if ($value === null || $value === '') {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $field)) . " is required and must be numeric";
                } elseif (!is_numeric($value)) {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $field)) . " must be numeric";
                }
            }

            if ((float) $r['loan_to_value_ratio'] < 1) {
                $errors[] = "Row {$rowNumber}: Loan to value ratio must be >= 1";
            }

            if (!in_array($interest_type, [OemDynamicProduct::FLAT, OemDynamicProduct::REDUCING], true)) {
                $errors[] = "Row {$rowNumber}: interest_type must be either flat or reducing";
            }

            if (empty($errors)) {
                $r['product_sub_type'] = $product_sub_type;
                $r['battery_type']     = $battery_type;
                $r['product_id']       = $product_id;
                $r['is_update']        = $isUpdate;
                $validRows[] = $r;
            }
        }

        if (!empty($errors) && empty($validRows)) $errors[] = 'No valid rows found.';

        return ['errors' => $errors, 'validRows' => $validRows];
    }
}
