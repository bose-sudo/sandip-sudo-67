<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\{LoanBasicDetails, DisbursementDetails};

class ExcelUploadController extends Controller
{
    public function index()
    {
        $fileName = 'DELHI (FINAYO) LOAN NO. LIST DT-05.08.26.xlsx';
        $filePath = public_path($fileName);

        if (!file_exists($filePath)) {
            return "File not found in the public folder: " . $fileName;
        }

        $data = Excel::toArray([], $filePath);
        $fColumnData = [];

        if (!empty($data[0])) {
            foreach ($data[0] as $index => $row) {
                if ($index === 0) {
                    continue; // Skip header row
                }

                if (array_key_exists(5, $row) && !empty($row[5])) {
                    $fColumnData[] = trim($row[5]);
                }
            }
        }

        $fColumnData     = array_unique($fColumnData);
        $loanPrimaryKeys = LoanBasicDetails::whereIn('borrower_id', $fColumnData)->pluck('id') ->toArray();

        echo "<h3>Extracted Finayo Id:</h3>";
        print_r($fColumnData);
        
        echo "<h3>Matched Primary Keys from LoanBasicDetails:</h3>";
        print_r($loanPrimaryKeys);
    
    }
}