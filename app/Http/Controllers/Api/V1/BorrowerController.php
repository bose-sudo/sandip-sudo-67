<?php
// 13.200.65.87
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\BorrowerKyc;
use App\Models\CoapplicantKyc;
use App\Models\FieldInspectionDetails;
use App\Models\Guarantor;
use App\Models\DocumentBackups;
use App\Models\Documents;
use App\Models\LoanBasicDetails;
use App\Models\User;
use App\Models\VendorServiceApi;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BorrowerController extends Controller
{
    private $helper_controller;
    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function get_borrower_documents($borrower_id = null)
    {
        try {
            if (is_null($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
            }
            // return $borrower_id;
            // $loan_basic_details = LoanBasicDetails::where('borrower_id', trim($borrower_id))->first();
            // if(!$loan_basic_details) {  
            //     return $this->helper_controller->send_response('failed', "Borrower not found basic", 422);
            // }

            // $documents = Documents::where('borrower_id', $loan_basic_details->id)->first();
            $documents = Documents::select('documents.*')->join('loan_basic_details', 'documents.borrower_id', '=', 'loan_basic_details.id')
                ->where('loan_basic_details.borrower_id', $borrower_id)
                ->first();
            if ($documents) {
                $result = [
                    'aadhar_front' => $documents->aadhar_front,
                    'aahar_back' => $documents->aadhar_back,
                    'pan_doc' => $documents->pan_doc,
                    'driving_license' => $documents->driving_license,
                    'per_address_proof' => $documents->per_address_proof,
                    'res_address_proof' => $documents->res_address_proof,
                    'vehicle_quotation' => $documents->vehicle_quotation,
                    'coAadhar_front' => $documents->coAadhar_front,
                    'coAahar_back' => $documents->coAadhar_back,
                    'coPan_doc' => $documents->coPan_doc,
                    'coDriving_license' => $documents->coDriving_license,
                    'coPerAddress_proof' => $documents->coPerAddress_proof,
                    'coResAddress_proof' => $documents->coResAddress_proof,
                    'additional_doc' => $documents->additional_doc,
                    'invoice' => $documents->invoice,
                    'performa' => $documents->performa,
                    'down_payment_receipt' => $documents->down_payment_receipt,
                    'insurance' => $documents->insurance,
                    'rto_tax_receipt' => $documents->rto_tax_receipt,
                    'dl_receipt' => $documents->dl_receipt,
                    'documents_form' => $documents->documents_form,
                    'guarantor_aadhar_front' => $documents->guarantor_aadhar_front,
                    'guarantor_aadhar_back' => $documents->guarantor_aadhar_back,
                    'guarantor_driving_license' => $documents->guarantor_driving_license,
                    'guarantor_pan_doc' => $documents->guarantor_pan_doc,
                    'guarantor_peraddress_proof' => $documents->guarantor_peraddress_proof,
                    'guarantor_coresaddress_proof' => $documents->guarantor_coresaddress_proof,
                    'borrower_avtar' => $documents->borrower_avtar,
                    'coapplicant_avtar' => $documents->coapplicant_avtar,
                    'guarantor_avtar' => $documents->guarantor_avtar,
                    'rto_document' => $documents->rto_document,
                    'mmr' => $documents->mmr,
                    'vehicle_image' => $documents->vehicle_image,
                    'urc_certificate' => $documents->urc_certificate,
                    'borrower_signed_cheque' => $documents->borrower_signed_cheque,
                    'vehicle_signed_cheque' => $documents->vehicle_signed_cheque,
                    'doh' => $documents->doh,
                    'borrower_other_docs' => $documents->borrower_other_docs,
                    'vehicle_other_docs' => $documents->vehicle_other_docs,
                    'borrower_rent_doc' => $documents->borrower_rent_doc,
                    'coapplicant_rent_doc' => $documents->coapplicant_rent_doc,
                    'guarantor_rent_doc' => $documents->guarantor_rent_doc,
                    'borrower_name_declaration' => $documents->borrower_name_declaration,
                    'coapplicant_name_declaration' => $documents->coapplicant_name_declaration,
                    'guarantor_name_declaration' => $documents->guarantor_name_declaration,
                    'chasis_number' => $documents->chasis_number,
                    'motor_controller' => $documents->motor_controller,
                    'battery_image' => $documents->battery_image,
                    'registration_certificate' => $documents->registration_certificate,
                    'noc_document' => $documents->noc_document,
                ];
                return $this->helper_controller->send_response('success', "Borrower documents fetched", 200, $result);
            } else {
                return $this->helper_controller->send_response('success', "Borrower not found", 200);
            }
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function update_borrower_documents(Request $request)
    {
        try {
            if (!isset($request->borrower_id) || empty($request->borrower_id)) {
                return $this->helper_controller->send_response("failed", "Borrower id is required", 422);
            }
            $borrower_id = $request->borrower_id;
            $kyc_docs = Documents::select('documents.*')
                        ->join('loan_basic_details', 'documents.borrower_id', '=', 'loan_basic_details.id')
                        ->where('loan_basic_details.borrower_id', $borrower_id)
                        ->first();

            if (!$kyc_docs) {
                return $this->helper_controller->send_response("failed", "Document record not found", 422);
            }

            date_default_timezone_set('Asia/Kolkata');
            $now = date('Ymd-His');

            $borrower_primary_key = $kyc_docs->borrower_id;    
            
            $flags = ['BORROWER_KYC', 'COAPPLICANT_KYC', 'GUARANTOR_KYC', 'VEHICLE_DOCUMENT', 'BATTERY_DOCUMENT'];
            $doc_types = [
                'aadhar_front' => 'AADHAR_FRONT',
                'aadhar_back' => 'AADHAR_BACK',
                'pan_doc' => 'PAN_CARD',
                'res_address_proof' => 'ELECTRIC_BILL',
                'borrower_avtar' => 'PERSON',
                'urc_certificate' => 'URC_CERTIFICATE',
                'driving_license' => 'DRIVING_LICENCE',
                'borrower_signed_cheque' => 'SIGNED_CHEQUE',
                'borrower_other_docs' => 'OTHER',
                'coAadhar_front' => 'AADHAR_FRONT',
                'coAadhar_back' => 'AADHAR_BACK',
                'coPan_doc' => 'PAN_CARD',
                'coapplicant_avtar' => 'PERSON',
                'coResAddress_proof' => 'ELECTRIC_BILL',
                'guarantor_aadhar_front' => 'AADHAR_FRONT',
                'guarantor_aadhar_back' => 'AADHAR_BACK',
                'guarantor_pan_doc' => 'PAN_CARD',
                'guarantor_avtar' => 'PERSON',
                'guarantor_coresaddress_proof' => 'ELECTRIC_BILL',
                'rto_document' => 'VEHICLE_RTO',
                'rto_tax_receipt' => 'VEHICLE_RTO_TAX',
                'mmr' => 'VEHICLE_MMR',
                'invoice' => 'VEHICLE_INVOICE',
                'insurance' => 'VEHICLE_INSURANCE',
                'vehicle_image' => 'VEHICLE_IMAGE',
                'doh' => 'DOH',
                'chasis_number' => 'CHASIS_IMAGE',
                'motor_controller' => 'MOTOR_CONTROLLER_IMAGE',
                'battery_image' => 'BATTERY_IMAGE',
                'registration_certificate' => 'REGISTRATION_CERTIFICATE',
                'vehicle_signed_cheque' => 'SIGNED_CHEQUE',
                'vehicle_other_docs' => 'OTHER',
                'borrower_rent_doc' => 'RENT_AGREEMENT',
                'coapplicant_rent_doc' => 'RENT_AGREEMENT',
                'guarantor_rent_doc' => 'RENT_AGREEMENT',
                'borrower_name_declaration' => 'NAME_DECLARATION',
                'coapplicant_name_declaration' => 'NAME_DECLARATION',
                'guarantor_name_declaration' => 'NAME_DECLARATION',
                'borrower_income_proof_doc' => 'BORROWER_INCOME_PROOF_DOC',
                'coapplicant_income_proof_doc' => 'COAPPLICANT_INCOME_PROOF_DOC',
                'guarantor_income_proof_doc' => 'GUARANTOR_INCOME_PROOF_DOC',
                'borrower_dob_declaration' => 'DOB_DECLARATION',
                'coapplicant_dob_declaration' => 'DOB_DECLARATION',
                'guarantor_dob_declaration' => 'DOB_DECLARATION',
                'noc_document' => 'NOC_DOCUMENT',
            ];

            $allowed_types = ["image/jpeg", "image/png", "image/jpg", "application/pdf"];
            $max_size = 5 * 1024 * 1024; // 5 MB

            DB::beginTransaction();

            foreach ($doc_types as $field => $doc_type) {
                $flag = $this->determineFlag($field, $flags);

                // Handle single file input
                if ($request->hasFile($field) && !is_array($request->file($field))) {
                    $file = $request->file($field);
                    $remark = $request->input($field . '_remark', '');

                    // Validate file type and size
                    if (!in_array($file->getMimeType(), $allowed_types)) {
                        DB::rollBack();
                        return $this->helper_controller->send_response("failed", "Only JPG, PNG, JPEG, and PDF files are allowed for $field", 422);
                    }
                    if ($file->getSize() > $max_size) {
                        DB::rollBack();
                        return $this->helper_controller->send_response("failed", "$field size must not exceed 5 MB", 422);
                    }

                    // Backup the existing file (if any)
                    $previous_url = $kyc_docs->$field;
                    $previous_url = str_replace('/storage/', '', $previous_url);
                    if ($previous_url) {
                        $backup_file_name = 'backup-' . $now .'-'. basename($previous_url);
                        $backup_path = 'Document_backup/Borrower/' . $borrower_id . '/' . $flag . '/' . $backup_file_name;

                        if (Storage::disk('public')->exists($previous_url)) {
                            $backup_dir = dirname($backup_path);
                            if (!Storage::disk('public')->exists($backup_dir)) {
                                Storage::disk('public')->makeDirectory($backup_dir);
                            }
                            Storage::disk('public')->copy($previous_url, $backup_path);
                        }
                    }

                    // Store the new file
                    // $fileName = $doc_type . '-' . $this->helper_controller->generate_random_string(16) . '.' . $file->getClientOriginalExtension();

                    if($doc_type == 'OTHER' || $doc_type == "SIGNED_CHEQUE") {
                        $fileName = $doc_type.'-'.$this->helper_controller->generate_random_string(16) . '.' . $file->getClientOriginalExtension();
                    } else {
                        $fileName = $doc_type. '.' . $file->getClientOriginalExtension();
                    }
                    $path = $file->storeAs('public/Borrower/' . $borrower_id . '/' . $flag, $fileName);
                    $new_url = Storage::url($path);

                    // Save the new document URL
                    $kyc_docs->$field = $new_url;

                    // Create a backup record
                    DocumentBackups::create([
                        'borrower_id' => $borrower_primary_key,
                        'flag_type' => $flag,
                        'doc_type' => $doc_type,
                        'previous_doc_url' => $previous_url,
                        'new_doc_url' => $new_url,
                        'backup_file_url' => isset($backup_file_name) ? Storage::url($backup_path) : null,
                        'remark' => $remark,
                        'user_id' => null,
                        'admin_user_id' => auth()->id(),
                        'executive_id' => null,
                        'status' => null,
                    ]);
                }

                if ($request->has($field) && is_array($request->file($field))) {
                    $files = $request->file($field);
                    $remarks = $request->input($field . '_remarks', []);
                    $file_urls = [];
                    $backup_files = [];

                    // Fetch existing files for this field
                    $previous_files = !empty($kyc_docs->$field) ? explode(',', $kyc_docs->$field) : [];

                    foreach ($files as $index => $file) {
                        // Validate file type and size
                        if (!in_array($file->getMimeType(), $allowed_types)) {
                            DB::rollBack();
                            return $this->helper_controller->send_response("failed", "Only JPG, PNG, JPEG, and PDF files are allowed for $field", 422);
                        }
                        if ($file->getSize() > $max_size) {
                            DB::rollBack();
                            return $this->helper_controller->send_response("failed", "$field size must not exceed 5 MB", 422);
                        }

                        // Backup the previous file if it exists in the corresponding index
                        if (isset($previous_files[$index])) {
                            $previous_file_url = str_replace('/storage/', '', $previous_files[$index]);
                            $backup_file_name = 'backup-' . $now . '-' . basename($previous_file_url);
                            $backup_path = 'Document_backup/Borrower/' . $borrower_id . '/' . $flag . '/' . $backup_file_name;

                            if (Storage::disk('public')->exists($previous_file_url)) {
                                $backup_dir = dirname($backup_path);
                                if (!Storage::disk('public')->exists($backup_dir)) {
                                    Storage::disk('public')->makeDirectory($backup_dir);
                                }
                                Storage::disk('public')->copy($previous_file_url, $backup_path);
                            }

                            // Save backup information
                            DocumentBackups::create([
                                'borrower_id' => $borrower_primary_key,
                                'flag_type' => $flag,
                                'doc_type' => $doc_type,
                                'previous_doc_url' => ($previous_file_url) ? Storage::url($previous_file_url) : '',
                                'new_doc_url' => null, // This will be set after the new file is saved
                                'backup_file_url' => Storage::url($backup_path),
                                'remark' => $remarks[$index] ?? '',
                                'user_id' => null,
                                'admin_user_id' => auth()->id(),
                                'executive_id' => null,
                                'status' => null,
                            ]);
                        }

                        // Store the new file
                        if ($doc_type == 'OTHER' || $doc_type == "SIGNED_CHEQUE") {
                            $fileName = $doc_type . '-' . $this->helper_controller->generate_random_string(16) . '.' . $file->getClientOriginalExtension();
                        } else {
                            $fileName = $doc_type . '.' . $file->getClientOriginalExtension();
                        }

                        $path = $file->storeAs('public/Borrower/' . $borrower_id . '/' . $flag, $fileName);
                        $url = Storage::url($path);

                        // Replace the existing file URL at the same index
                        $previous_files[$index] = $url;

                        // Update the DocumentBackups record for the new file URL
                        DocumentBackups::where('borrower_id', $borrower_primary_key)
                            ->where('flag_type', $flag)
                            ->where('doc_type', $doc_type)
                            ->where('previous_doc_url', Storage::url($previous_file_url))
                            ->update(['new_doc_url' => $url]);
                    }

                    // Merge new file URLs with existing ones and save them in the database
                    $kyc_docs->$field = implode(',', $previous_files);
                }

            }

            // Save the document details
            $kyc_docs->save();

            DB::commit();
            return $this->helper_controller->send_response("success", "Documents updated successfully", 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response("failed", "Failed to update documents: " . $e->getMessage(), 422);
        }
    }


    /**
    * Determines the flag based on the document field.
    */
    // private function determineFlag($field, $flags)
    // {
    //     if (strpos($field, 'co') === 0) {
    //         return $flags[1]; // COAPPLICANT_KYC
    //     } elseif (strpos($field, 'guarantor') === 0) {
    //         return $flags[2]; // GUARANTOR_KYC
    //     } elseif (strpos($field, 'vehicle') === 0 || strpos($field, 'rto') === 0 || strpos($field, 'mmr') === 0) {
    //         return $flags[3]; // VEHICLE_DOCUMENT
    //     } else {
    //         return $flags[0]; // BORROWER_KYC
    //     }
    // }
    private function determineFlag($field, $flags)
    {
        $coapplicantFields = [
            'coAadhar_front', 'coAadhar_back', 'coPan_doc', 'coapplicant_avtar', 'coResAddress_proof',
            'coapplicant_rent_doc', 'coapplicant_name_declaration', 'coapplicant_income_proof_doc', 'coapplicant_dob_declaration'
        ];

        $guarantorFields = [
            'guarantor_aadhar_front', 'guarantor_aadhar_back', 'guarantor_pan_doc', 'guarantor_avtar',
            'guarantor_coresaddress_proof', 'guarantor_name_declaration', 'guarantor_rent_doc',
            'guarantor_income_proof_doc', 'guarantor_dob_declaration'
        ];

        $vehicleFields = [
            'rto_document', 'rto_tax_receipt', 'mmr', 'invoice', 'insurance', 'vehicle_image', 'doh',
            'chasis_number', 'motor_controller', 'battery_image', 'registration_certificate',
            'vehicle_signed_cheque', 'vehicle_other_docs'
        ];

        if (in_array($field, $coapplicantFields)) {
            return $flags[1]; // COAPPLICANT_KYC
        } elseif (in_array($field, $guarantorFields)) {
            return $flags[2]; // GUARANTOR_KYC
        } elseif (in_array($field, $vehicleFields)) {
            return $flags[3]; // VEHICLE_DOCUMENT
        } else {
            return $flags[0]; // BORROWER_KYC
        }
    }



    public function demo()
    {
        $sample_json = '{
            "aadhar_front": "file_aadhar_front.jpg",
            "aadhar_back": "file_aadhar_back.jpg",
            "pan_card": "file_pan_card.pdf",
            "electric_bill": "file_electric_bill.pdf",
            "photo": "file_photo.jpg",
            "urc_certificate": "file_urc_certificate.pdf",
            "driving_license_img": "file_driving_license.jpg",
            "borrower_signed_cheque": ["file_cheque_1.jpg", "file_cheque_2.jpg"],
            "borrower_other_docs": ["file_other_doc_1.pdf", "file_other_doc_2.pdf"],

            "coAadhar_front": "file_co_aadhar_front.jpg",
            "coAadhar_back": "file_co_aadhar_back.jpg",
            "coPan_doc": "file_co_pan_card.pdf",
            "coapplicant_avtar": "file_co_photo.jpg",
            "coResAddress_proof": "file_co_electric_bill.pdf",

            "guarantor_aadhar_front": "file_guarantor_aadhar_front.jpg",
            "guarantor_aadhar_back": "file_guarantor_aadhar_back.jpg",
            "guarantor_pan_doc": "file_guarantor_pan_card.pdf",
            "guarantor_avtar": "file_guarantor_photo.jpg",
            "guarantor_coresaddress_proof": "file_guarantor_electric_bill.pdf",

            "rto_document": "file_rto_document.pdf",
            "rto_tax_receipt": "file_rto_tax_receipt.pdf",
            "mmr": "file_mmr.pdf",
            "invoice": "file_invoice.pdf",
            "insurance": "file_insurance.pdf",
            "vehicle_image": "file_vehicle_image.jpg",
            "doh": "file_doh.pdf",
            "chasis_number": "file_chasis_image.jpg",
            "motor_controller": "file_motor_controller_image.jpg",
            "battery_image": "file_battery_image.jpg",
            "registration_certificate": "file_registration_certificate.pdf",

            "vehicle_signed_cheque": ["file_vehicle_cheque_1.jpg", "file_vehicle_cheque_2.jpg"],
            "vehicle_other_docs": ["file_vehicle_doc_1.pdf", "file_vehicle_doc_2.pdf"]
        }';
    }

    public function get_borrower_loan_details($borrower_id = null) {
        try {
            if (is_null($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
            }
            
            $loan_basic_details = LoanBasicDetails::where('borrower_id', $borrower_id)->first();
            if(!$loan_basic_details) {
                return $this->helper_controller->send_response('failed', "Borrower details not found", 422);
            }
            
            $product_type = '';

            if($loan_basic_details->product_id == '1') {
                $product_type = "E-Rickshaw (L3)";
            } elseif ($loan_basic_details->product_id == '2') {
                $product_type = "E-Auto (L5)";
            } elseif ($loan_basic_details->product_id == '3') {
                $product_type = "E-Two Wheeler";
            } elseif ($loan_basic_details->product_id == '4') {
                $product_type = "Battery";
            }

            $result = [
                'id' => $loan_basic_details->id,
                'borrower_id' => $loan_basic_details->borrower_id,
                'loan_amount' => $loan_basic_details->loan_amount,
                'product_type' => $product_type,
            ];
            return $this->helper_controller->send_response('success', "Loan basic details fetched", 200, $result);
            
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    public function get_api_details_by_borrower(Request $request, $borrower_id) {  
        try {
            if(is_null($borrower_id) || !is_numeric($borrower_id)) {
                return $this->helper_controller->send_response('failed', "Borrower id is required", 422);
            }
            
            // Fetch loan details for the borrower
            $loan_details = LoanBasicDetails::find($borrower_id);
            
            if (!$loan_details) {
                return $this->helper_controller->send_response('failed', "Borrower not found", 422);
            }

            // Fetch primary borrower details
            $borrower_primary = $loan_details->id;
            $borrower_kyc = BorrowerKyc::where('borrower_id', $borrower_primary)->first();
            // if (!$borrower_kyc) {
            //     return response()->json(['error' => 'Borrower not found'], 404);
            // }
            $user = User::find($loan_details->user_id);
            if(!$user) {
                return response()->json(['error' => 'User not found'], 422);
            }

            // Fetch API details
            $api_details = VendorServiceApi::where('borrower_id', $borrower_primary)
                            ->orderBy('id', 'asc')
                            ->get();

            if ($api_details->isEmpty()) {
                return response()->json(['message' => 'No API details found for this borrower'], 404);
            }

            // Prepare the data for response
            $result = [];
            foreach ($api_details as $index => $api_detail) {
                $request_data = $response_data = [];

                // Handle base64 decoding and unserializing for specific API flags
                if (in_array($api_detail->api_flag, [
                    'AADHAR_CARD_VERIFICATION_OKYC_PAPERLESS_XML',
                    'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML',
                    'PAN_DETAILS_VALIDATION',
                    'AADHAR_CARD_OCR',
                    'PAN_CARD_OCR',
                ])) {
                    $json_request = base64_decode($api_detail->request, true);
                    $request_data = unserialize($json_request);
                    $request_data = json_decode($request_data, true);


                    $json_response = base64_decode($api_detail->response, true);
                    $response_data = unserialize($json_response);
                    $response_data = json_decode($response_data, true);
                } else {
                    if(!in_array($api_detail->api_flag, ['IOT_VEHICLE_LIST_API', 'IOT_VEHICLE_DETAILS_API'])) {
                        $request_data = json_decode($api_detail->request, true);

                        $response_data = json_decode($api_detail->response, true);
                    } else {
                        $request_data = ''; json_decode($api_detail->request, true);
                        $response_data = ''; //json_decode($api_detail->response, true);
                    }
                }

                // Ensure request and response are valid arrays, fall back to empty arrays if not
                $request_data = is_array($request_data) ? $request_data : [];
                $response_data = is_array($response_data) ? $response_data : [];

                // Build the data structure for each API detail
                $result[] = [
                    // 'sl' => $index + 1,
                    'created_at' => date('d-m-Y H:i:s', strtotime($api_detail->created_at)),
                    'borrower_id' => $loan_details->borrower_id,
                    'name' => $borrower_kyc->name ?? $user->name ?? '',
                    'mobile' => $borrower_kyc->mobile ?? $user->mobile ?? '',
                    'status' => strtoupper($api_detail->status),
                    'applicant_type' => $api_detail->applicant_type,
                    'api_flag' => str_replace('_', ' ', $api_detail->api_flag),
                    'request' => $this->formatApiRequest($api_detail->api_flag, $request_data),
                    'response' => $this->formatApiResponse($api_detail->api_flag, $api_detail->status, $response_data),
                ];
            }

            // Return the API details in JSON format
            return response()->json(['data' => $result], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while processing the request', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Format the request data based on API flag
     */
    private function formatApiRequest($api_flag, $request_data) {
        switch ($api_flag) {
            case 'AADHAR_CARD_VERIFICATION_OKYC_PAPERLESS_XML':
                return [
                    'Applicant type' => $request_data['applicant_type'] ?? null,
                    'Aadhar' => $this->helper_controller->maskAadharNumber($request_data['aadhar']) ?? null,
                ];

            case 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML':
                return [
                    'OTP' => $request_data['otp'] ?? null,
                    'Applicant type' => $request_data['applicant_type'] ?? null,
                ];

            case 'PAN_DETAILS_VALIDATION':
                return [
                    'Applicant type' => $request_data['applicant_type'] ?? null,
                    'PAN' => $request_data['pan'] ?? null,
                    'Name' => $request_data['name'] ?? null,
                ];

            case 'CREDIT_SCORE_API_SIGNZY_EXPERIAN':
                return [
                    'PAN' => $request_data['pan'] ?? null,
                    'Name' => $request_data['name'] ?? null,
                ];
            case 'AADHAR_CARD_OCR':
                return [
                    'Applicant type' => $request_data['user_type'] ?? null,
                ];
            case 'PAN_CARD_OCR':
                return [
                    'Applicant type' => $request_data['user_type'] ?? null,
                ];
            case 'VKYC_GENERATE_URL_API':
                return [
                    'Name' => $request_data['name'] ?? null,
                    'Mobile' => $request_data['mobile'] ?? null,
                ];
            case 'VKYC_CALL_DETAILS_USING_UNIQUE_ID_API':
                return [
                    'Application number' => $request_data['application_number'] ?? null
                ];
            case 'GENERATE_ESIGN_FILE_UPLOAD_URL':
                return [
                    'Name' => $request_data['signers'][0]['name'] ?? null,
                    'Mobile' => $request_data['signers'][0]['mobile'] ?? null,
                    'Email' => $request_data['signers'][0]['email'] ?? null,
                    'Location' => $request_data['signers'][0]['location'] ?? null,
                ];
            case 'GENERATE_ESIGN_URL':
                return [
                    'Doc Id' => $request_data['doc_id'] ?? null,
                ];
            case 'DOWNLOAD_ESIGN_DOCUMENT':
                return [
                    'Applicant Type' => $request_data['applicant_type'] ?? null,
                    'Doc Id' => $request_data['doc_id'] ?? null,
                ];
            case 'VKYC_TRANSACTION_DETAILS_API':
                return [
                    'Borrower Id' => $request_data['borrower_id'] ?? null
                ];

            default:
                return $request_data;
        }
    }

    /**
     * Format the response data based on API flag and status
     */
    private function formatApiResponse($api_flag, $status, $response_data) {
        if(count($response_data) == 0) {
            return null;
        }
        if (strtolower($status) == 'valid') {
            switch ($api_flag) {
                case 'AADHAR_CARD_VERIFICATION_OKYC_PAPERLESS_XML':
                    return [
                        'Message' => $response_data['model']['uidaiResponse']['message'] ?? null,
                        'Msg' => $response_data['msg'] ?? null,
                    ];

                case 'AADHAR_CARD_VERIFICATION_USING_OTP_OKYC_PAPERLESS_XML':
                    return [
                        'Masked adhar number' => $response_data['model']['maskedAdharNumber'] ?? null,
                        'Name' => $response_data['model']['name'] ?? null,
                        'DOB' => $response_data['model']['dob'] ?? null,
                        'Message' => $response_data['msg'] ?? null,
                    ];

                case 'PAN_DETAILS_VALIDATION':
                    if(isset($response_data['result_code']) && $response_data['result_code'] == 101) {
                        $message_text = "PAN details fetched";
                    } else {
                        $message_text = $response_data['message'] ?? null;
                    }
                    return [
                        'Pan' => $response_data['result']['pan'] ?? null,
                        'Full name' => $response_data['result']['fullname'] ?? null,
                        'DOB' => $response_data['result']['dob'] ?? null,
                        'Name match score' => $response_data['result']['name_match_score'] ?? null,
                        'Message' => $message_text ?? null,
                    ];

                case 'CREDIT_SCORE_API_SIGNZY_EXPERIAN':
                    $credit_score = $response_data['data']['jsonExperianReport']['SCORE']['FCIREXScore'] ?? null;
                    if($credit_score == null) {
                        $credit_score_txt = '';
                    } elseif($credit_score < 650) {
                        $credit_score_txt = 'Low Credit Score';
                    } else {
                        $credit_score_txt ='Good Credit Score';
                    }
                    return [
                        'Message' => $response_data['message'] ?? null,
                        'Error message' => $response_data['error']['message'] ?? '',
                        'Credit score' => $credit_score,
                        'Credit status' => $credit_score_txt,
                    ];
                case 'AADHAR_CARD_OCR':
                    $document_type = '';
                    if(isset($response_data['result'][0]['type'])) {
                        $document_type = str_replace('_', ' ', $response_data['result'][0]['type']);
                        $document_type = ucfirst($document_type);
                    }
                    if(isset($response_data['result'][0]['type']) && $response_data['result'][0]['type'] != '' && $response_data['result'][0]['type'] == 'aadhaar_front_bottom') {

                        return [
                            'Status' => $response_data['status'] ?? null,
                            'Document type' => $document_type ?? null,
                            'Aadhar number' => $response_data['result'][0]['details']['aadhaar']['value'] ?? null,
                            'Confidence' => $response_data['result'][0]['details']['aadhaar']['conf'] ?? null,
                            'Is masked' => $response_data['result'][0]['details']['aadhaar']['ismasked'] ?? null,
                            'Name' => $response_data['result'][0]['details']['name']['value'] ?? null,
                            'DOB' => $response_data['result'][0]['details']['dob']['value'] ?? null,
                        ];
                    } else {
                        return [
                            'Status' => $response_data['status'] ?? null,
                            'Document type' => $document_type ?? null,
                            'Aadhar number' => $response_data['result'][0]['details']['aadhaar']['value'] ?? null,
                            'Confidence' => $response_data['result'][0]['details']['aadhaar']['conf'] ?? null,
                            'Is masked' => $response_data['result'][0]['details']['aadhaar']['ismasked'] ?? null,
                            'PIN' => $response_data['result'][0]['details']['pin']['value'] ?? null,
                        ];
                    }
                case 'PAN_CARD_OCR':
                    $document_type = '';
                    if(isset($response_data['result'][0]['type'])) {
                        $document_type = str_replace('_', ' ', $response_data['result'][0]['type']);
                        $document_type = ucfirst($document_type);
                    }
                    return [
                        'Status' => $response_data['status'] ?? null,
                        'PAN' => $response_data['result'][0]['details']['pan_no']['value'] ?? null,
                        'Confidence' => $response_data['result'][0]['details']['pan_no']['conf'] ?? null,
                        'Name' => $response_data['result'][0]['details']['name']['value'] ?? null,
                        'DOB' => $response_data['result'][0]['details']['date']['value'] ?? null,
                        'Father' => $response_data['result'][0]['details']['father']['value'] ?? null,
                    ];
                case 'VKYC_GENERATE_URL_API':
                    if(isset($response_data['code'])) {
                        $text = 'VKYC url generated';
                        $status = 'Success';
                    } else {
                        $text = '';
                        $status = '';
                    }
                    return [
                        'Status' => $status,
                        'Message' => $text,
                    ];
                case 'VKYC_CALL_DETAILS_USING_UNIQUE_ID_API':
                    return [
                        'Call status' => $response_data['model']['callStatus'] ?? null,
                        'VKYC status' => $response_data['model']['vkycStatus'] ?? null,
                        'Call state' => $response_data['model']['state'] ?? null,
                        'Call status' => $response_data['model']['status'] ?? null,
                        'Call Initiated' => $response_data['model']['callInitiated'] ?? null,
                    ];
                case 'GENERATE_ESIGN_FILE_UPLOAD_URL':
                    if(isset($response_data['code']) && $response_data['code'] == 200) {
                        $message_text = "Esign file upload URL generated";
                    } else {
                        $message_text = "";
                    }
                    return [
                        'Doc Id' => $response_data['model']['docId'] ?? null,
                        'Message' => $message_text,
                    ];
                case 'GENERATE_ESIGN_URL':
                    if(isset($response_data['httpCode']) && $response_data['httpCode'] == 200) {
                        $message_text = "Esign URL generated";
                    } else {
                        $message_text = "";
                    }
                    return [
                        'Doc Id' => $response_data['docId'] ?? null,
                        'Message' => $message_text,
                        'E-sign Url' => $response_data['e_sign_url'] ?? null,
                    ];
                case 'DOWNLOAD_ESIGN_DOCUMENT':
                    return [
                        'Doc Id' => $response_data['model']['docId'] ?? null,
                        'Name' => $response_data['model']['signers'][0]['name'] ?? null,
                        'Gender' => $response_data['model']['signers'][0]['gender'] ?? null,
                        'Dob' => $response_data['model']['signers'][0]['dob'] ?? null,
                        'Email' => $response_data['model']['signers'][0]['email'] ?? null,
                        'Mobile' => $response_data['model']['signers'][0]['mobile'] ?? null,
                        'Aadhaar suffix' => $response_data['model']['signers'][0]['aadhaarSuffix'] ?? null,
                        'Location' => $response_data['model']['signers'][0]['location'] ?? null,
                        'Signer name' => $response_data['model']['signers'][0]['signerName'] ?? null,
                        'Signer state' => $response_data['model']['signers'][0]['signerState'] ?? null,
                        'Postal code' => $response_data['model']['signers'][0]['postalCode'] ?? null,
                        'Signed on' => $response_data['model']['signers'][0]['signedOn'] ?? null,
                        'State' => $response_data['model']['signers'][0]['state'] ?? null,
                    ];
                case 'VKYC_TRANSACTION_DETAILS_API':
                    $transaction_id = array_key_first($response_data['model']['transactionDetailInformation']);
                    $vkyc_details = $response_data['model']['transactionDetailInformation'][$transaction_id];
                    return [
                        'Transaction id' => $transaction_id,
                        'Agent name' => 
                            ($vkyc_details['agentTransactionInformation']['agentFname'] ?? '') . ' ' . 
                            ($vkyc_details['agentTransactionInformation']['agentLname'] ?? ''),
                        'Agent email' => $vkyc_details['agentTransactionInformation']['agentEmailId'] ?? null,
                        'User name' => 
                            ($vkyc_details['agentTransactionInformation']['userFname'] ?? '') . ' ' . 
                            ($vkyc_details['agentTransactionInformation']['userLname'] ?? ''),
                        'User longitude' => $vkyc_details['agentTransactionInformation']['userLongitude'] ?? null,
                        'User latitude' => $vkyc_details['agentTransactionInformation']['userLatitude'] ?? null,
                        'Detected address' => $vkyc_details['agentTransactionInformation']['detectedAddress'] ?? null,
                        'VKYC report link' => $vkyc_details['additionalImages']['VKYC_IMAGE'] ?? null,
                    ];

                default:
                    return $response_data;
            }
        } else {
            if (isset($response_data['msg'])) {
                return ['Message' => $response_data['msg']];
            } elseif (isset($response_data['error']['message'])) {
                return ['Message' => $response_data['error']['message']];
            } elseif (isset($response_data['error'])) {
                return ['Message' => $response_data['error']];
            } else {
                return null;
            }
        }
    }

    public function get_borrower_list() {
        // $borrowers = LoanBasicDetails::select([
        //                 'loan_basic_details.id',
        //                 'loan_basic_details.borrower_id',
        //                 'borrower_kycs.name'                
        //                 ])->join('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id')
        //                 ->orderBy('loan_basic_details.id', 'desc')->get();
        $borrowers = LoanBasicDetails::select([
                        'loan_basic_details.id',
                        'loan_basic_details.borrower_id',
                        'loan_basic_details.user_id',
                        DB::raw("COALESCE(borrower_kycs.name, users.name) AS name") // Use borrower_kycs.name, fallback to users.name
                    ])
                    ->leftJoin('borrower_kycs', 'loan_basic_details.id', '=', 'borrower_kycs.borrower_id') // Left join with borrower_kycs
                    ->leftJoin('users', 'loan_basic_details.user_id', '=', 'users.id') // Left join with users
                    ->whereRaw("COALESCE(borrower_kycs.name, users.name) IS NOT NULL") // Skip records without any name
                    ->orderBy('loan_basic_details.id', 'desc')
                    ->get();
        return $this->helper_controller->send_response('success', 'Borrower list retrived', 200, $borrowers);
    }

    public function changeLoanStatus(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|regex:/^[a-zA-Z0-9\-]+$/|exists:loan_basic_details,borrower_id',
                'status' => 'required|in:NEW,REJECTED,CANCELLED',
                'admin_remarks' => 'nullable|max:300|regex:/^[a-zA-Z0-9\s,\-.]+$/',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();
            // Retrieve the loan record
            $loan = LoanBasicDetails::where('borrower_id', $request->borrower_id)->first();

            if (!$loan) {
                return $this->helper_controller->send_response('failed', 'Loan not found.', 422);
            }

            // Update the loan status and admin remarks
            if($loan->status === 'REJECTED') {
                $loan->manual_override = true;
                $loan->manual_override_at = now();
                $loan->manual_override_by = auth()->user()->id;
                $loan->manual_override_reason = $request->admin_remarks;
            }
            $loan->status = $request->status;
            $loan->admin_remarks = $request->admin_remarks;
            $loan->remarks_added_by = auth()->user()->id; // Logged-in user ID
            $loan->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Loan status updated successfully.', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_applicant_address_data(Request $request) {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'user_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $api_detail = VendorServiceApi::where('borrower_id', $request->borrower_id)
                            ->where('applicant_type', $request->user_type)
                            ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                            ->latest()->first();
            if(!$api_detail) {
                return $this->helper_controller->send_response('failed', "Aadhar OCR not done yet", 422);
            }

            $response   = $api_detail->response;
            $response   = base64_decode($response);
            $response   = unserialize($response);
            $data       = json_decode($response, true);

            $address = $data['result'][0]['details']['address'];
            $parts = [
                $address['house_number'] ?? '',
                $address['street'] ?? '',
                $address['locality'] ?? '',
                $address['landmark'] ?? '',
                $address['city'] ?? '',
                $address['district'] ?? '',
                $address['state'] ?? '',
                $address['pin'] ?? ''
            ];

            // Filter out empty parts and trim each part
            $filteredParts = array_filter(array_map('trim', $parts));

            // Join with comma and return
            $single_line_address = implode(', ', $filteredParts);

            // $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($address);
            
            $name = $mobile = $email = '';
            if($request->user_type == "BORROWER") {
                $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($borrower_details) {
                    $name = $borrower_details->name;
                    $mobile = $borrower_details->mobile;
                    $email = $borrower_details->email;
                }      
            } elseif($request->user_type == "COAPPLICANT") {
                $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($coapplicant_details) {
                    $name = $coapplicant_details->name;
                    $mobile = $coapplicant_details->mobile;
                    $email = $coapplicant_details->email;
                }
            } elseif($request->user_type == "GUARANTOR") {
                $guarantor_details = Guarantor::where('borrower_id', $request->borrower_id)->latest()->first();
                if($guarantor_details) {
                    $name = $guarantor_details->name;
                    $mobile = $guarantor_details->mobile;
                    $email = $guarantor_details->email;

                }
            }

            $result_data = [
                'borrower_id' => $request->borrower_id,
                'user_type' => $request->user_type,
                'name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'single_line_address' => $single_line_address,
                'full_address' => $address
            ];

            return $this->helper_controller->send_response('success', "Address details fetched", 200, $result_data);
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_user_details_for_esign(Request $request) {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'borrower_id' => 'required|exists:loan_basic_details,id',
                'user_type' => 'required|in:BORROWER,COAPPLICANT,GUARANTOR',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $api_detail = VendorServiceApi::where('borrower_id', $request->borrower_id)
                            ->where('applicant_type', $request->user_type)
                            ->where('api_flag', 'AADHAR_CARD_OCR_BACK')
                            ->first();
            if(!$api_detail) {
                return $this->helper_controller->send_response('failed', "Aadhar OCR not done yet", 422);
            }

            $response   = $api_detail->response;
            $response   = base64_decode($response);
            $response   = unserialize($response);
            $data       = json_decode($response, true);

            $address = $data['result'][0]['details']['address'];

            $parts = [
                $address['house_number'] ?? '',
                $address['street'] ?? '',
                $address['locality'] ?? '',
                $address['landmark'] ?? '',
                $address['city'] ?? '',
                $address['district'] ?? '',
                $address['state'] ?? '',
                $address['pin'] ?? ''
            ];
            
            // Filter out empty parts and trim each part
            $filteredParts = array_filter(array_map('trim', $parts));

            // Join with comma and return
            $single_line_address = implode(', ', $filteredParts);

            // $single_line_address = $this->helper_controller->prepare_one_line_address_from_aadhar($address);
            
            $name = $mobile = $email = '';
            if($request->user_type == "BORROWER") {
                $borrower_details = BorrowerKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($borrower_details) {
                    $name = $borrower_details->name;
                    $mobile = $borrower_details->mobile;
                    $email = $borrower_details->email;
                }      
            } elseif($request->user_type == "COAPPLICANT") {
                $coapplicant_details = CoapplicantKyc::where('borrower_id', $request->borrower_id)->latest()->first();
                if($coapplicant_details) {
                    $name = $coapplicant_details->name;
                    $mobile = $coapplicant_details->mobile;
                    $email = $coapplicant_details->email;
                }
            } elseif($request->user_type == "GUARANTOR") {
                $guarantor_details = Guarantor::where('borrower_id', $request->borrower_id)->latest()->first();
                if($guarantor_details) {
                    $name = $guarantor_details->name;
                    $mobile = $guarantor_details->mobile;
                    $email = $guarantor_details->email;

                }
            }

            $get_esign_url = VendorServiceApi::where('borrower_id', $request->borrower_id)
                ->where('api_flag', 'GENERATE_ESIGN_URL')
                ->where('applicant_type', $request->user_type)
                ->where('status', 'VALID')
                ->latest()
                ->first();

            $city = $address['district'];
            $result = [
                'name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'city' => $city,
            ];
            
            if($get_esign_url) {
                $esignResponse = $get_esign_url->response;
                // decode JSON
                $esignData = json_decode($esignResponse, true);
                if($esignData['e_sign_url'] !== '' && $esignData['e_sign_url'] !== NULL) {
                    $result['e_sign_url'] = $esignData['e_sign_url'];
                }
            }

            return $this->helper_controller->send_response('success', "Address details fetched", 200, $result);
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    // public function verify_meter_image(Request $request)
    // {
    //     try {
    //         $validator = Validator::make($request->all(), [
    //             'borrower_id'   => 'required|integer|exists:loan_basic_details,id',
    //             'action'  => 'required|in:approve,reject',
    //             'remarks' => 'required_if:action,reject|nullable|string|max:500',
    //         ]);

    //         if ($validator->fails()) {
    //             return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //         }

    //         $fi_details = FieldInspectionDetails::where('borrower_id', $request->borrower_id)->latest()->first();

    //         if ($fi_details->meter_verification_status !== 'pending') {
    //             return $this->helper_controller->send_response('failed', 'No meter image pending verification.', 409);
    //         }

    //         DB::beginTransaction();

    //         $approved = $request->action === 'approve';
    //         $fi_details->meter_verification_status = $approved ? 'approved' : 'rejected';
    //         $fi_details->meter_remarks             = $request->remarks;
    //         $fi_details->meter_verified_by         = auth()->id();
    //         $fi_details->meter_verified_at         = now();

    //         if (!$approved) {
    //             $fi_details->meter_image = null; // force recapture
    //         }

    //         $fi_details->save();

    //         DB::commit();

    //         return $this->helper_controller->send_response('success', 'Meter image ' . $fi_details->meter_verification_status . '.', 200, [
    //             // 'fi_id'                     => $fi_details->id,
    //             'meter_verification_status' => $fi_details->meter_verification_status,
    //         ]);
    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         return $this->helper_controller->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
    //     }
    // }
    
    public function verify_meter_image(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'party_uid'   => 'required_without_all:borrower_id,party_type|string|size:24',
                'borrower_id' => 'required_without:party_uid|integer|exists:loan_basic_details,id',
                'party_type'  => 'required_without:party_uid|in:borrower,co_applicant,guarantor',
                'action'      => 'required|in:approve,reject',
                'remarks'     => 'required_if:action,reject|nullable|string|max:500',
            ]);
            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            DB::beginTransaction();

            $party = FieldInspectionParty::query()
                ->when($request->filled('party_uid'),
                    fn ($q) => $q->where('party_uid', $request->party_uid),
                    fn ($q) => $q->where('borrower_id', $request->borrower_id)
                                 ->where('party_type', $request->party_type))
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$party) {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'Field inspection party not found.', 404);
            }

            if ($party->meter_verification_status !== 'pending') {
                DB::rollBack();
                return $this->helper_controller->send_response('failed', 'No meter image pending verification.', 409);
            }

            $approved = $request->action === 'approve';

            $party->forceFill([
                'meter_verification_status' => $approved ? 'approved' : 'rejected',
                'meter_remarks'             => $request->remarks,
                'meter_verified_by'         => auth()->id(),
                'meter_verified_at'         => now(),
                'meter_image'               => $approved ? $party->meter_image : null, // force recapture
            ])->save();

            DB::commit();

            return $this->helper_controller->send_response('success', 'Meter image ' . $party->meter_verification_status . '.', 200, [
                'party_uid'                 => $party->party_uid,
                'party_type'                => $party->party_type,
                'name'                      => $party->name,
                'meter_verification_status' => $party->meter_verification_status,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unknown error occurred: ' . $e->getMessage(), 422);
        }
    }
}
