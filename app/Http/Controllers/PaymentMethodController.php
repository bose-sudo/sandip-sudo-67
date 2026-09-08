<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethods;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentMethodController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }
    
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'nullable|regex:/^[a-zA-Z0-9\s,\-.]+$/',
            'page' => 'nullable|integer|min:1',
            'number_of_records' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $query = PaymentMethods::with(['updatedBy:id,name,email']);

            if ($request->filled('payment_method')) {
                $query->whereRaw('LOWER(payment_method) LIKE ?', ['%' . strtolower($request->payment_method) . '%']);
            }

            // Pagination
            $page = $request->input('page', 1);
            $number_of_records = $request->input('number_of_records', 10);
            $data = $query->orderBy('payment_method', 'asc')->paginate($number_of_records, ['*'], 'page', $page);

            return $this->helper_controller->send_response('success', 'Data fetched successfully', 200, $data);
            
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to fetch records: ' . $e->getMessage(), 422);
        }
    }
    
    public function get_payment_method_screener() {
        $query = PaymentMethods::select(['id', 'payment_method'])
                    ->where('status', 'active')
                    ->orderBy('payment_method', 'asc')
                    ->get();

        return $this->helper_controller->send_response('success', 'Data fetched successfully', 200, $query);
    }

    public function store(Request $request) {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $payment_method                 = new PaymentMethods();
            $payment_method->payment_method = $request->payment_method;
            $payment_method->ip             = $this->helper_controller->get_user_ip();
            $payment_method->updated_by     = auth()->id();
            $payment_method->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Payment method added successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to create record: ' . $e->getMessage(), 422);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $payment_method = PaymentMethods::find($id);
            if(!$payment_method) {
                return $this->helper_controller->send_response('failed', "Entry not found", 422);
            }

            $payment_method->payment_method     = $request->payment_method;
            $payment_method->ip                 = $this->helper_controller->get_user_ip(); // Use helper for IP
            $payment_method->updated_by         = auth()->id();
            $payment_method->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Payment method updated successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to update record: ' . $e->getMessage(), 422);
        }
    }

    public function update_status(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $payment_method = PaymentMethods::find($id);
            if(!$payment_method) {
                return $this->helper_controller->send_response('failed', "Entry not found", 422);
            }
            $payment_method->status     = ($payment_method->status === 'active') ? 'inactive' : 'active';
            $payment_method->updated_by = auth()->id();
            $payment_method->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Status updated successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to update record: ' . $e->getMessage(), 422);
        }
    }
}
