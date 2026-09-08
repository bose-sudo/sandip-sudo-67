<?php

namespace App\Http\Controllers;

use App\Models\AppSupport;
use App\Models\AppSupportSubjects;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AppSupportController extends Controller
{
    protected $helper_controller;

    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    // Fetch all AppSupport entries with filters
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'app_type' => 'nullable|string|max:20',
            'borrower_id' => 'nullable|regex:/^[a-zA-Z0-9\-]+$/',
            'name' => 'nullable|regex:/^[a-zA-Z\s]+$/',
            'contact_number' => 'nullable|digits:10',
            'subject' => 'nullable|regex:/^[a-zA-Z\s]+$/',
            'page' => 'nullable|integer|min:1',
            'number_of_records' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $query = AppSupport::with(['updatedBy:id,name,email']);

            // Apply filters if provided
            if ($request->filled('app_type')) {
                $query->whereRaw('LOWER(app_type) = ?', [strtolower($request->app_type)]);
            }

            if ($request->filled('borrower_id')) {
                $query->whereRaw('LOWER(borrower_id) = ?', [strtolower($request->borrower_id)]);
            }

            if ($request->filled('name')) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->name) . '%']);
            }

            if ($request->filled('contact_number')) {
                $query->where('contact_number', $request->contact_number);
            }

            if ($request->filled('subject')) {
                $query->whereRaw('LOWER(subject) LIKE ?', ['%' . strtolower($request->subject) . '%']);
            }

            // Pagination
            $page = $request->input('page', 1);
            $number_of_records = $request->input('number_of_records', 10);
            $data = $query->orderBy('id', 'desc')->paginate($number_of_records, ['*'], 'page', $page);

            // Enhance data with dynamic creator details
            // $data->transform(function ($item) {
            //     $item->created_by_details = $item->userCreatedBy ?? $item->executiveCreatedBy;
            //     return $item;
            // });
            $data->transform(function ($item) {
                    return [
                        'id' => $item->id,
                        'app_type' => $item->app_type,
                        'borrower_id' => $item->borrower_id,
                        'name' => $item->name,
                        'contact_number' => $item->contact_number,
                        'subject' => $item->subject,
                        'description' => $item->description,
                        'created_by_details' => $item->created_by_details = $item->userCreatedBy ?? $item->executiveCreatedBy,
                        'created_on' => date('d-m-Y H:i:s', strtotime($item->created_at)),
                        'status' => $item->status,
                        'remarks' => $item->remarks,
                        'updated_by' => $item->updatedBy ?? [],
                        'updated_on' => date('d-m-Y H:i:s', strtotime($item->updated_at)),
                    ];
                });

            return $this->helper_controller->send_response('success', 'Data fetched successfully', 200, $data);

        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to fetch records: ' . $e->getMessage(), 422);
        }
    }

    // Create a new AppSupport entry
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'app_type' => 'required|string|max:20|in:B2B,B2C',
            'borrower_id' => 'required|regex:/^[a-zA-Z0-9\-]+$/',
            'name' => 'required|regex:/^[a-zA-Z\s]+$/',
            'contact_number' => 'required|digits:10',
            'subject' => 'required|regex:/^[a-zA-Z\s]+$/',
            'description' => 'nullable|regex:/^[a-zA-Z0-9\s,\-.]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $appSupport = new AppSupport();
            $appSupport->app_type = $request->app_type;
            $appSupport->borrower_id = $request->borrower_id;
            $appSupport->name = $request->name;
            $appSupport->contact_number = $request->contact_number;
            $appSupport->subject = $request->subject;
            $appSupport->description = $request->description;
            $appSupport->ip = $this->helper_controller->get_user_ip(); // Use helper for IP
            $appSupport->created_by = auth()->id();
            $appSupport->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'AppSupport entry created successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to create record: ' . $e->getMessage(), 422);
        }
    }

    // Update an existing AppSupport entry
    // public function update(Request $request, $id)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'app_type' => 'required|string|max:20',
    //         'borrower_id' => 'required|regex:/^[a-zA-Z0-9\-]+$/',
    //         'name' => 'required|regex:/^[a-zA-Z\s]+$/',
    //         'contact_number' => 'required|digits:10',
    //         'subject' => 'required|regex:/^[a-zA-Z\s]+$/',
    //         'description' => 'nullable|regex:/^[a-zA-Z0-9\s,\-.]+$/',
    //     ]);

    //     if ($validator->fails()) {
    //         return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
    //     }

    //     DB::beginTransaction();

    //     try {
    //         $appSupport = AppSupport::find($id);
    //         if(!$appSupport) {
    //             return $this->helper_controller->send_response('failed', "Entry not found", 422);
    //         }

    //         $appSupport->app_type = $request->app_type;
    //         $appSupport->borrower_id = $request->borrower_id;
    //         $appSupport->name = $request->name;
    //         $appSupport->contact_number = $request->contact_number;
    //         $appSupport->subject = $request->subject;
    //         $appSupport->description = $request->description;
    //         $appSupport->ip = $this->helper_controller->get_user_ip(); // Use helper for IP
    //         $appSupport->created_by = auth()->id();
    //         $appSupport->save();

    //         DB::commit();
    //         return $this->helper_controller->send_response('success', 'AppSupport entry updated successfully', 200);

    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         return $this->helper_controller->send_response('failed', 'Unable to update record: ' . $e->getMessage(), 422);
    //     }
    // }

    // Delete an AppSupport entry
    // public function destroy($id)
    // {
    //     DB::beginTransaction();

    //     try {
    //         $appSupport = AppSupport::find($id);
    //         if(!$appSupport) {
    //             return $this->helper_controller->send_response('failed', "Entry not found", 422);
    //         }
    //         $appSupport->delete();

    //         DB::commit();
    //         return $this->helper_controller->send_response('success', 'AppSupport entry deleted successfully', 200);

    //     } catch (Exception $e) {
    //         DB::rollBack();
    //         return $this->helper_controller->send_response('failed', 'Unable to delete record: ' . $e->getMessage(), 422);
    //     }
    // }

    public function update_status(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'remarks' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $appSupport = AppSupport::find($id);
            if(!$appSupport) {
                return $this->helper_controller->send_response('failed', "Entry not found", 422);
            }
            $appSupport->status = $appSupport->status == 'open' ? 'closed' : 'open';
            $appSupport->remarks = $request->remarks;
            $appSupport->updated_by = auth()->id();
            $appSupport->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'AppSupport status updated successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to update record: ' . $e->getMessage(), 422);
        }
    }

    public function subject_list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'nullable|regex:/^[a-zA-Z0-9\s,\-.]+$/',
            'page' => 'nullable|integer|min:1',
            'number_of_records' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            $query = AppSupportSubjects::query();

            if ($request->filled('subject')) {
                $query->whereRaw('LOWER(subject) LIKE ?', ['%' . strtolower($request->subject) . '%']);
            }

            // Pagination
            $page = $request->input('page', 1);
            $number_of_records = $request->input('number_of_records', 10);
            $data = $query->orderBy('subject', 'asc')->paginate($number_of_records, ['*'], 'page', $page);

            return $this->helper_controller->send_response('success', 'Data fetched successfully', 200, $data);
            
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to fetch records: ' . $e->getMessage(), 500);
        }
    }
    
    public function get_support_subject_screener() {
        $query = AppSupportSubjects::select(['id', 'subject'])
                    ->where('status', 'active')
                    ->orderBy('subject', 'asc')
                    ->get();

        return $this->helper_controller->send_response('success', 'Data fetched successfully', 200, $query);
    }

    public function create_subject(Request $request) {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();
        try {
            $app_support_subject = new AppSupportSubjects();
            $app_support_subject->subject = $request->subject;
            $app_support_subject->ip = $this->helper_controller->get_user_ip();
            $app_support_subject->updated_by = auth()->id();
            $app_support_subject->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Subject created successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to create record: ' . $e->getMessage(), 422);
        }
    }

    public function update_subject(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|regex:/^[a-zA-Z0-9\s,\-.]+$/',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        DB::beginTransaction();

        try {
            $appSupportSubject = AppSupportSubjects::find($id);
            if(!$appSupportSubject) {
                return $this->helper_controller->send_response('failed', "Entry not found", 422);
            }

            $appSupportSubject->subject = $request->subject;
            $appSupportSubject->ip = $this->helper_controller->get_user_ip(); // Use helper for IP
            $appSupportSubject->updated_by = auth()->id();
            $appSupportSubject->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Subject updated successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to update record: ' . $e->getMessage(), 422);
        }
    }

    public function update_subject_status(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $appSupportSubject = AppSupportSubjects::find($id);
            if(!$appSupportSubject) {
                return $this->helper_controller->send_response('failed', "Entry not found", 422);
            }
            $appSupportSubject->status = $appSupportSubject->status == 'active' ? 'inactive' : 'active';
            $appSupportSubject->updated_by = auth()->id();
            $appSupportSubject->save();

            DB::commit();
            return $this->helper_controller->send_response('success', 'Subject status updated successfully', 200);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', 'Unable to update record: ' . $e->getMessage(), 422);
        }
    }
}
