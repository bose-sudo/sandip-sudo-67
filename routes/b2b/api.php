<?php
// b2b
use App\Http\Controllers\AccountAggregatorController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\Api\V1\AppCommonController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\B2B\Api\V1\B2bDashboardController;
use App\Http\Controllers\B2B\Api\V1\BorrowerController;
use App\Http\Controllers\B2B\Api\V1\LoanController;
use App\Http\Controllers\B2B\Api\V1\ServiceApiController;
use App\Http\Controllers\Api\V2\UatServiceApiController;
use App\Http\Controllers\Api\V2\UatB2bAuthController;
use App\Http\Controllers\AppSupportController;
use App\Http\Controllers\DealerShopsController;
use App\Http\Controllers\EasebuzzPaymentController;
use App\Http\Controllers\LspExecutiveDealerGroupingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\HelperController;
use Illuminate\Support\Facades\Route;

Route::fallback(function(){
    return response()->json([
        'error' => 'Route not found.',
        'message' => 'Please check your request URL.',
    ], 404);
});

Route::post('/send-otp', [AdminAuthController::class, 'b2b_send_otp']);
Route::post('/login', [AdminAuthController::class, 'login_with_otp_b2b'])->name('login');

Route::group([
    'middleware'=>'api'
], function(){
    Route::middleware('auth:lsp_executive')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'b2b_logout'])->middleware('auth:lsp_executive');
        Route::get('user', [AdminAuthController::class, 'b2b_user'])->middleware('auth:lsp_executive');
        Route::get('/verify-token', [AdminAuthController::class, 'verify_token']);
    });
});

Route::post('/get-onboarding-data', [AppCommonController::class, 'get_onboarding_data']);
Route::post('/store-app-permissions', [AppCommonController::class, 'store_app_permission']);
Route::get('/get-app-permission-status', [AppCommonController::class, 'get_permission_status']);
Route::get('/get-languages', [AppCommonController::class, 'get_languages']);
Route::get('/get-genders', [AppCommonController::class, 'get_genders']);
Route::get('/get-resident-types', [AppCommonController::class, 'get_resident_type']);
Route::get('/get-occupations', [AppCommonController::class, 'get_occupations']);
Route::get('/get-dealers', [AppCommonController::class, 'get_dealer_ids']);
Route::get('/get-marital-status', [AppCommonController::class, 'get_marital_status']);
Route::get('/get-vehicle-types', [AppCommonController::class, 'get_vehicle_types']);
Route::get('/get-years-living', [AppCommonController::class, 'years_living']);
Route::post('/get-loan-terms', [LoanController::class, 'loan_terms']);
Route::get('/get-relations', [AppCommonController::class, 'get_relation_ship']);
Route::get('/get-relations/{gender}', [AppCommonController::class, 'get_relation_ship']);
Route::get('/get-emi-debit-consent-data', [AppCommonController::class, 'emi_debit_consent_content']);
Route::get('/get-document-types', [AppCommonController::class, 'other_doc_types']);
Route::get('/get-bureau-consent-data', [AppCommonController::class, 'get_bureau_consent_data']);
Route::post('/check-mobile-number-exist', [UserController::class, 'check_if_mobile_number_exist']);
Route::post('/check-email-id-exist', [UserController::class, 'check_if_email_id_exist']);
Route::post('/get-product-list', [ProductController::class, 'get_product_list']);
Route::get('/get-professions', [HelperController::class, 'get_professions']);
Route::get('get-ifsc-info/{ifsc}', [HelperController::class, 'get_ifsc_information']);


Route::post('/get-sub-product-list', [ProductController::class, 'get_sub_products']);


Route::group([
    'middleware'=>'api', 'prefix'=>'v1'
], function(){
    // Route::get('/custom', function () {
    //     return 'This is a custom route!';
    // });


    Route::middleware('auth:lsp_executive')->group(function () {
        // Route::post('/borrower-registration', [BorrowerController::class, 'borrower_registration']);
        // Route::post('/verify-borrower-registration-otp', [BorrowerController::class, 'verify_borrower_registration_otp']);
        Route::post('/send-borrower-otp', [BorrowerController::class, 'send_borrower_otp']);
        Route::post('/verify-borrower-otp', [BorrowerController::class, 'verify_borrower_otp']);

        Route::post('/get-loan-status', [LoanController::class, 'get_loan_status']);
        Route::post('/reset-loan-application', [LoanController::class, 'reset_loan_application']);
        Route::post('/store-basic-details', [LoanController::class, 'storeLoanBasicDetails']);
        Route::post('/store-basic-details-with-inverter', [LoanController::class, 'store_loan_basic_details_for_inverter']);
        Route::post('/store-borrower-details', [LoanController::class, 'storeBorrowerDetails']);
        Route::post('/store-borrower-family-details', [LoanController::class, 'storeBorrowerFamilyDetails']);
        Route::post('/store-borrower-occupation-details', [LoanController::class, 'storeBorrowerOccupation']);
        Route::post('/store-coapplicant-details', [LoanController::class, 'storeCoApplicantDetails']);
        Route::post('/store-coapplicant-occupation-details', [LoanController::class, 'storeCoApplicantOccupation']);
        Route::post('/store-guarantor-details', [LoanController::class, 'storeGuarantorDetails']);
        Route::post('/store-guarantor-occupation-details', [LoanController::class, 'storeGuarantorOccupation']);
        
        // Route::post('/get-models', [LoanController::class, 'verify_dealer_id']);
        Route::post('/get-models', [ProductController::class, 'get_product_models']);
        Route::get('/get-inverter-models', [ProductController::class, 'get_inverter_product_models']);
        
        Route::post('/lender-consent', [LoanController::class, 'lender_consent_acceptance']);
        // Route::post('/store-reference-details', [LoanController::class, 'store_reference_details']);
        // Route::post('/store-field-verification-details', [LoanController::class, 'store_field_verification_details']);
        Route::post('/upload-document', [LoanController::class, 'upload_image']);
        Route::post('/get-reference-list', [LoanController::class, 'get_reference_list']);
        Route::post('/run-rule-engine', [LoanController::class, 'rule_engine']);
        Route::post('/upload-borrower-document', [LoanController::class, 'borrower_doc_upload']);
        Route::post('/upload-borrower-battery-document', [LoanController::class, 'borrower_doc_upload_battery']);
        Route::post('/upload-coapplicant-document', [LoanController::class, 'co_applicant_doc_upload']);
        Route::post('/upload-guarantor-document', [LoanController::class, 'guarantor_doc_upload']);
        Route::post('/store-emi-debit-consent', [LoanController::class, 'emi_debit_consent']);
        Route::post('/upload-vehicle-document', [LoanController::class, 'vehicle_doc_upload']);
        Route::post('/upload-battery-document', [LoanController::class, 'battery_doc_upload']);
        Route::post('/check-existing-application', [LoanController::class, 'get_existing_application']);
        Route::post('/field-verification', [LoanController::class, 'field_verification']);
        Route::post('/get-field-verification-status', [LoanController::class, 'get_field_verification_status']);
        Route::post('/get-dealer-assets-details', [LoanController::class, 'get_dealer_details_and_models']);
        Route::post('/check-vkyc-status', [LoanController::class, 'check_vkyc_result']);
        Route::post('/match-address-with-borrower', [LoanController::class, 'match_address_with_borrower']);
        // Route::post('/get-user-details-for-esign', [LoanController::class, 'get_loan_user_details_for_esign']);
        Route::post('/get-user-details-for-esign', [BorrowerController::class, 'get_user_details_for_esign']);
        Route::post('/skip-co-applicant', [LoanController::class, 'skip_co_applicant']);
        Route::post('/skip-guarantor', [LoanController::class, 'skip_guarantor']);
        Route::post('/skip-iot-step', [LoanController::class, 'skip_iot_step']);
        // Route::post('/get-user-address-details', [LoanController::class, 'user_address_details']);

        Route::post('/get-user-address-details', [BorrowerController::class, 'get_applicant_address_data']);
        Route::post('/get-borrower-details', [LoanController::class, 'get_borrower_details']);
        Route::post('/get-name-declaration', [LoanController::class, 'get_name_declaration_document']);
        // Route::post('/verify-reference', [LoanController::class, 'verify_reference']);
        
        // service api
        // Route::post('/verify-aadhar', [ServiceApiController::class, 'aadhar_verify']);
        // Route::post('/verify-aadhar-resend-otp', [ServiceApiController::class, 'verify_aadhar_resend_otp']);
        // Route::post('/verify-aadhar-using-otp', [ServiceApiController::class, 'verify_aadhar_using_otp']);
        // Route::post('/verify-pan', [ServiceApiController::class, 'pan_verification']);
        // // Route::post('/get-credit-score', [ServiceApiController::class, 'get_credit_score_experian']);
        // Route::post('/pan-ocr-check', [ServiceApiController::class, 'pan_ocr_api']);
        // Route::post('/aadhar-ocr-check', [ServiceApiController::class, 'aadhar_ocr_api']);
        // Route::post('/cheque-ocr-check', [ServiceApiController::class, 'cheque_ocr']);
        // Route::post('/verify-driving-licence', [ServiceApiController::class, 'verify_driving_licence']);
        // Route::post('/check-iot-activation', [ServiceApiController::class, 'iot_activition_check']);
        // Route::post('/generate-vkyc-url', [ServiceApiController::class, 'video_kyc_generate_url']);
        // Route::post('/vkyc-transaction-details', [ServiceApiController::class, 'vkyc_transaction_details']);
        // Route::post('/generate-esign', [ServiceApiController::class, 'generate_esign']);
        // Route::post('/get-vkyc-call-details', [ServiceApiController::class, 'get_vkyc_status_by_application_number']);
        // Route::post('/generate-esign-url', [ServiceApiController::class, 'generate_esign']);
        // Route::post('/download-esigned-doc', [ServiceApiController::class, 'download_esign_doc']);
        // Route::post('/get-credit-score-details', [ServiceApiController::class, 'get_credit_score_details']);
        // Route::post('/verify-pan-details', [ServiceApiController::class, 'pan_details_verification']);
        
        Route::get('/get-borrower-list', [B2bDashboardController::class, 'get_borrower_list']);
        Route::get('/get-dashboard-counts', [B2bDashboardController::class, 'getDashboardDetails']);
        Route::get('/get-year-to-date-counts', [B2bDashboardController::class, 'getYearlyCount']);
        Route::get('/get-loan-list', [B2bDashboardController::class, 'get_loan_list']);
        
        Route::get('check-application-with-aadhar/{aadhar}', [HelperController::class, 'check_if_aadhar_exist']);
        Route::get('check-application-with-pan/{pan}', [HelperController::class, 'check_if_pan_exist']);
        
        Route::get('/get-borrower-registration-details/{borrower_id}', [LoanController::class, 'borrower_registration_details']);
        Route::get('/get-dealer-screener-by-executive', [LspExecutiveDealerGroupingController::class, 'b2b_dealer_screener_by_executive']);
        
        Route::get('/get-borrower-loan-details/{borrower_id}', [BorrowerController::class, 'get_borrower_loan_details']);
        Route::post('/delete-loan-steps', [HelperController::class, 'delete_loan_steps']);

        // app support
        Route::get('/get-support-subject-screener', [AppSupportController::class, 'get_support_subject_screener']);
        Route::post('/store-support-query', [AppSupportController::class, 'store']);

        // executive borrower screener
        Route::get('/executive-borrower-screener', [BorrowerController::class, 'get_borrower_list_for_executive']);

        // 23-01-2025
        Route::get('/get-emi-debit-consent-data/{borrower_id}', [HelperController::class, 'get_emi_debit_consent_data']);
        // 08-02-2025
        Route::post('/store-api-data', [EasebuzzPaymentController::class, 'store_api_request_response']);
        // 18-02-2025
        Route::post('/initiate-emi-payment', [EasebuzzPaymentController::class, 'initiate_payment']);

        // 19-02-2025
        Route::post('/store-easebuzz-payment-response', [EasebuzzPaymentController::class, 'store_easebuzz_payment_response']);

        // 26-02-2025
        Route::get('/get-dealer-shops-fi/{borrower_id}', [DealerShopsController::class, 'get_dealer_shop_by_borrower_id']);

        // 25-03-2025
        Route::get('/get-fips', [AccountAggregatorController::class, 'get_fips']);
        Route::post('/initiate-account-aggregator-request', [AccountAggregatorController::class, 'initiate_request_b2b']);
        // 03-04-2025
        Route::get('/get-emi-payment-receipt/{receipt_no}', [EasebuzzPaymentController::class, 'get_emi_receipt']);
    
        Route::get('/get-name-declaration-doc', [HelperController::class, 'get_name_declaration_doc']);
        Route::get('/get-dob-declaration', [HelperController::class, 'get_dob_declaration']);

        // 09-06-2025
        Route::post('/initiate-post-enach-request', [EasebuzzPaymentController::class, 'create_enach_mandate']);
        Route::get('/get-enach-details', [EasebuzzPaymentController::class, 'get_borrower_account_enach_details']);
        Route::post('/skip-enach-step', [LoanController::class, 'skip_nach_step']);
        Route::post('/store-enach-registration-details', [LoanController::class, 'store_nach_registration_details']);
        Route::get('/check-aa-status', [AccountAggregatorController::class, 'check_aa_status_b2c']);

        Route::get('/get-state-screener', [StateController::class, 'state_screener']); // List all states

    });
});

Route::middleware('auth:lsp_executive')->prefix('v2')->group(function () {
    // UAT service api    
    // For B2B dummy cases
    Route::post('/borrower-registration', [UatB2bAuthController::class, 'borrower_registration']);
    Route::post('/verify-borrower-registration-otp', [UatB2bAuthController::class, 'verify_borrower_registration_otp']);
    Route::post('/send-borrower-otp', [UatB2bAuthController::class, 'send_borrower_otp']);
    Route::post('/verify-borrower-otp', [UatB2bAuthController::class, 'verify_borrower_otp']);
    // Route::post('/send-otp', [UatAuthController::class, 'sendOtp']);
    // Route::post('/login', [UatAuthController::class, 'login']);

    Route::post('/verify-aadhar', [UatServiceApiController::class, 'aadhar_verify']);
    Route::post('/store-reference-details', [LoanController::class, 'store_reference_details_b2b_uat']);
    Route::post('/store-field-verification-details', [LoanController::class, 'store_field_verification_details_b2b_uat']);
    Route::post('/verify-aadhar-using-otp', [UatServiceApiController::class, 'verify_aadhar_using_otp']);
    Route::post('/get-credit-score-details', [UatServiceApiController::class, 'get_credit_score_details']);
    Route::post('/verify-pan-details', [UatServiceApiController::class, 'pan_details_verification']);
    Route::post('/pan-ocr-check', [UatServiceApiController::class, 'pan_ocr_api']);
    Route::post('/aadhar-ocr-check', [UatServiceApiController::class, 'aadhar_ocr_api']);
    Route::post('/cheque-ocr-check', [UatServiceApiController::class, 'cheque_ocr']);  
    Route::post('/verify-driving-licence', [UatServiceApiController::class, 'verify_driving_licence']);
    
    Route::post('/generate-vkyc-url', [UatServiceApiController::class, 'video_kyc_generate_url']);
    // Route::post('/vkyc-transaction-details', [UatServiceApiController::class, 'vkyc_transaction_details']);
    Route::post('/get-vkyc-call-details', [UatServiceApiController::class, 'get_vkyc_status_by_application_number']);
    Route::post('/generate-esign', [UatServiceApiController::class, 'generate_esign']);
    Route::post('/download-esigned-doc', [UatServiceApiController::class, 'download_esign_doc']);
    Route::post('/check-iot-activation', [UatServiceApiController::class, 'iot_activition_check']);

    Route::prefix('digilocker')->group(function () {
        Route::post('generate-url', [ServiceApiController::class, 'digilocker_generate_url']);
        Route::post('status',       [ServiceApiController::class, 'digilocker_status']);
        Route::post('details',      [ServiceApiController::class, 'digilocker_details']);
    });
});


Route::middleware('auth:lsp_executive')->prefix('v1')->group(function () {
    // UAT service api    
    // For B2C dummy cases
    Route::post('/borrower-registration', [UatB2bAuthController::class, 'borrower_registration']);
    Route::post('/verify-borrower-registration-otp', [UatB2bAuthController::class, 'verify_borrower_registration_otp']);
    Route::post('/send-borrower-otp', [UatB2bAuthController::class, 'send_borrower_otp']);
    Route::post('/verify-borrower-otp', [UatB2bAuthController::class, 'verify_borrower_otp']);
    // Route::post('/send-otp', [UatAuthController::class, 'sendOtp']);
    // Route::post('/login', [UatAuthController::class, 'login']);

    // Route::post('/verify-aadhar', [UatServiceApiController::class, 'aadhar_verify']);
    Route::post('/store-reference-details', [LoanController::class, 'store_reference_details_b2b_uat']);
    Route::post('/store-field-verification-details', [LoanController::class, 'store_field_verification_details_b2b_uat']);
    Route::post('/verify-aadhar-using-otp', [UatServiceApiController::class, 'verify_aadhar_using_otp']);
    Route::post('/get-credit-score-details', [UatServiceApiController::class, 'get_credit_score_details']);
    Route::post('/verify-pan-details', [UatServiceApiController::class, 'pan_details_verification']);
    Route::post('/pan-ocr-check', [UatServiceApiController::class, 'pan_ocr_api']);
    Route::post('/aadhar-ocr-check', [UatServiceApiController::class, 'aadhar_ocr_api']);
    Route::post('/cheque-ocr-check', [UatServiceApiController::class, 'cheque_ocr']);  
    Route::post('/verify-driving-licence', [UatServiceApiController::class, 'verify_driving_licence']);
    
    Route::post('/generate-vkyc-url', [UatServiceApiController::class, 'video_kyc_generate_url']);
    // Route::post('/vkyc-transaction-details', [UatServiceApiController::class, 'vkyc_transaction_details']);
    Route::post('/get-vkyc-call-details', [UatServiceApiController::class, 'get_vkyc_status_by_application_number']);
    Route::post('/generate-esign', [UatServiceApiController::class, 'generate_esign']);
    Route::post('/download-esigned-doc', [UatServiceApiController::class, 'download_esign_doc']);
    Route::post('/check-iot-activation', [UatServiceApiController::class, 'iot_activition_check']);

    Route::prefix('digilocker')->group(function () {
        Route::post('generate-url', [ServiceApiController::class, 'digilocker_generate_url']);
        Route::post('status',       [ServiceApiController::class, 'digilocker_status']);
        Route::post('details',      [ServiceApiController::class, 'digilocker_details']);
    });
});