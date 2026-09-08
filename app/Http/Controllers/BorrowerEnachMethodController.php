<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BorrowerAccountDetails;
use App\Models\BorrowerEnachMethod;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BorrowerEnachMethodController extends Controller
{
    use ApiResponse;

    private const RELATIONS = [
        'createdByUser:id,name,email',
        'updatedByUser:id,name,email',
        'borrowerKyc:borrower_id,name,mobile',
        'loanDetails:id,borrower_id',
    ];
    private const DATE_FORMAT = 'd-m-Y H:i:s';

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'borrower_id'  => ['required', 'integer', 'exists:loan_basic_details,id'],
            'enach_method' => ['required', 'string', 'max:100'],
            'remarks'      => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $data    = $validator->validated();
        $adminId = auth()->id();
        $ip      = $request->ip();

        try {
            $record = DB::transaction(function () use ($data, $adminId, $ip) {
                BorrowerEnachMethod::where('borrower_id', $data['borrower_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->update([
                        'is_active'  => false,
                        'updated_by' => $adminId,
                        'updated_ip' => $ip,
                        'updated_at' => now(),
                    ]);

                $record = BorrowerEnachMethod::create([
                    'borrower_id'  => $data['borrower_id'],
                    'enach_method' => $data['enach_method'],
                    'remarks'      => $data['remarks'] ?? null,
                    'is_active'    => true,
                    'created_by'   => $adminId,
                    'created_ip'   => $ip,
                    'updated_by'   => $adminId,
                    'updated_ip'   => $ip,
                ]);

                BorrowerAccountDetails::where('borrower_id', $data['borrower_id'])
                    ->update(['enach_method' => $data['enach_method']]);

                return $record;
            });
        } catch (\Throwable $e) {
            return $this->send_response('failed', 'Unable to record enach method'. $e->getMessage(), 422);
        }

        return $this->send_response('success', 'Enach method recorded successfully.', 200, $record->load(self::RELATIONS));
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'borrower_id'  => ['nullable', 'integer'],
            'enach_method' => ['nullable', 'string', 'max:100'],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active'    => ['nullable', 'in:0,1,all'],
            'page'         => ['nullable', 'integer', 'min:1'],
            'limit'        => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if ($validator->fails()) {
            return $this->send_response('failed', $validator->errors()->first(), 422);
        }

        $filters = $validator->validated();
        $isActive = $filters['is_active'] ?? '1';
        $page     = (int) ($filters['page'] ?? 1);
        $limit    = (int) ($filters['limit'] ?? 25);

        $records = BorrowerEnachMethod::with(self::RELATIONS)
            ->when($isActive !== 'all', fn ($q) => $q->where('is_active', (bool) $isActive))
            ->when($filters['borrower_id'] ?? null, fn ($q, $v) => $q->where('borrower_id', $v))
            ->when($filters['enach_method'] ?? null, fn ($q, $v) => $q->where('enach_method', $v))
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->send_response('success', 'Enach method records fetched successfully.', 200, [
            'records'      => collect($records->items())->map(fn ($r) => $this->formatRecord($r))->values(),
            'current_page' => $records->currentPage(),
            'limit'        => $records->perPage(),
            'total'        => $records->total(),
            'last_page'    => $records->lastPage(),
        ]);
    }

    public function getByBorrower(Request $request, int $borrowerId): JsonResponse
    {
        $records = BorrowerEnachMethod::with(self::RELATIONS)
            ->where('borrower_id', $borrowerId)
            ->orderByDesc('id')
            ->get();

        if ($records->isEmpty()) {
            return $this->send_response('failed', 'No enach method records found for this borrower.', 404);
        }

        $formatted = $records->map(fn ($r) => $this->formatRecord($r))->values();

        return $this->send_response('success', 'Enach method records fetched successfully.', 200, [
            'borrower_id' => $borrowerId,
            'borrower'    => $records->first()->borrowerKyc?->only(['name', 'mobile']),
            'history'     => $formatted,
        ]);
    }

    private function formatRecord(BorrowerEnachMethod $record): array
    {
        $row = $record->toArray();

        $row['created_at'] = $record->created_at?->format(self::DATE_FORMAT);
        $row['updated_at'] = $record->updated_at?->format(self::DATE_FORMAT);

        return $row;
    }
}