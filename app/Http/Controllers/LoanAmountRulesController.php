<?php

namespace App\Http\Controllers;

use App\Models\LoanAmountRules;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoanAmountRulesController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_type_id' => 'nullable|integer|exists:product_types,id',
                'min_loan_amount' => 'nullable|numeric|min:0',
                'max_loan_amount' => 'nullable|numeric|min:0|gte:min_loan_amount',
                'min_loan_tenure' => 'nullable|integer|min:1',
                'max_loan_tenure' => 'nullable|integer|min:1|gte:min_loan_tenure',
                'page' => 'nullable|integer|min:1',
                'number_of_records' => 'nullable|integer|min:1|max:100'
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $rules = LoanAmountRules::with(['modifiedBy:id,name', 'productType:id,product_type']);

            if ($request->filled('product_type_id')) {
                $rules->where('product_type_id', $request->product_type_id);
            }
            if ($request->filled('min_loan_amount')) {
                $rules->where('min_loan_amount', '>=', $request->min_loan_amount);
            }
            if ($request->filled('max_loan_amount')) {
                $rules->where('max_loan_amount', '<=', $request->max_loan_amount);
            }
            if ($request->filled('min_loan_tenure')) {
                $rules->where('min_loan_tenure', '>=', $request->min_loan_tenure);
            }
            if ($request->filled('max_loan_tenure')) {
                $rules->where('max_loan_tenure', '<=', $request->max_loan_tenure);
            }

            $rules = $rules->paginate($request->number_of_records ?? 10);

            return $this->helper_controller->send_response('success', 'Loan amount rules retrieved', 200, $rules);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An error occurred: " . $e->getMessage(), 422);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_type_id' => 'required|integer|exists:product_types,id',
                'max_loan_amount' => 'required|numeric|min:1',
                'min_loan_amount' => 'required|numeric|min:1|lte:max_loan_amount',
                'min_loan_tenure' => 'required|integer|min:1',
                'max_loan_tenure' => 'required|integer|min:1|gte:min_loan_tenure',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $rule = new LoanAmountRules();
            $rule->product_type_id = $request->product_type_id;
            $rule->max_loan_amount = $request->max_loan_amount;
            $rule->min_loan_amount = $request->min_loan_amount;
            $rule->min_loan_tenure = $request->min_loan_tenure;
            $rule->max_loan_tenure = $request->max_loan_tenure;
            $rule->modified_by = auth()->id();
            $rule->ip = $this->helper_controller->get_user_ip();
            $rule->save();

            return $this->helper_controller->send_response('success', 'Loan amount rule created successfully', 200, $rule);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An error occurred: " . $e->getMessage(), 422);
        }
    }

    public function show($id)
    {
        $rule = LoanAmountRules::with(['modifiedBy', 'productType'])->find($id);
        if (!$rule) {
            return $this->helper_controller->send_response('failed', 'Loan amount rule not found', 422);
        }

        return $this->helper_controller->send_response('success', 'Loan amount rule retrieved', 200, $rule);
    }

    public function update(Request $request, $id)
    {
        try {
            $rule = LoanAmountRules::find($id);
            if (!$rule) {
                return $this->helper_controller->send_response('failed', 'Loan amount rule not found', 422);
            }

            $validator = Validator::make($request->all(), [
                'product_type_id' => 'nullable|integer|exists:product_types,id',
                'max_loan_amount' => 'nullable|numeric|min:1',
                'min_loan_amount' => 'nullable|numeric|min:1|lte:max_loan_amount',
                'min_loan_tenure' => 'nullable|integer|min:1',
                'max_loan_tenure' => 'nullable|integer|min:1|gte:min_loan_tenure',
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            if ($request->filled('product_type_id')) {
                $rule->product_type_id = $request->product_type_id;
            }
            if ($request->filled('max_loan_amount')) {
                $rule->max_loan_amount = $request->max_loan_amount;
            }
            if ($request->filled('min_loan_amount')) {
                $rule->min_loan_amount = $request->min_loan_amount;
            }
            if ($request->filled('min_loan_tenure')) {
                $rule->min_loan_tenure = $request->min_loan_tenure;
            }
            if ($request->filled('max_loan_tenure')) {
                $rule->max_loan_tenure = $request->max_loan_tenure;
            }

            $rule->modified_by = auth()->id();
            $rule->ip = $this->helper_controller->get_user_ip();
            $rule->save();

            return $this->helper_controller->send_response('success', 'Loan amount rule updated successfully', 200, $rule);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An error occurred: " . $e->getMessage(), 422);
        }
    }

    public function destroy($id)
    {
        try {
            $rule = LoanAmountRules::find($id);
            if (!$rule) {
                return $this->helper_controller->send_response('failed', 'Loan amount rule not found', 422);
            }

            $rule->delete();

            return $this->helper_controller->send_response('success', 'Loan amount rule deleted successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', "An error occurred: " . $e->getMessage(), 422);
        }
    }

}
