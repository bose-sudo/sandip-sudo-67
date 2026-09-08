<?php

namespace App\Http\Controllers;

use App\Models\LspExecutiveDealerGrouping;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LspExecutiveDealerGroupingController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helperController)
    {
        $this->helper_controller = $helperController;
    }

    public function getDealersByExecutiveId($executive_id)
    {
        try {
            // Fetch the groupings with dealer information
            $groupings = LspExecutiveDealerGrouping::with(['dealer:id,name'])
                ->where('executive_id', $executive_id)
                ->get(['id', 'dealer_id']);

            if ($groupings->isEmpty()) {
                return $this->helper_controller->send_response('failed', 'No dealers found for the given executive ID', 200);
            }

            // Extract the primary key and dealer name
            $dealers = $groupings->map(function ($grouping) {
                return [
                    'id' => $grouping->id,
                    'dealer_id' => $grouping->dealer->id,
                    'name' => $grouping->dealer->name,
                ];
            });

            return $this->helper_controller->send_response('success', 'Dealers fetched successfully', 200, $dealers);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unexpected error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function b2b_dealer_screener_by_executive()
    {
        try {
            $executive_id = auth()->user()->id;
            // Fetch the groupings with dealer information
            $groupings = LspExecutiveDealerGrouping::with(['dealer:id,dealer_unq,name'])
                ->where('executive_id', $executive_id)
                ->get(['id', 'dealer_id']);

            if ($groupings->isEmpty()) {
                return $this->helper_controller->send_response('failed', 'No dealers found for the given executive ID', 200);
            }

            // Extract the primary key and dealer name
            $dealers = $groupings->map(function ($grouping) {
                return [
                    // 'id' => $grouping->id,
                    'dealer_id' => $grouping->dealer->dealer_unq,
                    'name' => $grouping->dealer->name,
                ];
            });

            return $this->helper_controller->send_response('success', 'Dealers fetched successfully', 200, $dealers);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An unexpected error occurred: ' . $e->getMessage(), 500);
        }
    }

    // Create a new grouping
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'executive_id' => 'required|exists:lsp_executive_details,id',
            'dealer_id' => 'required|array|min:1',
            'dealer_id.*' => 'exists:dealers,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $user_id = auth()->user()->id; // Getting the user_id from the token

            foreach ($request->dealer_id as $dealerId) {
                $is_already_exist = LspExecutiveDealerGrouping::where('executive_id', $request->executive_id)
                                        ->where('dealer_id', $dealerId)
                                        ->first();
                if(!$is_already_exist) {
                    LspExecutiveDealerGrouping::create([
                        'executive_id' => $request->executive_id,
                        'dealer_id' => $dealerId,
                        'user_id' => $user_id,
                    ]);
                }
            }

            return $this->helper_controller->send_response('success', "Executive-Dealer Grouping Created", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Retrieve a grouping
    public function show($id)
    {
        try {
            $grouping = LspExecutiveDealerGrouping::find($id);

            if (!$grouping) {
                return $this->helper_controller->send_response('failed', 'Grouping not found', 422);
            }

            return $this->helper_controller->send_response('success', "Grouping Fetched", 200, $grouping);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Update a grouping
    public function update(Request $request, $id)
    {
        return 'not in use';
        $validator = Validator::make($request->all(), [
            'executive_id' => 'required|exists:admin_users,id',
            'dealer_id' => 'required|array|min:1',
            'dealer_id.*' => 'exists:dealers,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $grouping = LspExecutiveDealerGrouping::find($id);

            if (!$grouping) {
                return $this->helper_controller->send_response('failed', 'Grouping not found', 422);
            }

            $grouping->update([
                'executive_id' => $request->executive_id,
                'user_id' => auth()->user()->id,
            ]);

            $grouping->dealer_id = $request->dealer_id;
            $grouping->save();

            return $this->helper_controller->send_response('success', "Grouping Updated", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }

    // Delete a grouping
    public function destroy($id)
    {
        try {
            $grouping = LspExecutiveDealerGrouping::find($id);

            if (!$grouping) {
                return $this->helper_controller->send_response('failed', 'Grouping not found', 422);
            }

            $grouping->delete();

            return $this->helper_controller->send_response('success', "Grouping Deleted", 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', $e->getMessage(), 422);
        }
    }
}
