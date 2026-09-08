<?php

namespace App\Http\Controllers;

use App\Models\Documents;
use App\Models\LoanBasicDetails;
use App\Models\BorrowerKyc;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Traits\ApiResponse;
use Throwable;

class CibilReportController extends Controller
{
    use ApiResponse;

    private const APPLICANT_COLUMN_MAP = [
        'BORROWER'    => 'borrower_cibil_report',
        'COAPPLICANT' => 'coapplicant_cibil_report',
        'GUARANTOR'   => 'guarantor_cibil_report',
    ];

    public function store(Request $request) {
        try {
            $validator = Validator::make($request->all(), [
                'borrower_id'    => 'required|integer',
                'applicant_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
                'file'           => 'required|file|mimes:html,htm,xml,pdf,xlsx,xls,csv|max:10240',
            ]);

            if ($validator->fails()) {
                return $this->send_response('failed', $validator->errors()->first(), 422);
            }

            $loanBasicDetail = LoanBasicDetails::find($request->borrower_id);
            if (!$loanBasicDetail) {
                return $this->send_response('failed', 'Invalid borrower id', 422);
            }

            $borrowerId = $loanBasicDetail->borrower_id;

            $kycExists = BorrowerKyc::where('borrower_id', $request->borrower_id)->exists();
            if (!$kycExists) {
                return $this->send_response('failed', 'Borrower details Step is not completed yet', 422);
            }

            DB::beginTransaction();
            $document = Documents::where('borrower_id', $request->borrower_id)->first();
            if (!$document) {
                return $this->send_response('failed', 'Documents record not found for borrower_id', 422);
            }


            $applicantType = strtoupper($request->applicant_type);
            $applicantTypeLower = strtolower($applicantType);
            $column = self::APPLICANT_COLUMN_MAP[$applicantType];

            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeOriginalName = Str::slug($originalName);

            $fileName = "{$safeOriginalName}-{$borrowerId}-CIBIL.{$extension}";
            $directory = "public/cibil_report/{$borrowerId}/{$applicantTypeLower}";
            $path = "{$directory}/{$fileName}";
            if (!Storage::exists($directory)) {
                Storage::makeDirectory($directory, 0755, true);
            }
            Storage::put($path, file_get_contents($file->getRealPath()));

            $document->{$column} = $path;
            $document->save();

            DB::commit();
            return $this->send_response('success', 'Bureau report uploaded successfully', 200);
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }
}