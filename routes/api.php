<?php
// UAT
use App\Http\Controllers\AccessRoleController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\Api\V1\AdminLoanController;
use App\Http\Controllers\Api\V1\AdminServiceApiController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AppCommonController;
use App\Http\Controllers\Api\V1\BorrowerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FieldInspectionController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LoanUsersController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ServiceApiController;
use App\Http\Controllers\Api\V2\B2cUatServiceApiController;
use App\Http\Controllers\Api\V2\UatAuthController;
use App\Http\Controllers\Api\V2\UatServiceApiController;
use App\Http\Controllers\Api\V2\UatUserController;
use App\Http\Controllers\AccountAggregatorController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AppSupportController;
use App\Http\Controllers\BatteryTypesController;
use App\Http\Controllers\BeneficiaryDetailsController;
use App\Http\Controllers\BlockAccessController;
use App\Http\Controllers\BorrowerAccountDetailsController;
use App\Http\Controllers\BorrowerChequeController;
use App\Http\Controllers\BorrowerIdentifierUploadController;
use App\Http\Controllers\BranchLocationController;
use App\Http\Controllers\CreditDetailsConsentController;
use App\Http\Controllers\DealersController;
use App\Http\Controllers\DealerDesignatedAuthorityController;
use App\Http\Controllers\DealerShopsController;
use App\Http\Controllers\DisbursementController;
use App\Http\Controllers\DisbursementDetailsController;
use App\Http\Controllers\EasebuzzPaymentController;
use App\Http\Controllers\EmiDebitConsentController;
use App\Http\Controllers\EmiHelperController;
use App\Http\Controllers\HelperController;
use App\Http\Controllers\ImpactDashboardController;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\LanguagesController;
use App\Http\Controllers\LenderController;
use App\Http\Controllers\LenderDirectorController;
use App\Http\Controllers\LenderDealerGroupController;
use App\Http\Controllers\LenderBorrowerGroupController;
use App\Http\Controllers\LenderLoanSettingController;
use App\Http\Controllers\LenderServiceProviderController;
use App\Http\Controllers\LoanAmountRulesController;
use App\Http\Controllers\LoanServiceProviderController;
use App\Http\Controllers\LoanStepBackRequestController;
use App\Http\Controllers\LspDirectorsController;
use App\Http\Controllers\LspExecutiveController;
use App\Http\Controllers\LspExecutiveDealerGroupingController;
use App\Http\Controllers\MakerCheckerAuthorizationController;
use App\Http\Controllers\OemController;
use App\Http\Controllers\OemProductController;
use App\Http\Controllers\OemProductSpecificationController;
use App\Http\Controllers\OfflineTransactionController;
use App\Http\Controllers\ManufacturersController;
use App\Http\Controllers\MaritalStatusesController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OccupationTypesController;
use App\Http\Controllers\OnboardingScreenContentController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProductTypesController;
use App\Http\Controllers\RelationsController;
use App\Http\Controllers\ResidentTypesController;
use App\Http\Controllers\SalesExecutiveDealerController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\SubMenuItemController;
use App\Http\Controllers\SubventionDetailsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserDepartmentController;
use App\Http\Controllers\UserTypeController;
use App\Http\Controllers\VehicleTypesController;
use App\Http\Controllers\WebResponseController;
use App\Http\Controllers\CibilReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// action control list
use App\Acl\Http\Controllers\Api\SidebarMenuController;
use App\Acl\Http\Controllers\Api\PermissionListController;
use App\Acl\Http\Controllers\Api\RolePermissionController;
use App\Acl\Http\Controllers\Api\UserPermissionController;


use App\Http\Controllers\UserHierarchyController;
use App\Http\Controllers\DealerMappingController;
use App\Http\Controllers\FullAccessController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\LoanMasterExportController;

use App\Http\Controllers\EmiPresentmentController;
use App\Http\Controllers\DebitRequestController;
use App\Http\Controllers\SubventionUploadController;
use App\Http\Controllers\AccountAggregatorEntityController;
use App\Http\Controllers\PaymentHeadController;
use App\Http\Controllers\PaymentHeadGroupController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\FileStreamController;
use App\Http\Controllers\LoanApplicationReportController;

// reports
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\DealerReportController;
use App\Http\Controllers\PaymentSummaryController;

// Dynamic Product Controller
use App\Http\Controllers\Product\DynamicProductController;
use App\Http\Controllers\Product\DealerProductController;
use App\Http\Controllers\Product\OemProductExcelController;

// Delivery Order
use App\Http\Controllers\DeliveryOrderController;
use App\Http\Controllers\ExcelUploadController;

use App\Http\Controllers\BorrowerEnachMethodController;
use App\Http\Controllers\BorrowerEditRequestController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/files/stream', [FileStreamController::class, 'show'])
    ->name('files.stream')
    ->middleware('signed');

Route::get('/risk-profiles', [ImpactDashboardController::class, 'riskReport']);
Route::get('/store-manual-loan-scheme', [EmiHelperController::class, 'store_manual_loan_scheme']);
Route::post('/initiate-payment', [HelperController::class, 'easebuzz_initiate_payment_api']);
Route::get('/store-emi-chart/{borrower_id}', [EmiHelperController::class, 'storeSampleEmiSchedule']);
Route::get('/get-emi-chart', [EmiHelperController::class, 'getEmiSchedule']);
// Route::post('/initiate-pre-enach-request', [EasebuzzPaymentController::class, 'create_pre_enach_mandate']);
Route::post('/initiate-post-enach-request-prod', [EasebuzzPaymentController::class, 'create_enach_mandate_prod']);
// Route::get('/get-mandate-list', [EasebuzzPaymentController::class, 'get_mandate_list']);
Route::get('/download-scheduler-report/{schedule_id}', [EasebuzzPaymentController::class, 'download_scheduler_report']);
Route::get('/retrive-mandate/{transaction_id}', [EasebuzzPaymentController::class, 'retrieve_mandate']);
// Route::post('/initiate-enach-debit-request', [EasebuzzPaymentController::class, 'initiate_debit_request']);
// Route::get('/import-npci-bank-list', [EasebuzzPaymentController::class, 'import_npci_bank_list']);
// Route::get('/update-bank-data', [EasebuzzPaymentController::class, 'update_borrower_bank_code']);

Route::fallback(function(){
    return response()->json([
        'error' => 'Route not found.',
        'message' => 'Please check your request URL.',
    ], 404);
});

Route::post('/enach/mandate/success', [WebResponseController::class, 'enach_successUrl']);
Route::post('/enach/mandate/failed', [WebResponseController::class, 'enach_failureUrl']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/test', [UserController::class, 'test']);
Route::post('/store-vkyc-data', [ServiceApiController::class, 'store_vkyc_data']);

Route::get('/api-testing', function(){
    return "Check api testing, and man, it working great";
});

// Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-registration-otp', [AuthController::class, 'verifyRegistrationMobileOtp']);
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
// Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('register/step1', [AuthController::class, 'registerStep1']);
Route::post('register/step2', [AuthController::class, 'registerStep2']);
Route::post('register/step3', [AuthController::class, 'registerStep3']);
Route::post('register/step4', [AuthController::class, 'registerStep4']);

Route::post('/get-onboarding-data', [AppCommonController::class, 'get_onboarding_data']);
Route::post('/store-app-permissions', [AppCommonController::class, 'store_app_permission']);
Route::post('/get-app-permission-status', [AppCommonController::class, 'get_permission_status']);
Route::post('/check-mobile-number-exist', [UserController::class, 'check_if_mobile_number_exist']);
Route::post('/check-email-id-exist', [UserController::class, 'check_if_email_id_exist']);
Route::match(['get', 'post'], '/get-product-list', [ProductController::class, 'get_product_list']);
Route::post('/get-sub-product-list', [ProductController::class, 'get_sub_products']);

Route::get('/get-loan-status-list', [DashboardController::class, 'get_loan_status_list']);
Route::get('/get-languages', [AppCommonController::class, 'get_languages']);
Route::get('/get-genders', [AppCommonController::class, 'get_genders']);
Route::get('/get-resident-types', [AppCommonController::class, 'get_resident_type']);
Route::get('/get-occupations', [AppCommonController::class, 'get_occupations']);
Route::get('/get-professions', [HelperController::class, 'get_professions']);
Route::get('/get-dealers', [AppCommonController::class, 'get_dealer_ids']);
Route::get('/get-marital-status', [AppCommonController::class, 'get_marital_status']);
Route::get('/get-vehicle-types', [AppCommonController::class, 'get_vehicle_types']);
Route::get('/get-years-living', [AppCommonController::class, 'years_living']);
Route::get('/get-loan-offers', [AppCommonController::class, 'demo_loan_offers']);
Route::post('/get-loan-terms', [LoanController::class, 'loan_terms']);
Route::get('/get-relations', [AppCommonController::class, 'get_relation_ship']);
Route::get('/get-relations/{gender}', [AppCommonController::class, 'get_relation_ship']);
Route::get('/get-emi-debit-consent-data', [AppCommonController::class, 'emi_debit_consent_content']);
Route::get('/get-document-types', [AppCommonController::class, 'other_doc_types']);
Route::get('/get-bureau-consent-data', [AppCommonController::class, 'get_bureau_consent_data']);
Route::get('/get-latest-apk-details', [AppCommonController::class, 'get_apk_details']);
Route::post('/upload-apk', [AppCommonController::class, 'upload_apk']);
// Route::post('/verify-token', [AuthController::class, 'verify_token']);

// Dashboard apis starts//
Route::get('/get-dashboard-details', [DashboardController::class, 'getDashboardDetails']);
Route::get('/get-yearly-count', [DashboardController::class, 'getYearlyCount']);
Route::get('/get-dealers-list', [DashboardController::class, 'get_dealers_list']);
Route::get('/get-borrower-details/{borrower_id}', [DashboardController::class, 'get_borrower_details']);

Route::get('/languages', [DashboardController::class, 'getLanguages']);
Route::get('/battery-types', [DashboardController::class, 'getBatteryTypes']);
Route::get('/product-types', [DashboardController::class, 'getProductTypes']);
Route::get('/manufacturers', [DashboardController::class, 'getManufacturers']);
Route::get('/marital-statuses', [DashboardController::class, 'getMaritalStatuses']);
Route::get('/resident-types', [DashboardController::class, 'getResidentTypes']);
Route::get('/occupation-types', [DashboardController::class, 'getOccupationTypes']);
Route::get('/vehicle-types', [DashboardController::class, 'getVehicleTypes']);
Route::get('/relations', [DashboardController::class, 'getRelations']);

// Language
Route::post('/store-language', [LanguagesController::class, 'create']);
Route::get('/edit-language/{id}', [LanguagesController::class, 'read']);
Route::put('/update-language/{id}', [LanguagesController::class, 'update']);
// Route::get('/delete-language/{id}', [LanguagesController::class, 'delete']);

// Battery type
Route::get('/battery-type-screener', [BatteryTypesController::class, 'battery_type_screener']);
Route::post('/store-battery-type', [BatteryTypesController::class, 'create']);
Route::get('/edit-battery-type/{id}', [BatteryTypesController::class, 'read']);
Route::put('/update-battery-type/{id}', [BatteryTypesController::class, 'update']);
// Route::get('/delete-battery-type/{id}', [BatteryTypesController::class, 'delete']);


// Manufacturers
Route::get('/manufacturer-screener', [ManufacturersController::class, 'manufacturer_screener']);
Route::post('/store-manufacturer', [ManufacturersController::class, 'create']);
Route::get('/edit-manufacturer/{id}', [ManufacturersController::class, 'read']);
Route::put('/update-manufacturer/{id}', [ManufacturersController::class, 'update']);
// Route::get('/delete-manufacturer/{id}', [ManufacturersController::class, 'delete']);

// MaritalStatus
Route::post('/store-marital-status', [MaritalStatusesController::class, 'create']);
Route::get('/edit-marital-status/{id}', [MaritalStatusesController::class, 'read']);
Route::put('/update-marital-status/{id}', [MaritalStatusesController::class, 'update']);
// Route::get('/delete-marital-status/{id}', [MaritalStatusesController::class, 'delete']);

// Resident Type
Route::post('/store-resident-type', [ResidentTypesController::class, 'create']);
Route::get('/edit-resident-type/{id}', [ResidentTypesController::class, 'read']);
Route::put('/update-resident-type/{id}', [ResidentTypesController::class, 'update']);
// Route::get('/delete-resident-type/{id}', [ResidentTypesController::class, 'delete']);


// Occupation Type
Route::post('/store-occupation-type', [OccupationTypesController::class, 'create']);
Route::get('/edit-occupation-type/{id}', [OccupationTypesController::class, 'read']);
Route::put('/update-occupation-type/{id}', [OccupationTypesController::class, 'update']);
// Route::get('/delete-occupation-type/{id}', [OccupationTypesController::class, 'delete']);


// Vehicle type
Route::get('/vehicle-type-screener', [VehicleTypesController::class, 'vehicle_type_screener']);
Route::post('/store-vehicle-type', [VehicleTypesController::class, 'create']);
Route::get('/edit-vehicle-type/{id}', [VehicleTypesController::class, 'read']);
Route::put('/update-vehicle-type/{id}', [VehicleTypesController::class, 'update']);
// Route::get('/delete-vehicle-type/{id}', [VehicleTypesController::class, 'delete']);

// Relationship
Route::post('/store-relationship', [RelationsController::class, 'create']);
Route::get('/edit-relationship/{id}', [RelationsController::class, 'read']);
Route::put('/update-relationship/{id}', [RelationsController::class, 'update']);
// Route::get('/delete-relationship/{id}', [RelationsController::class, 'delete']);

// Product Type
Route::get('/product-type-screener', [ProductTypesController::class, 'product_type_screener']);
Route::post('/store-product-type', [ProductTypesController::class, 'create']);
Route::get('/edit-product-type/{id}', [ProductTypesController::class, 'read']);
Route::put('/update-product-type/{id}', [ProductTypesController::class, 'update']);
// Route::get('/delete-product-type/{id}', [ProductTypesController::class, 'delete']);

// Dealers
Route::get('/dealer-screener', [DealersController::class, 'dealer_screener']);
// Route::post('/store-dealer', [DealersController::class, 'create']);

// Product
// Route::get('/get-product-list', [DashboardController::class, 'get_product_list']);
// Route::get('/get-product-list', [ProductController::class, 'index']);
// Route::post('/store-product', [ProductController::class, 'store']);
// Route::get('/edit-product/{id}', [ProductController::class, 'read']);
// Route::put('/update-product/{id}', [ProductController::class, 'update']);

// User Department
Route::get('/get-user-department-list', [UserDepartmentController::class, 'index']);
Route::get('/get-department-screener', [UserDepartmentController::class, 'department_screener']);
Route::post('/store-user-department', [UserDepartmentController::class, 'store']);
Route::get('/edit-user-department/{id}', [UserDepartmentController::class, 'read']);
Route::put('/update-user-department/{id}', [UserDepartmentController::class, 'update']);

// User Type
Route::get('/get-user-type-list', [UserTypeController::class, 'index']);
Route::get('/get-user-type-screener', [UserTypeController::class, 'user_type_screener']);
Route::post('/store-user-type', [UserTypeController::class, 'store']);
Route::get('/edit-user-type/{id}', [UserTypeController::class, 'read']);
Route::put('/update-user-type/{id}', [UserTypeController::class, 'update']);

Route::get('get-ifsc-info/{ifsc}', [HelperController::class, 'get_ifsc_information']);

// Route::post('/get-address-with-lat-long', [HelperController::class, 'get_address_with_lat_long']);

// Dashboard apis ends//

Route::group([
    'middleware'=>'api', 'prefix'=>'auth'
], function(){
    Route::get('/verify-token', [AuthController::class, 'verify_token']);
    // Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
    // Route::get('/refresh-token', [AuthController::class, 'refreshToken'])->name('refreshToken');
    // Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Route::get('/profile', [AuthController::class, 'profile']);
    // Route::get('/refresh-token', [AuthController::class, 'refreshToken']);
    // Route::post('/logout', [AuthController::class, 'logout']);
});

Route::group([
    'middleware'=>'api',
], function(){
    Route::post('/test-api', [IndexController::class, 'index']);
    Route::post('/update-address', [UserController::class, 'updateAddress']);
    Route::post('/update-basic-info', [UserController::class, 'updateBasicInfo']);
    
});

Route::group([
    'middleware'=>'api', 'prefix'=>'v1'
], function(){
    // delete api
    Route::post('/delete-applicant-data', [ApplicantController::class, 'deleteApplicantData']);
    
    Route::get('check-application-with-aadhar/{aadhar}', [HelperController::class, 'check_if_aadhar_exist']);
    Route::get('check-application-with-pan/{pan}', [HelperController::class, 'check_if_pan_exist']);
    
    Route::post('/get-loan-status', [LoanController::class, 'get_loan_status']);
    Route::post('get-loan-status-by-product', [LoanController::class, 'get_loan_status_by_product']);
    Route::post('/reset-loan-application', [LoanController::class, 'reset_loan_application']);
    Route::post('/store-basic-details', [LoanController::class, 'storeLoanBasicDetails']);
    Route::post('/store-borrower-details', [LoanController::class, 'storeBorrowerDetails']);
    Route::post('/store-borrower-family-details', [LoanController::class, 'storeBorrowerFamilyDetails']);
    Route::post('/store-borrower-occupation-details', [LoanController::class, 'storeBorrowerOccupation']);
    Route::post('/store-coapplicant-details', [LoanController::class, 'storeCoApplicantDetails']);
    Route::post('/store-coapplicant-occupation-details', [LoanController::class, 'storeCoApplicantOccupation']);
    Route::post('/store-guarantor-details', [LoanController::class, 'storeGuarantorDetails']);
    Route::post('/store-guarantor-occupation-details', [LoanController::class, 'storeGuarantorOccupation']);
    
    // Route::post('/get-models', [LoanController::class, 'verify_dealer_id']);
    Route::post('/get-models', [ProductController::class, 'get_product_models']);
    Route::post('/approve-multi-products', [ProductController::class, 'getApproveMultiProducts']);
    Route::post('/user-requests',[ProductController::class, 'userequeststore']);
    Route::get('/user-requests',[ProductController::class, 'userrequestindex']);
    
    Route::post('/lender-consent', [LoanController::class, 'lender_consent_acceptance']);
    Route::post('/store-reference-details', [LoanController::class, 'store_reference_details']);
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
    Route::post('/get-dealer-assets-details', [LoanController::class, 'get_dealer_details_and_models']);
    Route::post('/check-vkyc-status', [LoanController::class, 'check_vkyc_result']);
    Route::post('/match-address-with-borrower', [LoanController::class, 'match_address_with_borrower']);
    Route::post('/get-user-details-for-esign', [LoanController::class, 'get_loan_user_details_for_esign']);
    Route::post('/skip-co-applicant', [LoanController::class, 'skip_co_applicant']);
    Route::post('/skip-guarantor', [LoanController::class, 'skip_guarantor']);
    Route::post('/skip-iot-step', [LoanController::class, 'skip_iot_step']);
    // Route::post('/get-user-address-details', [LoanUsersController::class, 'user_address_details']);
    Route::post('/get-user-address-details', [BorrowerController::class, 'get_applicant_address_data']);
    Route::post('/get-borrower-details', [LoanUsersController::class, 'get_borrower_details']);
    Route::get('/get-name-declaration', [LoanController::class, 'get_name_declaration_document']);
        Route::get('/get-borrower-loan-details/{borrower_id}', [BorrowerController::class, 'get_borrower_loan_details']);
    // Route::post('/verify-reference', [LoanController::class, 'verify_reference']);
    
    Route::post('/store-field-verification-details', [LoanController::class, 'store_field_verification_details']);
    Route::post('/field-verification', [LoanController::class, 'field_verification']);
    Route::post('/get-field-verification-status', [LoanController::class, 'get_field_verification_status']);
    
    // service api
    Route::post('/verify-aadhar', [ServiceApiController::class, 'aadhar_verify']);
    Route::post('/verify-aadhar-resend-otp', [ServiceApiController::class, 'verify_aadhar_resend_otp']);
    Route::post('/verify-aadhar-using-otp', [ServiceApiController::class, 'verify_aadhar_using_otp']);
    Route::post('/verify-pan', [ServiceApiController::class, 'pan_verification']);
    // Route::post('/get-credit-score', [ServiceApiController::class, 'get_credit_score_experian']);
    Route::post('/pan-ocr-check', [ServiceApiController::class, 'pan_ocr_api']);
    Route::post('/aadhar-ocr-check', [ServiceApiController::class, 'aadhar_ocr_api']);
    Route::post('/verify-driving-licence', [ServiceApiController::class, 'verify_driving_licence']);
    Route::post('/check-iot-activation', [ServiceApiController::class, 'iot_activition_check']);
    Route::post('/generate-vkyc-url', [ServiceApiController::class, 'video_kyc_generate_url']);
    Route::post('/vkyc-transaction-details', [ServiceApiController::class, 'vkyc_transaction_details']);
    Route::post('/get-vkyc-call-details', [ServiceApiController::class, 'get_vkyc_status_by_application_number']);
    // Route::post('/generate-esign-url', [ServiceApiController::class, 'generate_esign']);
    Route::post('/generate-esign', [ServiceApiController::class, 'generate_esign']);
    Route::post('/download-esigned-doc', [ServiceApiController::class, 'download_esign_doc']);
    // Route::post('/download-esigned-doc', [ServiceApiController::class, 'download_esign_doc']);
    Route::post('/get-credit-score-details', [ServiceApiController::class, 'get_credit_score_details']);
    Route::post('/verify-pan-details', [ServiceApiController::class, 'pan_details_verification']);

    // app support  
    Route::get('/get-support-subject-screener', [AppSupportController::class, 'get_support_subject_screener']);
    Route::post('/store-support-query', [AppSupportController::class, 'store']);
    // 25-01-2025
    Route::get('/get-emi-debit-consent-data', [HelperController::class, 'get_emi_debit_consent_data_b2c']);

    // 08-02-2025
    Route::post('/store-api-data', [EasebuzzPaymentController::class, 'store_api_request_response']);
    // 18-02-2025
    Route::post('/initiate-emi-payment', [EasebuzzPaymentController::class, 'initiate_payment']);

    // 19-02-2025
    Route::post('/store-easebuzz-payment-response', [EasebuzzPaymentController::class, 'store_easebuzz_payment_response']);

    // 26-02-2025
    Route::get('/get-dealer-shops-fi', [DealerShopsController::class, 'get_dealer_shops_borrower_id_b2c']);

    // 18-03-2025
    Route::get('/get-fips', [AccountAggregatorController::class, 'get_fips']);
    Route::post('/initiate-account-aggagregator-request', [AccountAggregatorController::class, 'initiate_request_b2c']);

    // 03-04-2025
    Route::get('/get-emi-payment-receipt/{receipt_no}', [EasebuzzPaymentController::class, 'get_emi_receipt']);
    
    Route::get('/get-name-declaration-doc', [HelperController::class, 'get_name_declaration_doc']);
    Route::get('/get-dob-declaration', [HelperController::class, 'get_dob_declaration']);

    Route::post('/initiate-pre-enach-request', [EasebuzzPaymentController::class, 'create_pre_enach_mandate']);
    Route::post('/initiate-post-enach-request', [EasebuzzPaymentController::class, 'create_enach_mandate']);
    Route::get('/get-enach-details', [EasebuzzPaymentController::class, 'get_borrower_account_enach_details']);
    Route::post('/skip-enach-step', [LoanController::class, 'skip_nach_step']);
    Route::post('/store-enach-registration-details', [LoanController::class, 'store_nach_registration_details']);

    // 24-05-2025
    Route::get('/get-state-screener', [StateController::class, 'state_screener']); // List all states
    Route::get('/check-aa-status', [AccountAggregatorController::class, 'check_aa_status_b2c']);
    
    Route::post('/generate-unified-kyc-url', [ServiceApiController::class, 'generate_url_unified_kyc']);
    Route::get('/check-unified-kyc-status', [ServiceApiController::class, 'generate_url_unified_kyc_staus']);

    Route::prefix('digilocker')->group(function () {
        Route::post('generate-url', [ServiceApiController::class, 'digilocker_generate_url']);
        Route::post('status',       [ServiceApiController::class, 'digilocker_status']);
        Route::post('details',      [ServiceApiController::class, 'digilocker_details']);
    });
});

// Route::get('/get-emi-payment-receipt/{receipt_no}', [EasebuzzPaymentController::class, 'get_emi_receipt']);



// Webhooks
// 19-02-2025
Route::post('/easebuzz/webhook', [EasebuzzPaymentController::class, 'payment_webhook']);
Route::post('/easebuzz/enach-webhook', [EasebuzzPaymentController::class, 'easebuzz_enach_webhook']);

// 07-04-2025
Route::post('/account-aggregator/webhook', [AccountAggregatorController::class, 'store_account_aggregator_webhook_response']);

// 27-01-2025
// Field inspection independent apis
Route::post('/field-inspection/send-otp', [LoanController::class, 'send_otp_field_verification']);
Route::post('/field-inspection/verify-otp', [LoanController::class, 'verify_otp_field_verification']);
Route::post('/field-inspection/verify-access-token', [LoanController::class, 'verify_fi_access_token']);
Route::post('/field-inspection/run-field-verification', [LoanController::class, 'calculate_distance_between_borrower_dealer']);
Route::post('field-inspection/meter-image', [LoanController::class, 'store_meter_image']);
Route::post('field-inspection/status', [LoanController::class, 'get_field_inspection_status']);

// B2C Admin

Route::post('admin/register', [AdminAuthController::class, 'register']);
Route::post('admin/login', [AdminAuthController::class, 'login']);
Route::post('admin/send-otp', [AdminAuthController::class, 'sendOtp']);
Route::post('admin/verify-otp', [AdminAuthController::class, 'login_with_otp']);

Route::middleware('auth:admin')->group(function () {
    Route::get('/get-b2b-borrower-list', [DashboardController::class, 'get_borrower_list'])->name('admin.b2b-borrower-list');
    Route::get('/get-b2c-borrower-list', [DashboardController::class, 'get_borrower_list'])->name('admin.b2c-borrower-list');
    Route::get('/get-loan-application-list', [DashboardController::class, 'get_loan_application_list']);
    // Route::get('/get-borrower-list', [DashboardController::class, 'get_borrower_list'])->name('admin.get-borrower-list');
    // Route::get('/get-dashboard-details', [DashboardController::class, 'getDashboardDetails']);
    // Route::get('/get-yearly-count', [DashboardController::class, 'getYearlyCount']);

    Route::get('/get-borrower-list', [AdminDashboardController::class, 'get_borrower_list'])->name('admin.get-borrower-list');
    Route::get('/get-dashboard-details', [AdminDashboardController::class, 'getDashboardDetails']);
    Route::get('/get-yearly-count', [AdminDashboardController::class, 'getYearlyCount']);
    // routes/api.php
    Route::get('/loan-master/export', [LoanMasterExportController::class, 'export']);
    Route::get('/pos-data/export', [LoanMasterExportController::class, 'posexport']);
    Route::get('/portfolio-cut/export', [LoanMasterExportController::class, 'portexport']);
    Route::get('/dealer-shops/export', [LoanMasterExportController::class, 'dealerexport']);
});
Route::middleware('auth:admin')->prefix('admin')->group(function () {
    Route::post('/field-inspection-meter-image-verify', [BorrowerController::class, 'verify_meter_image'])->name('admin.update-meter-verification-status');

    Route::prefix('acl')->group(function () {
        Route::get('/get-sidebar-menus', [SidebarMenuController::class, 'index']);
        Route::get('/get-permissions', [PermissionListController::class, 'index']);

        // user permission
        Route::get('/get-user-permissions/{userId}', [UserPermissionController::class, 'show']);
        Route::post('/update-user-permissions/{userId}', [UserPermissionController::class, 'bulkUpdate']);

        // Role permission
        Route::get('/get-role-permissions/{role}', [RolePermissionController::class, 'show']);
        Route::post('/update-role-permissions/{role}', [RolePermissionController::class, 'sync']);
    });

    Route::get('/get-product-list', [ProductController::class, 'index'])->name('admin.get-product-list');
    Route::post('/product-models/toggle-approve-multi/{key}', [ProductController::class, 'toggleApproveMulti'])->name('admin.toggle-approve-multi');
    Route::post('/store-product', [ProductController::class, 'store'])->name('admin.store-product');
    Route::get('/edit-product/{id}', [ProductController::class, 'read'])->name('admin.edit-product');
    Route::put('/update-product/{id}', [ProductController::class, 'update'])->name('admin.update-product');

    Route::post('/store-dealer', [DealersController::class, 'store_dealer'])->name('admin.store-dealer');
    Route::get('/edit-dealer/{id}', [DealersController::class, 'read'])->name('admin.edit-dealer');
    // Route::post('/update-dealer', [DealersController::class, 'update']);
    Route::post('/update-dealer', [DealersController::class, 'update_dealer'])->name('admin.update-dealer');
    // Route::get('/delete-dealer/{id}', [DealersController::class, 'delete']);


    Route::post('/store-dealer-authority', [DealerDesignatedAuthorityController::class, 'store'])->name('admin.store-dealer-authority');
    Route::get('/edit-dealer-authority/{id}', [DealerDesignatedAuthorityController::class, 'show'])->name('admin.edit-dealer-authority');
    Route::get('/get-dealer-authorities/{dealer_id}', [DealerDesignatedAuthorityController::class, 'get_delaer_authorities'])->name('admin.get-dealer-authorities');
    Route::post('/update-dealer-authority', [DealerDesignatedAuthorityController::class, 'update'])->name('admin.update-dealer-authority');


    // Route::get('/get-borrower-details/{borrower_id}', [DashboardController::class, 'get_borrower_details'])->name('admin.get-borrower-details');
    Route::get('/get-borrower-details/{borrower_id}', [AdminDashboardController::class, 'get_borrower_details'])->name('admin.get-borrower-details');

    // User Type
    Route::get('/get-user-type-list', [UserTypeController::class, 'index'])->name('admin.get-user-type-list');
    Route::get('/get-user-type-screener', [UserTypeController::class, 'user_type_screener']);
    Route::post('/store-user-type', [UserTypeController::class, 'store'])->name('admin.store-user-type');
    Route::get('/edit-user-type/{id}', [UserTypeController::class, 'read'])->name('admin.edit-user-type');
    Route::put('/update-user-type/{id}', [UserTypeController::class, 'update'])->name('admin.update-user-type');

    Route::get('/get-loan-status-list', [DashboardController::class, 'get_loan_status_list']);


    // User Department
    Route::get('/get-user-department-list', [UserDepartmentController::class, 'index'])->name('admin.get-user-department-list');
    Route::get('/get-department-screener', [UserDepartmentController::class, 'department_screener']);
    Route::post('/store-user-department', [UserDepartmentController::class, 'store'])->name('admin.store-user-department');
    Route::get('/edit-user-department/{id}', [UserDepartmentController::class, 'read'])->name('admin.edit-user-department');
    Route::put('/update-user-department/{id}', [UserDepartmentController::class, 'update'])->name('admin.update-user-department');

    Route::post('/delete-applicant-data', [ApplicantController::class, 'deleteApplicantData']);

    Route::post('logout', [AdminAuthController::class, 'logout'])->middleware('auth:admin');
    // B2C Admin
    Route::get('get-user-list', [AdminAuthController::class, 'userList'])->name('admin.get-user-list');
    Route::get('edit-user/{id}', [AdminAuthController::class, 'edit'])->name('admin.edit-user');
    Route::get('user', [AdminAuthController::class, 'user'])->middleware('auth:admin');
    Route::put('update-user/{id}', [AdminAuthController::class, 'update'])->name('admin.update-user');
    Route::get('update-user-status/{id}', [AdminAuthController::class, 'update_status']);
    Route::post('change-admin-user-status/{id}', [AdminAuthController::class, 'change_admin_user_status'])->name('admin.change-admin-user-status');
    
    // Uploaded product list
    Route::post('upload-product-excel', [ProductController::class, 'upload_excel_and_validate']);
    Route::get('get-uploaded-product-files', [ProductController::class, 'get_uploaded_files']);
    Route::put('approve-product-excel/{id}', [ProductController::class, 'approve_excel_and_process']);
    Route::put('reject-product-excel/{id}', [ProductController::class, 'reject_product_excel']);
    
    // Modules
    Route::get('get-module-list', [ModuleController::class, 'index']);
    Route::post('store-module', [ModuleController::class, 'store']);
    Route::get('edit-module-list/{id}', [ModuleController::class, 'show']);
    Route::put('update-module-list/{id}', [ModuleController::class, 'update']);

    // MenuItem
    Route::get('get-menu-item-list', [MenuItemController::class, 'index']);
    Route::post('store-menu-item', [MenuItemController::class, 'store']);
    Route::get('edit-menu-item-list/{id}', [MenuItemController::class, 'show']);
    Route::put('update-menu-item-list/{id}', [MenuItemController::class, 'update']);

    // MenuItem
    Route::get('get-sub-menu-item-list', [SubMenuItemController::class, 'index']);
    Route::post('store-sub-menu-item', [SubMenuItemController::class, 'store']);
    Route::get('edit-sub-menu-item-list/{id}', [SubMenuItemController::class, 'show']);
    Route::put('update-sub-menu-item-list/{id}', [SubMenuItemController::class, 'update']);
    
    // Access Role
    Route::get('get-user-sidebar-menu', [AccessRoleController::class, 'getSidebarMenu']);
    // Route::get('get-user-sidebar-menu', [AccessRoleController::class, 'getSidebarMenu'])->name('admin.get-user-sidebar-menu');
    // Route::get('get-menu-groups', [AccessRoleController::class, 'getAllMenuGroups'])->name('admin.get-menu-groups');
    Route::get('get-menu-groups', [AccessRoleController::class, 'getAllMenuGroups']);
    // Route::get('get-sub-menu-permissions/{submenu_id}', [AccessRoleController::class, 'getSubMenuPermissions'])->   name('admin.get-sub-menu-permissions');
    Route::get('get-sub-menu-permissions/{submenu_id}', [AccessRoleController::class, 'getSubMenuPermissions']);
    Route::get('get-access-role-by-user-type/{user_type_id}', [AccessRoleController::class, 'show'])->name('admin.get-access-role-by-user-type');
    Route::post('store-access-role', [AccessRoleController::class, 'store'])->name('admin.store-access-role');
    Route::put('update-access-role', [AccessRoleController::class, 'update'])->name('admin.update-access-role');

    // Credit Details Consent Content
    Route::get('get-credit-details-contents', [CreditDetailsConsentController::class, 'index']);
    Route::post('store-credit-details-content', [CreditDetailsConsentController::class, 'store']);
    Route::get('edit-credit-details-content/{id}', [CreditDetailsConsentController::class, 'show']);
    Route::put('update-credit-details-content/{id}', [CreditDetailsConsentController::class, 'update']);
    // Route::get('delete-credit-details-content', [CreditDetailsConsentController::class, 'destroy']);
    
    // Onboarding content
    Route::get('get-onboarding-contents', [OnboardingScreenContentController::class, 'index']);
    Route::post('store-onboarding-content', [OnboardingScreenContentController::class, 'store']);
    Route::get('edit-onboarding-content/{id}', [OnboardingScreenContentController::class, 'show']);
    Route::put('update-onboarding-content/{id}', [OnboardingScreenContentController::class, 'update']);
    // Route::get('delete-onboarding-content', [OnboardingScreenContentController::class, 'destroy']);
    
    // Emi debit consent content
    Route::get('get-emi-debit-contents', [EmiDebitConsentController::class, 'index']);
    Route::post('store-emi-debit-content', [EmiDebitConsentController::class, 'store']);
    Route::get('edit-emi-debit-content/{id}', [EmiDebitConsentController::class, 'show']);
    Route::put('update-emi-debit-content/{id}', [EmiDebitConsentController::class, 'update']);
    // Route::get('delete-emi-debit-content', [EmiDebitConsentController::class, 'destroy']);

    Route::get('download-folder/{folderName}', [DashboardController::class, 'downloadFolder'])->name('admin.download-borrower-kyc-documents');
    Route::get('download-agreement-file/{borrower_id}', [DashboardController::class, 'download_agreement_file'])->name('admin.download-agreement-file');
    Route::get('cam-sheet-doc-download/{borrower_id}', [DashboardController::class, 'cam_sheet_doc'])->name('admin.cam-sheet-doc-download');

    Route::get('cam-sheet-doc-download-test/{borrower_id}', [DashboardController::class, 'cam_sheet_doc_test'])->name('admin.cam-sheet-doc-download-test');
    Route::post('store-lender-product-charges', [ProductController::class, 'store_lender_product_charges']);
    Route::get('get-active-charges-by-product/{product_id}', [ProductController::class, 'getActiveChargesByProduct']);
    
    Route::post('store-lender-manufacturer-charges', [ManufacturersController::class, 'store_lender_manufacturer_charges']);
    Route::get('get-active-charges-by-manufacturer/{manufacturer_id}', [ManufacturersController::class, 'getActiveCharges']);
    
    Route::post('store-lender-dealer-charges', [DealersController::class, 'store_lender_dealer_charges']);
    Route::get('get-active-charges-by-dealer/{dealer_id}', [DealersController::class, 'getActiveChargesByDealer']);

    Route::get('get-lender-list', [LenderController::class, 'index'])->name('admin.get-lender-list');
    Route::post('store-lender', [LenderController::class, 'store'])->name('admin.store-lender');
    Route::get('edit-lender/{id}', [LenderController::class, 'show'])->name('admin.edit-lender');
    Route::post('update-lender', [LenderController::class, 'update'])->name('admin.update-lender');

    Route::get('lender-screener', [LenderController::class, 'lender_screener']);

    Route::get('get-lender-directors', [LenderDirectorController::class, 'index']);
    Route::post('store-lender-directors', [LenderDirectorController::class, 'store'])->name('admin.store-lender-directors');
    Route::get('edit-lender-directors/{id}', [LenderDirectorController::class, 'show']);
    Route::put('update-lender-director/{id}', [LenderDirectorController::class, 'update'])->name('admin.update-lender-director');

    Route::post('store-lender-charges', [LenderController::class, 'store_lender_charges'])->name('admin.store-lender-charges');
    Route::get('get-active-charges-by-lender/{lender_id}', [LenderController::class, 'getActiveCharges'])->name('admin.get-active-charges-by-lender');

    Route::get('get-lender-service-provider-list', [LenderServiceProviderController::class, 'index']);
    Route::post('store-lender-service-provider', [LenderServiceProviderController::class, 'create']);
    Route::get('edit-lender-service-provider/{id}', [LenderServiceProviderController::class, 'view']);
    Route::post('update-lender-service-provider', [LenderServiceProviderController::class, 'update']); 

    Route::get('director-list-by-lsp/{lsp_id}', [LspDirectorsController::class, 'director_list_by_lsp_id']);
    Route::post('store-lsp-director', [LspDirectorsController::class, 'store']);
    Route::get('edit-lsp-director/{id}', [LspDirectorsController::class, 'show']);
    Route::put('update-lsp-director/{id}', [LspDirectorsController::class, 'update']);

    Route::get('get-lsp-screener', [LspExecutiveController::class, 'get_lsp_screener']);
    Route::get('get-lsp-executive-list', [LspExecutiveController::class, 'get_lsp_executive_list']);
    Route::post('store-lsp-executive', [LspExecutiveController::class, 'store']);
    Route::get('edit-lsp-executive/{id}', [LspExecutiveController::class, 'show']);
    // Route::put('update-lsp-executive/{id}', [LspExecutiveController::class, 'update']);  
    Route::post('update-lsp-executive', [LspExecutiveController::class, 'update']); 

    // OEM
    Route::get('get-oem-screener', [OemController::class, 'oem_screener']);
    Route::get('get-oem-list', [OemController::class, 'index']);
    Route::post('store-oem-details', [OemController::class, 'store_oem']);
    Route::get('edit-oem-details/{id}', [OemController::class, 'read']);
    Route::post('update-oem-details', [OemController::class, 'update_oem']); 
    Route::get('update-oem-status/{id}', [OemController::class, 'update_status']); 

    // OEM product specification apis
    Route::post('store-oem-product-specification', [OemProductSpecificationController::class, 'store']);
    Route::get('edit-oem-product-specification/{id}', [OemProductSpecificationController::class, 'show']);
    Route::post('update-oem-product-specification/{id}', [OemProductSpecificationController::class, 'update']);
    
    Route::get('update-lsp-executive-status/{id}', [LspExecutiveController::class, 'update_status']); 
    Route::get('update-dealer-status/{id}', [DealersController::class, 'update_status'])->name('admin.update-dealer-status'); 
    Route::get('update-lender-status/{id}', [LenderController::class, 'update_status'])->name('admin.update-lender-status'); 
    Route::get('update-lender-service-provider-status/{id}', [LenderServiceProviderController::class, 'update_status']); 

    Route::get('get-dealers-by-lsp-executive/{executive_id}', [LspExecutiveDealerGroupingController::class, 'getDealersByExecutiveId']);
    Route::post('store-lsp-executive-dealer-grouping', [LspExecutiveDealerGroupingController::class, 'store']);
    Route::get('edit-lsp-executive-dealer-grouping/{id}', [LspExecutiveDealerGroupingController::class, 'show']);
    Route::put('update-lsp-executive-dealer-grouping/{id}', [LspExecutiveDealerGroupingController::class, 'update']); 
    Route::get('delete-lsp-executive-dealer-grouping/{id}', [LspExecutiveDealerGroupingController::class, 'destroy']);

    Route::post('account-verfication-send-otp', [AdminAuthController::class, 'sendOtp']);
    Route::post('account-verfication-verify-otp', [AdminAuthController::class, 'verify_mobile_otp']);
    Route::post('change-admin-password', [AdminAuthController::class, 'changePassword']);
    Route::get('/get-documents-by-borrower/{borrower_id}', [BorrowerController::class, 'get_borrower_documents']);
    Route::post('/update-borrower-documents', [BorrowerController::class, 'update_borrower_documents'])->name('admin.update-borrower-documents');

    Route::post('/store-loan-service-provider', [LoanServiceProviderController::class, 'store_lsp']);
    Route::post('/update-loan-service-provider', [LoanServiceProviderController::class, 'update_lsp']);
    Route::get('/lsp-screener', [LoanServiceProviderController::class, 'lsp_screener']);
    Route::get('/loan-service-provider-details/{id}', [LoanServiceProviderController::class, 'read']);
    Route::get('/update-loan-service-provider-status/{id}', [LoanServiceProviderController::class, 'update_status']);
    Route::get('/loan-service-provider-list', [LoanServiceProviderController::class, 'index']);

    Route::get('get-branch-location-screener', [BranchLocationController::class, 'get_branch_location_screener']);
    Route::get('get-branch-location-list', [BranchLocationController::class, 'index']);
    Route::post('store-branch-location', [BranchLocationController::class, 'store']);
    Route::get('edit-branch-location/{id}', [BranchLocationController::class, 'show']);
    Route::post('update-branch-location/{id}', [BranchLocationController::class, 'update']);
    Route::get('update-branch-location-status/{id}', [BranchLocationController::class, 'changeStatus']);
    // Route::get('delete-branch-location/{id}', [BranchLocationController::class, 'destroy']);

    Route::get('/dealer-screener-lsp', [DealersController::class, 'dealer_screener_lsp']);
    Route::get('/get-product-types', [ProductController::class, 'get_product_type_list_b2c']);

    // Admin loan application
    Route::post('/get-loan-status', [AdminLoanController::class, 'get_loan_status']);
    Route::post('/reset-loan-application', [AdminLoanController::class, 'reset_loan_application']);
    Route::post('/store-basic-details', [AdminLoanController::class, 'storeLoanBasicDetails']);
    Route::post('/store-borrower-details', [AdminLoanController::class, 'storeBorrowerDetails']);
    Route::post('/store-borrower-family-details', [AdminLoanController::class, 'storeBorrowerFamilyDetails']);
    Route::post('/store-borrower-occupation-details', [AdminLoanController::class, 'storeBorrowerOccupation']);
    Route::post('/store-coapplicant-details', [AdminLoanController::class, 'storeCoApplicantDetails']);
    Route::post('/store-coapplicant-occupation-details', [AdminLoanController::class, 'storeCoApplicantOccupation']);
    Route::post('/store-guarantor-details', [AdminLoanController::class, 'storeGuarantorDetails']);
    Route::post('/store-guarantor-occupation-details', [AdminLoanController::class, 'storeGuarantorOccupation']);
    Route::post('/skip-co-applicant', [AdminLoanController::class, 'skip_co_applicant']);
    Route::post('/skip-guarantor', [AdminLoanController::class, 'skip_guarantor']);
    Route::post('/skip-iot-step', [AdminLoanController::class, 'skip_iot_step']);

    Route::get('check-application-with-aadhar/{aadhar}', [HelperController::class, 'check_if_aadhar_exist']);
    Route::get('check-application-with-pan/{pan}', [HelperController::class, 'check_if_pan_exist']);
    Route::get('/get-borrower-registration-details/{borrower_id}', [AdminLoanController::class, 'borrower_registration_details']);
    Route::get('/get-borrower-loan-details/{borrower_id}', [BorrowerController::class, 'get_borrower_loan_details']);
    Route::post('/get-models', [ProductController::class, 'get_product_models']);
    Route::post('/match-address-with-borrower', [AdminLoanController::class, 'match_address_with_borrower']);
    // Route::post('/get-user-address-details', [AdminLoanController::class, 'user_address_details']);
    Route::post('/get-user-address-details', [BorrowerController::class, 'get_applicant_address_data']);
    
    Route::get('/get-all-user-list', [AdminLoanController::class, 'get_all_user_list'])->name('admin.get-all-user-list');
    Route::post('/user-requests',[AdminLoanController::class, 'store']); // API 1 — Create
    Route::get('/user-requests',[AdminLoanController::class, 'index']); // API 2 — List
    Route::post('/user-requests/{id}/status',[AdminLoanController::class, 'updateStatus']); // API 3 — Approv
    Route::get('/get-name-declaration', [AdminLoanController::class, 'get_name_declaration_document']);
    Route::post('/run-rule-engine', [AdminLoanController::class, 'rule_engine']);
    Route::post('/lender-consent', [AdminLoanController::class, 'lender_consent_acceptance']);
    Route::post('/get-borrower-details', [AdminLoanController::class, 'get_borrower_details']);

    Route::post('/get-loan-terms', [AdminLoanController::class, 'loan_terms']);
    Route::post('/upload-document', [AdminLoanController::class, 'upload_image']);
    Route::post('/run-rule-engine', [AdminLoanController::class, 'rule_engine']);
    Route::post('/upload-borrower-document', [AdminLoanController::class, 'borrower_doc_upload_admin']);
    Route::post('/upload-borrower-battery-document', [AdminLoanController::class, 'borrower_doc_upload_battery_admin']);
    Route::post('/upload-coapplicant-document', [AdminLoanController::class, 'co_applicant_doc_upload_admin']);
    Route::post('/upload-guarantor-document', [AdminLoanController::class, 'guarantor_doc_upload_admin']);
    Route::post('/upload-vehicle-document', [AdminLoanController::class, 'vehicle_doc_upload_admin']);
    Route::post('/upload-battery-document', [AdminLoanController::class, 'battery_doc_upload_admin']);

    Route::post('/store-reference-details', [AdminLoanController::class, 'store_reference_details']);
    Route::post('/get-reference-list', [AdminLoanController::class, 'get_reference_list']);
    Route::post('/store-field-verification-details', [AdminLoanController::class, 'store_field_verification_details']);
    Route::post('/get-field-verification-status', [AdminLoanController::class, 'get_field_verification_status']);
    Route::post('/check-vkyc-status', [AdminLoanController::class, 'check_vkyc_result']);
    Route::post('/store-emi-debit-consent', [AdminLoanController::class, 'emi_debit_consent']);
    
    // Route::post('/get-user-details-for-esign', [AdminLoanController::class, 'get_loan_user_details_for_esign']);
    Route::post('/get-user-details-for-esign', [BorrowerController::class, 'get_user_details_for_esign']);

    Route::get('/get-api-details-by-borrower/{borrower_id}', [BorrowerController::class, 'get_api_details_by_borrower']);
    Route::get('/get-app-activity-log', [ActivityLogController::class, 'get_app_log']);
    Route::get('/get-executive-screener-for-app-log', [ActivityLogController::class, 'get_executive_screener_for_app_log']);

    // Uploaded disbursement details entry
    Route::get('get-disbursement-list', [DisbursementDetailsController::class, 'index']);
    Route::post('store-disbursement-details', [DisbursementDetailsController::class, 'store']);
    Route::get('edit-disbursement-details/{id}', [DisbursementDetailsController::class, 'show']);
    Route::post('update-disbursement-details/{id}', [DisbursementDetailsController::class, 'update']);
    Route::get('get-borrower-screener', [DisbursementDetailsController::class, 'borrower_screener']);

    Route::get('get-all-borrower-screener', [BorrowerController::class, 'get_borrower_list']);
    
    // Uploaded disbursement excel
    Route::post('upload-disbursement-excel', [DisbursementDetailsController::class, 'upload_excel_and_validate']);
    Route::get('get-uploaded-disbursement-files', [DisbursementDetailsController::class, 'get_uploaded_files']);
    Route::post('approve-disbursement-excel/{id}', [DisbursementDetailsController::class, 'approve_excel_and_process']);
    Route::post('reject-disbursement-excel/{id}', [DisbursementDetailsController::class, 'reject_product_excel']);
    Route::get('get-disbursement-excel-template', [DisbursementDetailsController::class, 'get_disbursement_excel_template']);

    // Lender Borrower Group
    Route::get('get-lender-borrower-group-list', [LenderBorrowerGroupController::class, 'index'])->name('admin.lender-borrower-group-list');
    Route::post('store-lender-borrower-group', [LenderBorrowerGroupController::class, 'store'])->name('admin.store-lender-borrower-group');
    Route::get('lender-borrower-list', [LenderBorrowerGroupController::class, 'get_borrower_list']);
    Route::get('lender-borrower-details/{borrower_id}', [LenderBorrowerGroupController::class, 'get_borrower_details'])->name('admin.lender-borrower-details');
    // Route::get('get-lender-borrower-group-list', [LenderBorrowerGroupController::class, 'index']);

    // lender dashboard
    Route::get('get-lender-dashboard-details', [LenderBorrowerGroupController::class, 'get_lender_dashboard_details']);
    Route::get('get-lender-yearly-counts', [LenderBorrowerGroupController::class, 'get_lender_yearly_count']);

    Route::post('lender-request-download-permission', [LenderBorrowerGroupController::class, 'raise_download_permission_request']);
    Route::get('lender-download-request-list', [LenderBorrowerGroupController::class, 'lenders_download_request_list']);
    Route::get('all-lender-download-request-list', [LenderBorrowerGroupController::class, 'all_lenders_download_request_list_'])->name('admin.download-request-list');
    Route::post('handle-lender-download-request/{id}', [LenderBorrowerGroupController::class, 'handle_lenders_download_request']);
    Route::post('handle-lender-doc-download', [LenderBorrowerGroupController::class, 'lender_doc_download']);
    
    Route::post('download-vkyc-report', [AdminServiceApiController::class, 'vkyc_transaction_report'])->name('admin.download-vkyc-report');

    // cheque ocr for OEM and dealer
    Route::post('/verify-admin-cheque-ocr', [AdminServiceApiController::class, 'cheque_ocr_admin']);

    // Beneficiary
    Route::get('/get-beneficiary-list', [BeneficiaryDetailsController::class, 'index']);
    Route::post('/store-beneficiary-details', [BeneficiaryDetailsController::class, 'store']);
    Route::get('/get-beneficiary-details/{id}', [BeneficiaryDetailsController::class, 'show']);
    Route::post('/update-beneficiary-details/{id}', [BeneficiaryDetailsController::class, 'update']);
    Route::post('/update-beneficiary-status/{id}', [BeneficiaryDetailsController::class, 'update_status']);

    // Lender Dealer Grouing

    Route::get('/get-lender-dealer-group-list', [LenderDealerGroupController::class, 'index'])->name('admin.get-lender-dealer-group-list');
    Route::post('/store-lender-dealer-group', [LenderDealerGroupController::class, 'store'])->name('admin.store-lender-dealer-group');
    Route::get('/get-lender-dealer-group-details/{id}', [LenderDealerGroupController::class, 'show']);
    Route::post('/update-lender-dealer-group-details/{id}', [LenderDealerGroupController::class, 'update']);
    Route::post('/update-lender-dealer-group-status/{id}', [LenderDealerGroupController::class, 'changeStatus'])->name('admin.update-lender-dealer-group-status');

    Route::get('/get-app-support-queries', [AppSupportController::class, 'index']);
    Route::post('/update-app-support-status/{id}', [AppSupportController::class, 'update_status']);

    Route::get('/get-subject-list', [AppSupportController::class, 'subject_list']);
    Route::post('/store-support-subject', [AppSupportController::class, 'create_subject']);
    Route::post('/update-support-subject/{id}', [AppSupportController::class, 'update_subject']);
    Route::post('/update-support-subject-status/{id}', [AppSupportController::class, 'update_subject_status']);

    // update loan status
    Route::post('/change-borrower-loan-status', [BorrowerController::class, 'changeLoanStatus'])->name('admin.change-borrower-loan-status');

    // payment method 03-01-2025
    Route::get('/get-payment-method-list', [PaymentMethodController::class, 'index']);
    Route::get('/get-payment-method-screener', [PaymentMethodController::class, 'get_payment_method_screener']);
    Route::post('/store-payment-method', [PaymentMethodController::class, 'store']);
    Route::post('/update-payment-method/{id}', [PaymentMethodController::class, 'update']);
    Route::post('/update-payment-method-status/{id}', [PaymentMethodController::class, 'update_status']);

    Route::get('/get-dealer-product-excel-template', [ProductController::class, 'get_dealer_product_excel_template']);

    // subvention details 11-01-2025
    Route::get('/get-subvention-payment-list', [SubventionDetailsController::class, 'index']);
    Route::post('/store-subvention-detail', [SubventionDetailsController::class, 'store']);
    Route::get('/get-subvention-detail/{id}', [SubventionDetailsController::class, 'show']);
    Route::post('/update-subvention-detail/{id}', [SubventionDetailsController::class, 'update']);
    Route::post('/update-subvention-detail-status/{id}', [SubventionDetailsController::class, 'update_status']);
    Route::get('/get-borrower-details-for-subvention/{borrower_id}', [SubventionDetailsController::class, 'get_borrower_details_for_subvention_entry']);
    Route::get('/get-subvention-payment-status/{borrower_id}', [SubventionDetailsController::class, 'check_subvention_payment_status']);

    // 22-01-2025
    Route::get('/get-emi-debit-consent-data/{borrower_id}', [HelperController::class, 'get_emi_debit_consent_data']);
    Route::get('/get-rto-kit-template', [HelperController::class, 'get_rto_kit_template']);

    // 28-01-2025

    Route::get('/get-blocked-contact-list', [BlockAccessController::class, 'index']);
    Route::get('/edit-blocked-contact/{id}', [BlockAccessController::class, 'show']);
    Route::post('/update-blocked-contact/{id}', [BlockAccessController::class, 'update']);
    Route::post('/update-blocked-contact-status/{id}', [BlockAccessController::class, 'updateStatus']);

    // Uploaded Block Contact Access excel
    Route::post('/upload-block-access-excel', [BlockAccessController::class, 'upload_excel_and_validate']);
    Route::get('/get-uploaded-block-access-files', [BlockAccessController::class, 'get_uploaded_files']);
    Route::post('/approve-block-access-excel/{id}', [BlockAccessController::class, 'approve_excel_and_process']);
    Route::post('/reject-block-access-excel/{id}', [BlockAccessController::class, 'reject_product_excel']);
    Route::get('/get-block-access-excel-template', [BlockAccessController::class, 'getTemplateDownloadLink']);

    // 31-01-2025
    Route::post('/update-product-status-multiple', [ProductController::class, 'updateMultiProductStatus']);

    // 03-02-2025
    Route::get('/get-lender-loan-setting/{lender_id}', [LenderLoanSettingController::class, 'show'])->name('admin.get-lender-loan-setting');
    Route::post('/store-lender-loan-setting', [LenderLoanSettingController::class, 'store'])->name('admin.store-lender-loan-setting');
    Route::post('/update-lender-loan-setting/{lender_id}', [LenderLoanSettingController::class, 'update'])->name('admin.update-lender-loan-setting');
    // Route::post('/lender-loan-setting/{lender_id}', [LenderLoanSettingController::class, 'destroy']);

    // 04-02-2025
    Route::get('/get-product-scheme/{borrower_id}', [DashboardController::class, 'get_borrower_product_scheme']);

    // 08-02-2025
    Route::get('/get-vernacular-declaration-template', [HelperController::class, 'get_vernacular_declaration_template']);

    // 21-02-2025
    Route::get('/get-state-list', [StateController::class, 'index']); // List all states
    Route::get('/get-state-screener', [StateController::class, 'state_screener']); // List all states
    Route::post('/store-state', [StateController::class, 'store']); // Create a new state
    Route::get('/edit-state/{id}', [StateController::class, 'show']); // Show a specific state
    Route::post('/update-state/{id}', [StateController::class, 'update']); // Update a state
    // Route::delete('/delete-state/{id}', [StateController::class, 'destroy']); // Soft delete a state
    Route::post('/update-state-status/{id}', [StateController::class, 'changeStatus']);

    // 25-02-2025
    Route::post('/store-dealer-shop', [DealerShopsController::class, 'store'])->name('admin.store-dealer-shop');
    Route::post('/update-dealer-shop/{id}', [DealerShopsController::class, 'update'])->name('admin.update-dealer-shop');
    Route::get('/get-dealer-shops/{dealer_id}', [DealerShopsController::class, 'getShopsByDealerId'])->name('admin.get-dealer-shops');
    Route::post('/update-dealer-shop-status/{id}', [DealerShopsController::class, 'changeStatus'])->name('admin.update-dealer-shop-status');

    // 26-02-2025
    Route::get('/get-dealer-shops-fi/{borrower_id}', [DealerShopsController::class, 'get_dealer_shop_by_borrower_id']);
    
    // 27-02-2025
    Route::post('/raise-disbursement-request', [DisbursementController::class, 'raise_disbursement_request']);
    Route::get('/get-disbursement-request-list', [DisbursementController::class, 'disbursement_request_list']);

    Route::get('/get-borrower-loan-scheme/{borrower_id}', [DisbursementController::class, 'get_borrower_loan_scheme']);


    // 17-03-2025
    Route::get('/get-loan-amount-rules', [LoanAmountRulesController::class, 'index']);
    Route::post('/store-loan-amount-rule', [LoanAmountRulesController::class, 'store']);
    Route::get('/get-loan-amount-rule-details/{id}', [LoanAmountRulesController::class, 'show']); 
    Route::post('/update-loan-amount-rule/{id}', [LoanAmountRulesController::class, 'update']);
    // Route::post('/update-loan-amount-rule-status/{id}', [LoanAmountRulesController::class, 'changeStatus']);

    // 18-03-2025
    Route::get('/get-fips', [AccountAggregatorController::class, 'get_fips']);
    Route::post('/initiate-account-aggregator-request', [AccountAggregatorController::class, 'initiate_request_admin'])->name('admin.initiate-account-aggregator-request');

    // 25-03-2025

    Route::get('/get-bureau-report-download-link/{borrower_id}/{user_type}', [HelperController::class, 'get_bureau_report_download_link'])->name('admin.get-bureau-report-download-link');

    // 01-04-2025
    Route::get('/get-emi-chart', [EmiHelperController::class, 'getEmiSchedule'])->name('admin.get-emi-chart');
    Route::post('/initiate-emi-payment', [EasebuzzPaymentController::class, 'initiate_payment_admin']);
    // 03-04-2025
    // Route::get('/get-dealers-list', [DashboardController::class, 'get_dealers_list'])->name('admin.get-dealers-list');
    Route::get('/get-dealers-list', [AdminDashboardController::class, 'get_dealers_list'])->name('admin.get-dealers-list');

    // 04-04-2025
    Route::get('/get-export-file-types', [LenderBorrowerGroupController::class, 'getFileTypes']);
    Route::get('/get-lender-export-limit', [LenderBorrowerGroupController::class, 'getDownloadLimits']);
    Route::get('/get-document-export-limit', [LenderBorrowerGroupController::class, 'getDownloadLimits_admin']);
    Route::post('/store-doc-export-record', [LenderBorrowerGroupController::class, 'trackDownload']);
    Route::post('/update-lender-export-permission/{id}', [LenderBorrowerGroupController::class, 'approveDownloadLimitIncrease'])->name('admin.update-lender-export-permission');

    // 19-04-2025
    Route::post('/download-aa-excel-report', [AccountAggregatorController::class, 'download_account_aggregator_excel_report'])->name('admin.download-aa-excel-report');
    Route::post('/download-aa-pdf-report', [AccountAggregatorController::class, 'download_account_aggregator_pdf_report']);

    // 21-04-2025
    Route::get('/get-emi-payment-receipt/{receipt_no}', [EasebuzzPaymentController::class, 'get_emi_receipt'])->name('admin.get-emi-payment-receipt');
    // Route::get('/get-emi-payment-receipt/{receipt_no}', [EasebuzzPaymentController::class, 'get_emi_receipt']);


    // 26-04-2025
    Route::get('/get-signature-verification-certificate', [HelperController::class, 'get_signature_verification_certificate_template']);
    Route::get('/get-name-declaration-doc', [HelperController::class, 'get_name_declaration_doc']);
    Route::get('/get-dob-declaration', [HelperController::class, 'get_dob_declaration']);

    Route::post('/upload-offline-transaction-excel', [OfflineTransactionController::class, 'upload_excel_and_validate']);
    Route::get('/get-offline-transaction-uploaded-files', [OfflineTransactionController::class, 'get_uploaded_files']);
    Route::post('/approve-offline-transaction-excel/{id}', [OfflineTransactionController::class, 'approve_excel_and_process']);
    Route::post('/reject-offline-transaction-excel/{id}', [OfflineTransactionController::class, 'reject_product_excel']);
    Route::get('/get-offline-transaction-excel-template', [OfflineTransactionController::class, 'getTemplateDownloadLink']);
    Route::get('/offline-transaction/get-instruction', [OfflineTransactionController::class, 'getInstructions']);
    
    Route::get('/fetch-borrower-account-details', [EasebuzzPaymentController::class, 'fetch_account_details_for_enach']);
    Route::post('/initiate-pre-enach-request', [EasebuzzPaymentController::class, 'create_pre_enach_mandate']);
    Route::post('/initiate-post-enach-request', [EasebuzzPaymentController::class, 'create_enach_mandate']);
    
    // 17-05-2025
    Route::get('/get-enach-details', [EasebuzzPaymentController::class, 'get_borrower_account_enach_details']);
    Route::post('/skip-enach-step', [AdminLoanController::class, 'skip_nach_step']);
    Route::post('/store-enach-registration-details', [AdminLoanController::class, 'store_nach_registration_details']);
    Route::post('/prepare-enach-schedule', [EasebuzzPaymentController::class, 'create_enach_debit_schedule']);

    // 22-05-2025
    Route::post('/grant-enach-skip-permission', [BorrowerAccountDetailsController::class, 'grant_enach_skip_permission']);
    Route::post('/revoke-enach-skip-permission', [BorrowerAccountDetailsController::class, 'revoke_enach_skip_permission']);

    // Borrower cheques // 28-05-2025
    Route::get('/get-borrower-cheques/{borrower_id}', [BorrowerChequeController::class, 'show_borrower_cheques'])->name('admin.get-borrower-cheques');
    Route::post('/store-borrower-cheques', [BorrowerChequeController::class, 'store_borrower_cheques'])->name('admin.store-borrower-cheques');
    Route::post('/update-borrower-cheque/{id}', [BorrowerChequeController::class, 'update_borrower_cheque']);
    Route::post('/update-cheque-status/{id}', [BorrowerChequeController::class, 'update_cheque_status'])->name('admin.update-cheque-status');
    Route::post('/update-cheque-used-status/{id}', [BorrowerChequeController::class, 'update_cheque_used_status'])->name('admin.update-cheque-used-status');

    // 04-06-2025
    Route::get('/get-borrower-aa-records', [AccountAggregatorController::class, 'get_borrowers_aa_records'])->name('admin.get-borrower-aa-records');

    // 05-06-2025
    Route::post('/grant-aa-skip-permission', [BorrowerAccountDetailsController::class, 'grant_aa_skip_permission'])->name('admin.grant-aa-skip-permission');
    Route::post('/revoke-aa-skip-permission', [BorrowerAccountDetailsController::class, 'revoke_aa_skip_permission'])->name('admin.revoke-aa-skip-permission');
    Route::get('/check-aa-status', [AccountAggregatorController::class, 'check_aa_status']);

    // 09-06-2025

    Route::get('/get-monthly-payment-report', [EasebuzzPaymentController::class, 'get_monthly_payment_report']);
    Route::get('/get-monthly-payment-summary', [PaymentSummaryController::class, 'get_payment_summary_report']);

    // 17-06-2025
    Route::get('/get-sales-executive-list', [SalesExecutiveDealerController::class, 'salesExecutiveScreener']);
    Route::get('/get-sales-executive-dealer-groups', [SalesExecutiveDealerController::class, 'index']);
    Route::post('/store-sales-executive-dealer-group', [SalesExecutiveDealerController::class, 'store']);
    Route::post('/update-sales-executive-dealer-group/{id}', [SalesExecutiveDealerController::class, 'update']);
    Route::get('/delete-sales-executive-dealer-group/{id}', [SalesExecutiveDealerController::class, 'destroy']);
    Route::get('/get-dealers-by-sales-executive/{executive_id}', [SalesExecutiveDealerController::class, 'getDealersByExecutive']);

    Route::get('/get-applicant-address-data', [BorrowerController::class, 'get_applicant_address_data']);

    // 24-01-2026
    Route::get('/get-admin-users', [MakerCheckerAuthorizationController::class, 'get_admin_users'])->name('admin.get-admin-users');

    Route::get('/borrower-list-new', [AdminDashboardController::class, 'get_borrower_list']);

    // loan step back request apis
    Route::get('/get-loan-application-steps', [LoanStepBackRequestController::class, 'get_loan_application_step']);
    Route::prefix('module-authorization')->group(function () {
        Route::post('/create-authorization', [MakerCheckerAuthorizationController::class, 'create'])->name('admin.ma-create-authorization');
        Route::get('/edit-authorization', [MakerCheckerAuthorizationController::class, 'edit'])->name('admin.ma-edit-authorization');
        Route::post('/update-authorization', [MakerCheckerAuthorizationController::class, 'update'])->name('admin.ma-update-authorizarion');
        Route::post('/update-status', [MakerCheckerAuthorizationController::class, 'statusChange'])->name('admin.ma-update-status');
        Route::post('/delete-authorization', [MakerCheckerAuthorizationController::class, 'delete'])->name('admin.ma-delete-authorization');
        Route::get('/authorization-list', [MakerCheckerAuthorizationController::class, 'list'])->name('admin.ma-authorization-list');
        Route::get('/functionality-list', [MakerCheckerAuthorizationController::class, 'get_module_functionality_list'])->name('admin.ma-functionality-list');
        Route::get('/get-authorizations', [MakerCheckerAuthorizationController::class, 'get_authorizations'])->name('admin.ma-get-authorizations');
    });
    Route::prefix('loan-step-back')->group(function () {
        Route::post('/create-request', [LoanStepBackRequestController::class, 'create'])->name('loan_step_back.create_request');
        Route::get('/request-list', [LoanStepBackRequestController::class, 'list'])->name('admin.lsb-request-list');
        Route::post('/r1-update-status', [LoanStepBackRequestController::class, 'r1UpdateStatus']);
        Route::post('/r2-update-status', [LoanStepBackRequestController::class, 'r2UpdateStatus']);
        Route::post('/delete-request', [LoanStepBackRequestController::class, 'delete'])->name('admin.lsb-delete-request');
    });

    Route::get('/preview-agreement/{borrower_id}', [LoanUsersController::class, 'generate_pre_signed_agreement_for_preview'])->name('admin.preview-agreement');
    Route::post('/allow-application-to-proceed', [LoanUsersController::class, 'allow_application_to_proceed'])->name('admin.allow-application-to-proceed');

    Route::prefix('emi')->group(function () {
        // ── Manual triggers ───────────────────────────────────────────────
         // Route::post('trigger',        [EmiPresentmentController::class, 'triggerManualPresentmentRun']);
         // Route::post('retry-failed',   [EmiPresentmentController::class, 'retryFailedPresentments']);
         Route::post('trigger-single', [EmiPresentmentController::class, 'triggerSinglePresentment']);
         // Route::get('summary',         [EmiPresentmentController::class, 'presentmentSummary']);

         // ── Debit request list ────────────────────────────────────────────
         // Route::get('debit-requests',       [DebitRequestController::class, 'index']);
         // Route::get('debit-requests/{id}',  [DebitRequestController::class, 'show']);
         Route::get('debit-requests/export', [DebitRequestController::class, 'export'])->name('admin.emi-export-payment-report');
     
    });

    // 08-02-2026
    Route::prefix('impact-dashboard')->group(function () {
        Route::get('/cibil-distribution', [ImpactDashboardController::class, 'cibilDistribution']);
        Route::get('/inclustion-stat', [ImpactDashboardController::class, 'inclusionStats']);
        Route::get('/lively-hood-impacted', [ImpactDashboardController::class, 'livelyHoodImpact']);
        Route::get('/portfolio-at-risk', [ImpactDashboardController::class, 'portfolioAtRisk']);
        Route::get('/cibil-journey', [ImpactDashboardController::class, 'cibilJourney']);
        Route::get('/payment-mode-stats', [ImpactDashboardController::class, 'paymentModeStats']);
        Route::get('/residence-stats', [ImpactDashboardController::class, 'homeStayStats']);
        Route::get('/income-impact-stats', [ImpactDashboardController::class, 'incomeImpact']);
        Route::get('/vehicle-type-stats', [ImpactDashboardController::class, 'vehicleTypeStats']);
        Route::get('/gender-split-stats', [ImpactDashboardController::class, 'borrowerGenderSplit']);
        Route::get('/impact-dashboard-stats', [ImpactDashboardController::class, 'portfolioSnapshot']);
        Route::get('/states-data-stats', [ImpactDashboardController::class, 'stateDistribution']);
        Route::get('/risk-profiles', [ImpactDashboardController::class, 'riskReport']);
    });

    Route::prefix('identifier')->group(function () {
        Route::post('/upload-excel', [BorrowerIdentifierUploadController::class, 'uploadExcel']);
        Route::get('/upload-excel-list', [BorrowerIdentifierUploadController::class, 'getUploads']);
        Route::post('/approve-reject-excel/{id}', [BorrowerIdentifierUploadController::class, 'changeStatus']);
        Route::post('/change-status/{id}', [BorrowerIdentifierUploadController::class, 'toggleRowStatus']);
        Route::post('/delete-excel/{id}', [BorrowerIdentifierUploadController::class, 'deleteUpload']);
        Route::get('/download-sample-excel', [BorrowerIdentifierUploadController::class, 'download_sample_excel']);
    }); 

    // 18-03-2026
    // ->name('user-hierarchy.')
    Route::prefix('user-hierarchy')->group(function () {
        // Route::post('relationships', [UserHierarchyController::class, 'addRelationship'])
        //     ->name('relationships.add');

        // Route::delete('relationships', [UserHierarchyController::class, 'removeRelationship'])
        //     ->name('relationships.remove');

        // Route::post('relationships/deactivate', [UserHierarchyController::class, 'deactivateRelationship'])
        //     ->name('relationships.deactivate');

        // Route::get('descendants/{userId}', [UserHierarchyController::class, 'getDescendants'])
        //     ->name('descendants')->whereNumber('userId');

        // Route::get('ancestors/{userId}', [UserHierarchyController::class, 'getAncestors'])
        //     ->name('ancestors')->whereNumber('userId');

        // Route::get('children/{userId}', [UserHierarchyController::class, 'getDirectChildren'])
        //     ->name('children')->whereNumber('userId');
        Route::post('relationships', [UserHierarchyController::class, 'addRelationship']);

        Route::delete('relationships', [UserHierarchyController::class, 'removeRelationship']);

        Route::post('relationships/deactivate', [UserHierarchyController::class, 'deactivateRelationship']);
        Route::post('relationships/activate', [UserHierarchyController::class, 'activateRelationship'])
            ->name('relationships.activate');

        // Bulk child operations
        Route::post('bulk-children', [UserHierarchyController::class, 'bulkAddChildren']);
            // ->name('bulk-children.add');
        Route::post('update-bulk-children', [UserHierarchyController::class, 'bulkManageChildren']);
            // ->name('bulk-children.manage');

        // List — parents with child counts
        Route::get('parents', [UserHierarchyController::class, 'parentList']);
            // ->name('parents.index');
 
        // List — children of one parent (drill-down)
        Route::get('parents/{userId}/children', [UserHierarchyController::class, 'childList'])
            ->whereNumber('userId'); // ->name('parents.children')

        Route::get('/get-my-users', [UserHierarchyController::class, 'myChildList']); // ->name('parents.mychildren')
 
        // Full subtree helpers
        Route::get('descendants/{userId}', [UserHierarchyController::class, 'getDescendants'])
            ->whereNumber('userId'); // ->name('descendants')
        Route::get('ancestors/{userId}', [UserHierarchyController::class, 'getAncestors'])
            ->whereNumber('userId'); // ->name('ancestors')
    });

    // 18-03-2026
    // ->name('dealer-mapping.')
    Route::prefix('dealer-mapping')->group(function () {

        // Route::post('/', [DealerMappingController::class, 'store'])
        //     ->name('store');

        // Route::post('/{id}', [DealerMappingController::class, 'update'])
        //     ->name('update')->whereNumber('id');

        // Route::post('/{id}/deactivate', [DealerMappingController::class, 'deactivate'])
        //     ->name('deactivate')->whereNumber('id');

        // Route::post('/{id}/activate', [DealerMappingController::class, 'activate'])
        //     ->name('activate')->whereNumber('id');

        // Route::delete('/{id}', [DealerMappingController::class, 'destroy'])
        //     ->name('destroy')->whereNumber('id');

        // Route::get('/by-user/{userId}', [DealerMappingController::class, 'byUser'])
        //     ->name('by-user')->whereNumber('userId');

        // Route::get('/by-dealer/{dealerUnq}', [DealerMappingController::class, 'byDealer'])
        //     ->name('by-dealer');

        // Route::get('/visible-dealers/{userId}', [DealerMappingController::class, 'visibleDealers'])
        //     ->name('visible-dealers')->whereNumber('userId');
        Route::post('/', [DealerMappingController::class, 'store']);

        Route::post('/{id}', [DealerMappingController::class, 'update'])->whereNumber('id');

        Route::post('/{id}/deactivate', [DealerMappingController::class, 'deactivate'])->whereNumber('id');

        Route::post('/{id}/activate', [DealerMappingController::class, 'activate'])->whereNumber('id');

        Route::delete('/{id}', [DealerMappingController::class, 'destroy'])->whereNumber('id');

        Route::get('/by-user/{userId}', [DealerMappingController::class, 'byUser'])->whereNumber('userId');

        Route::get('/by-dealer/{dealerUnq}', [DealerMappingController::class, 'byDealer']);

        Route::get('/visible-dealers/{userId}', [DealerMappingController::class, 'visibleDealers'])->whereNumber('userId');

        // List — users with dealer counts
        Route::get('/users', [DealerMappingController::class, 'userMappingSummary']);
        // Route::get('/users', [DealerMappingController::class, 'userMappingSummary'])
            // ->name('users.summary');
    });

    // 18-03-2026
    // ->name('full-access.')
    Route::prefix('full-access')->group(function () {

        // User-type config
        // Route::get('user-types', [FullAccessController::class, 'listUserTypes'])
        //     ->name('user-types.index');
        // Route::post('user-types', [FullAccessController::class, 'grantUserTypes'])
        //     ->name('user-types.grant');
        // Route::delete('user-types/{userTypeId}', [FullAccessController::class, 'revokeUserType'])
        //     ->name('user-types.revoke')->whereNumber('userTypeId');
        // Route::delete('user-types/{userTypeId}/bulk-revoke', [FullAccessController::class, 'revokeUserTypeAndUsers'])
        //     ->name('user-types.bulk-revoke')->whereNumber('userTypeId');

        // // Individual user flag
        // Route::get('users', [FullAccessController::class, 'listUsers'])
        //     ->name('users.index');
        // Route::post('users/{userId}/grant', [FullAccessController::class, 'grantUser'])
        //     ->name('users.grant')->whereNumber('userId');
        // Route::post('users/{userId}/revoke', [FullAccessController::class, 'revokeUser'])
        //     ->name('users.revoke')->whereNumber('userId');
        Route::get('user-types', [FullAccessController::class, 'listUserTypes']);
        Route::post('user-types', [FullAccessController::class, 'grantUserTypes']);
        Route::delete('user-types/{userTypeId}', [FullAccessController::class, 'revokeUserType'])
            ->whereNumber('userTypeId');
        Route::delete('user-types/{userTypeId}/bulk-revoke', [FullAccessController::class, 'revokeUserTypeAndUsers'])
            ->whereNumber('userTypeId');

        // Individual user flag
        Route::get('users', [FullAccessController::class, 'listUsers']);
        Route::post('users/{userId}/grant', [FullAccessController::class, 'grantUser'])
            ->whereNumber('userId');
        Route::post('users/{userId}/revoke', [FullAccessController::class, 'revokeUser'])
            ->whereNumber('userId');
        Route::post('users/bulk', [FullAccessController::class, 'bulkManageUsers'])
            ->name('users.bulk');
    });

    Route::prefix('reports')->group(function () {
        Route::get('/lmr-report', [ReportsController::class, 'exportLmrReport'])->name('report.lmr-report');

        // 21-05-2026
        Route::get('/disbursement-report', [ReportsController::class, 'exportDisbursementReport'])->name('report.disbursement-report');
        Route::get('/dpd-npa-report', [ReportsController::class, 'exportDpdNpaReport']);

        // 26-05-2026
        Route::get('/lar-report', [ReportsController::class, 'exportLarReport'])->name('report.lar-report');

        // 02-06-2026
        Route::get('/export-dealers', [DealerReportController::class, 'export']); // ->name('report.dealer-report');

        Route::get('/loan-application-report', [LoanApplicationReportController::class, 'exportLoanApplicationReport'])->name('report.lender-borrower-report');
    });


    Route::prefix('subvention-import')->group(function () {
        // ── Excel bulk upload ─────────────────────────────────────────────────
        Route::get('/template',           [SubventionUploadController::class, 'downloadTemplate'])->name('admin.subvention-import-template');
        Route::post('/upload',            [SubventionUploadController::class, 'upload'])->name('admin.subvention-upload-excel');
        Route::get('/get-uploads',               [SubventionUploadController::class, 'uploadLogs'])->name('admin.subvention-get-uploads');
        // Route::get('/excel/logs/{id}',          [SubventionUploadController::class, 'uploadLogDetail']);
        Route::post('/action/{id}',  [SubventionUploadController::class, 'action'])->whereNumber('id')->name('admin.subvention-import-action');
    });

    Route::prefix('aa/')->group(function () {
        // FIP list for a given lender
        Route::get('/fips', [AccountAggregatorEntityController::class, 'get_fips']);
            // ->name('fips');

        // Fetch dealer details pre-filled for the AA form
        Route::get('/dealer-details', [AccountAggregatorEntityController::class, 'get_dealer_details_for_account_aggregator']);
            // ->name('dealer.details');

        // Initiate an AA consent request for an entity
        Route::post('/initiate', [AccountAggregatorEntityController::class, 'initiate_request'])
            ->name('admin.initiate-entity-aa');

        // Download reports (latest completed request for the entity)
        Route::get('/download-excel', [AccountAggregatorEntityController::class, 'download_excel_report'])
            ->name('admin.download-excel');

        Route::get('/download-pdf', [AccountAggregatorEntityController::class, 'download_pdf_report']);
            // ->name('download.pdf');
    });

    Route::get('/get-rsm-screener', [AdminDashboardController::class, 'get_rsm_screener']);

    Route::prefix('payment-head/')->group(function () {
        Route::post('/store', [PaymentHeadController::class, 'store']);
        Route::get('/list', [PaymentHeadController::class, 'index']);
        Route::get('/list-screener', [PaymentHeadController::class, 'screener']);
        Route::get('/get-head/{id}', [PaymentHeadController::class, 'show'])->whereNumber('id');
        Route::post('/update/{id}', [PaymentHeadController::class, 'update'])->whereNumber('id');
        Route::post('/update-status/{id}', [PaymentHeadController::class, 'toggleStatus'])->whereNumber('id');
    });
    Route::prefix('payment-head-group/')->group(function () {
        Route::post('/store', [PaymentHeadGroupController::class, 'store']);
        Route::get('/list', [PaymentHeadGroupController::class, 'index']);
        Route::get('/details/{id}', [PaymentHeadGroupController::class, 'show'])->whereNumber('id');
        Route::post('/update/{id}', [PaymentHeadGroupController::class, 'update'])->whereNumber('id');
        Route::post('/update-status/{id}', [PaymentHeadGroupController::class, 'toggleStatus'])->whereNumber('id');
    });

    Route::prefix('enach/')->group(function () {
        Route::get('/get-setting', [SystemSettingController::class, 'enach']);
        Route::post('/update-setting', [SystemSettingController::class, 'updateEnachAuthMode'])->name('admin.enach-update-setting');
    });

    Route::get('/get-form-twenty', [HelperController::class, 'get_form_twenty']);
    Route::post('/store-cibil-report', [CibilReportController::class, 'store']);

    Route::prefix('dynamic-products')->group(function () {
        Route::get('/',                [DynamicProductController::class, 'index']); // ->name('admin.dynamic-products');
        Route::post('/',               [DynamicProductController::class, 'store']); //->name('admin.store-dynamic-product');
        Route::post('/bulk-assign', [DealerProductController::class, 'bulkAssign']);
        Route::get('/{id}',            [DynamicProductController::class, 'show']);        // active set only
        Route::get('/{id}/rules',      [DynamicProductController::class, 'rules']);       // active rules for edit form
        Route::get('/{id}/history',    [DynamicProductController::class, 'history']);     // all versions (audit)
        Route::post('/{id}',           [DynamicProductController::class, 'update']); //->name('admin.update-dynamic-product');      // update product fields
        Route::post('/{id}/rules',     [DynamicProductController::class, 'authorRules']); // publish new rule version
        Route::post('/{id}/status',    [DynamicProductController::class, 'changeStatus']); //->name('admin.update-dynamic-product-status'); // GET/POST only — was PATCH
        Route::get('/{id}/view',       [DynamicProductController::class, 'view']); // clean, display-ready
    });

    // Route::post('/update-oem-product-details/{id}', [OemProductController::class, 'update']);
    // Route::post('/update-oem-product-status/{id}', [OemProductController::class, 'changeStatus']);
    // Route::get('/get-oem-product-details/{id}', [OemProductController::class, 'show']);
    // Route::post('/store-oem-product', [OemProductController::class, 'store']);
    
    // OEM products
    Route::get('/get-oem-dealer-product-list', [OemProductController::class, 'index']);
    Route::post('/store-oem-product', [DynamicProductController::class, 'store']);
    Route::get('/get-oem-product-details/{id}', [DynamicProductController::class, 'show']);
    Route::post('/update-oem-product-details/{id}', [DynamicProductController::class, 'update']);
    Route::post('/update-oem-product-status/{id}', [DynamicProductController::class, 'changeStatus']);
    
    // OEM Dashboard Apis
    Route::get('get-oem-dealer-list', [OemController::class, 'get_oem_dealer_list']);
    Route::get('get-oem-borrower-list', [OemController::class, 'get_oem_borrower_list']);
    Route::get('get-oem-dashboard-details', [OemController::class, 'get_oem_dashboard_details']);
    Route::get('get-oem-yearly-counts', [OemController::class, 'get_oem_yearly_count']);
    // Route::get('get-oem-product-list', [OemController::class, 'get_oem_product_list']);
    Route::get('get-oem-product-list', [DynamicProductController::class, 'index']);
    // Route::get('/get-oem-own-products', [OemController::class, 'get_oem_own_products']);
    Route::get('/get-oem-own-products', [OemController::class, 'get_oem_product_list']);

    Route::get('oems/{oemId}/dealers', [DealerProductController::class, 'dealersByOem']);
    Route::prefix('dynamic-products/{productId}/dealers')->group(function () {
        Route::get('/',              [DealerProductController::class, 'dealers']);
        Route::post('/assign',       [DealerProductController::class, 'assign']); //->name('admin.store-dynamic-product-dealer-group');   // {dealer_ids:[...]}
        Route::post('/toggle',       [DealerProductController::class, 'toggle']);   // {dealer_id}
        Route::post('/remove',       [DealerProductController::class, 'remove']);   // {dealer_id}

        // dealer-specific scheme (override)
        Route::get('/{dealerId}/rules',  [DealerProductController::class, 'dealerRules']);
        Route::post('/{dealerId}/rules', [DealerProductController::class, 'authorDealerRules']); // {rules:[...]}
    });

    Route::prefix('dynamic-products/excel')->group(function () {
        Route::post('/upload',        [OemProductExcelController::class, 'upload_excel_and_validate']);
        Route::post('/{id}/approve',  [OemProductExcelController::class, 'approve_excel_and_process']);
        Route::post('/{id}/reject',   [OemProductExcelController::class, 'reject_excel']);
        Route::get('/files',          [OemProductExcelController::class, 'get_uploaded_files']);
        Route::get('/template',       [OemProductExcelController::class, 'get_template']);
    });

    // Route::post('/upload-oem-product-excel', [OemProductController::class, 'upload_excel_and_validate']);
    // Route::get('/get-oem-uploaded-files', [OemProductController::class, 'get_uploaded_files']);
    // Route::post('/approve-oem-product-excel/{id}', [OemProductController::class, 'approve_excel_and_process']);
    // Route::post('/reject-oem-product-excel/{id}', [OemProductController::class, 'reject_product_excel']);

    // oem product upload excel 03-01-2025
    Route::post('/upload-oem-product-excel', [OemProductExcelController::class, 'upload_excel_and_validate']);
    Route::get('/get-oem-uploaded-files', [OemProductExcelController::class, 'get_uploaded_files']);
    Route::post('/approve-oem-product-excel/{id}', [OemProductExcelController::class, 'approve_excel_and_process']);
    Route::post('/reject-oem-product-excel/{id}', [OemProductExcelController::class, 'reject_excel']);
    Route::get('/get-oem-product-excel-template', [OemProductController::class, 'get_oem_product_excel_template']);

    Route::prefix('delivery-order')->group(function () {
        Route::post('/details',     [DeliveryOrderController::class, 'getDetails']);
        Route::post('/generate',    [DeliveryOrderController::class, 'generate']);
        Route::get('/preview/{do}', [DeliveryOrderController::class, 'preview']);
        Route::post('/send',        [DeliveryOrderController::class, 'send']);
    });

    Route::prefix('borrower-enach-methods')->group(function () {
        Route::post('/', [BorrowerEnachMethodController::class, 'store']);
        Route::get('/', [BorrowerEnachMethodController::class, 'index']);
        Route::get('/{borrowerId}', [BorrowerEnachMethodController::class, 'getByBorrower']);
    });

    // 05-09-2026
    Route::prefix('borrower-edit')->group(function () {
    Route::get ('editable-details/{borrower_id}', [BorrowerEditRequestController::class, 'get_editable_details']); //->name('borrower_edit.editable-details');
    Route::post('create',        [BorrowerEditRequestController::class, 'create'])->name('borrower_edit.create-request');
    Route::get('list',          [BorrowerEditRequestController::class, 'list']); // ->name('borrower_edit.list');
    Route::post('detail',        [BorrowerEditRequestController::class, 'edit']); // ->name('borrower_edit.detail');
    Route::post('changes',       [BorrowerEditRequestController::class, 'get_changes']); // ->name('borrower_edit.changes');
    Route::post('r1-status',     [BorrowerEditRequestController::class, 'r1UpdateStatus']); // ->name('borrower_edit.r1-status');
    Route::post('r2-status',     [BorrowerEditRequestController::class, 'r2UpdateStatus']); // ->name('borrower_edit.r2-status');
    Route::post('delete',        [BorrowerEditRequestController::class, 'delete']); // ->name('borrower_edit.delete');
    Route::post('status-change', [BorrowerEditRequestController::class, 'statusChange']); // ->name('borrower_edit.status-change');
    Route::post('history',       [BorrowerEditRequestController::class, 'history']); // ->name('borrower_edit.history');
});
});

Route::get('delivery-order/download/{token}', [DeliveryOrderController::class, 'download'])
    ->name('delivery-order.download'); // public, no auth

    Route::post('/store-sales-executive-dealer-group', [SalesExecutiveDealerController::class, 'store']);
    Route::get('/subvention-import/template', [SubventionUploadController::class, 'downloadTemplate']);
    
    
Route::get('/get-signature-verification-certificate', [HelperController::class, 'get_signature_verification_certificate_template']);

Route::middleware('auth:admin')->prefix('v2')->group(function () {
    Route::post('/admin-borrower-registration', [UatUserController::class, 'borrower_registration']);
    Route::post('/admin-verify-borrower-registration-otp', [UatUserController::class, 'verify_borrower_registration_otp']);
    Route::post('/admin-send-borrower-otp', [UatUserController::class, 'send_borrower_otp']);
    Route::post('/admin-verify-borrower-otp', [UatUserController::class, 'verify_borrower_otp']);

    // UAT service api    
    Route::post('/admin-verify-aadhar', [UatServiceApiController::class, 'aadhar_verify']);
    Route::post('/admin-verify-aadhar-using-otp', [UatServiceApiController::class, 'verify_aadhar_using_otp']);
    Route::post('/admin-get-credit-score-details', [UatServiceApiController::class, 'get_credit_score_details']);
    Route::post('/admin-verify-pan-details', [UatServiceApiController::class, 'pan_details_verification']);
    Route::post('/admin-pan-ocr-check', [UatServiceApiController::class, 'pan_ocr_api']);
    Route::post('/admin-cheque-ocr', [UatServiceApiController::class, 'cheque_ocr']);
    Route::post('/admin-aadhar-ocr-check', [UatServiceApiController::class, 'aadhar_ocr_api']);
    Route::post('/admin-verify-driving-licence', [UatServiceApiController::class, 'verify_driving_licence']);
    
    Route::post('/admin-generate-vkyc-url', [UatServiceApiController::class, 'video_kyc_generate_url']);
    // Route::post('/admin-vkyc-transaction-details', [UatServiceApiController::class, 'vkyc_transaction_details']);
    Route::post('/admin-get-vkyc-call-details', [UatServiceApiController::class, 'get_vkyc_status_by_application_number']);
    Route::post('/admin-generate-esign', [UatServiceApiController::class, 'generate_esign']);
    Route::post('/admin-download-esigned-doc', [UatServiceApiController::class, 'download_esign_doc']);
    Route::post('/admin-check-iot-activation', [UatServiceApiController::class, 'iot_activition_check']);
});

// For Live
Route::middleware('auth:admin')->prefix('v1')->group(function () {
    Route::post('/admin-borrower-registration', [AdminUserController::class, 'borrower_registration'])->name('admin.admin-borrower-registration');
    Route::post('/admin-verify-borrower-registration-otp', [AdminUserController::class, 'verify_borrower_registration_otp']);
    Route::post('/admin-send-borrower-otp', [AdminUserController::class, 'send_borrower_otp']);
    Route::post('/admin-verify-borrower-otp', [AdminUserController::class, 'verify_borrower_otp']);

    // UAT service api    
    Route::post('/admin-verify-aadhar', [AdminServiceApiController::class, 'aadhar_verify']);
    Route::post('/admin-verify-aadhar-using-otp', [AdminServiceApiController::class, 'verify_aadhar_using_otp']);
    Route::post('/admin-get-credit-score-details', [AdminServiceApiController::class, 'get_credit_score_details']);
    Route::post('/admin-verify-pan-details', [AdminServiceApiController::class, 'pan_details_verification']);
    Route::post('/admin-pan-ocr-check', [AdminServiceApiController::class, 'pan_ocr_api']);
    Route::post('/admin-cheque-ocr', [AdminServiceApiController::class, 'cheque_ocr']);
    Route::post('/admin-aadhar-ocr-check', [AdminServiceApiController::class, 'aadhar_ocr_api']);
    Route::post('/admin-verify-driving-licence', [AdminServiceApiController::class, 'verify_driving_licence']);
    
    Route::post('/admin-generate-vkyc-url', [AdminServiceApiController::class, 'video_kyc_generate_url']);
    // Route::post('/admin-vkyc-transaction-details', [AdminServiceApiController::class, 'vkyc_transaction_details']);
    Route::post('/admin-get-vkyc-call-details', [AdminServiceApiController::class, 'get_vkyc_status_by_application_number']);
    Route::post('/admin-generate-esign', [AdminServiceApiController::class, 'generate_esign']);
    Route::post('/admin-download-esigned-doc', [AdminServiceApiController::class, 'download_esign_doc']);
    Route::post('/admin-check-iot-activation', [AdminServiceApiController::class, 'iot_activition_check']);

    Route::prefix('admin-digilocker')->group(function () {
        Route::post('generate-url', [AdminServiceApiController::class, 'digilocker_generate_url']);
        Route::post('status',       [AdminServiceApiController::class, 'digilocker_status']);
        Route::post('details',      [AdminServiceApiController::class, 'digilocker_details']);
    });
});

Route::group([
    'middleware'=>'api', 'prefix'=>'v2'
], function(){
    // For B2C dummy cases
    Route::post('/register', [UatAuthController::class, 'register']);
    Route::post('/verify-registration-otp', [UatAuthController::class, 'verifyRegistrationMobileOtp']);
    Route::post('/send-otp', [UatAuthController::class, 'sendOtp']);
    Route::post('/login', [UatAuthController::class, 'login']);

    // UAT service api    
    Route::post('/verify-aadhar', [B2cUatServiceApiController::class, 'aadhar_verify']);
    Route::post('/store-reference-details', [LoanController::class, 'store_reference_details_b2c_uat']);
    Route::post('/store-field-verification-details', [LoanController::class, 'store_field_verification_details_b2c_uat']);
    Route::post('/verify-aadhar-using-otp', [B2cUatServiceApiController::class, 'verify_aadhar_using_otp']);
    Route::post('/get-credit-score-details', [B2cUatServiceApiController::class, 'get_credit_score_details']);
    Route::post('/verify-pan-details', [B2cUatServiceApiController::class, 'pan_details_verification']);
    Route::post('/pan-ocr-check', [B2cUatServiceApiController::class, 'pan_ocr_api']);
    Route::post('/aadhar-ocr-check', [B2cUatServiceApiController::class, 'aadhar_ocr_api']);
    Route::post('/cheque-ocr', [B2cUatServiceApiController::class, 'cheque_ocr']);
    Route::post('/verify-driving-licence', [B2cUatServiceApiController::class, 'verify_driving_licence']);
    
    Route::post('/generate-vkyc-url', [B2cUatServiceApiController::class, 'video_kyc_generate_url']);
    // Route::post('/vkyc-transaction-details', [B2cUatServiceApiController::class, 'vkyc_transaction_details']);
    Route::post('/get-vkyc-call-details', [B2cUatServiceApiController::class, 'get_vkyc_status_by_application_number']);
    Route::post('/generate-esign', [B2cUatServiceApiController::class, 'generate_esign']);
    Route::post('/download-esigned-doc', [B2cUatServiceApiController::class, 'download_esign_doc']);
    Route::post('/check-iot-activation', [B2cUatServiceApiController::class, 'iot_activition_check']);

    Route::prefix('digilocker')->group(function () {
        Route::post('generate-url', [ServiceApiController::class, 'digilocker_generate_url']);
        Route::post('status',       [ServiceApiController::class, 'digilocker_status']);
        Route::post('details',      [ServiceApiController::class, 'digilocker_details']);
    });
});


    Route::get('/test-device-id', [HelperController::class, 'yourApiMethod']);
    Route::get('/excel-upload'  , [ExcelUploadController::class, 'index']);
