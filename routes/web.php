<?php

use App\Http\Controllers\WebController;
use App\Http\Controllers\EasebuzzPaymentController;
use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return '<center><h1>Welcome to Finayo Uat Server</h1></center>'; //view('welcome');
});

Route::get('/risk-report', function () {
    return view('risk-report');
});

Route::get('/split-agreement/{borrower_id}', [WebController::class, 'splitAgreement']);

Route::get('/reference/{str}', [WebController::class, 'reference_check']);
Route::get('/field-inspecction', [WebController::class, 'field_inspection']);
Route::get('/field-inspecction/{str}', [WebController::class, 'field_inspection']);
Route::get('/generate-pdf', [WebController::class, 'generatePdf']);
// Route::get('/credit-report/{mobile}/{name}/{dob}/{pan}/{pin}/{access_token}', [WebController::class, 'get_credit_score_details_web']);

Route::get('/generate-credit-report', [WebController::class, 'credit_report_form'])->name('credit_report_form');
// Route::get('/credit-report/{mobile}/{name}/{dob}/{pan}/{pin}/{access_token}', [WebController::class, 'get_credit_score_details_web']);
Route::post('/credit-report', [WebController::class, 'get_credit_score_details_web'])->name('generate_credit_report');
Route::get('/update-borrower-data', [WebController::class, 'update_borrower_data']);
Route::get('/mandate/view/{transactionId}', [EasebuzzPaymentController::class, 'showMandate']);
// Route::get('/test-sms/{type}', [WebController::class, 'testNewSms']);
