<?php

namespace App\Services;

use App\Models\BorrowerEditRequest;
use App\Models\LoanBasicDetails;
use App\Support\BorrowerEditFieldRegistry as Registry;
use App\Support\NormalizesEditValues;
use RuntimeException;

class BorrowerEditApplyService
{
    use NormalizesEditValues;

    /**
     * Applies every pending item column-wise. Caller owns the transaction.
     * Throws on any failure so the caller can roll the approval back too.
     */
    public function apply(BorrowerEditRequest $editRequest, int $actorId): int
    {
        $loan = LoanBasicDetails::where('id', $editRequest->borrower_id)
            ->lockForUpdate()
            ->first();

        if (!$loan) {
            throw new RuntimeException('Borrower record no longer exists');
        }

        if ($loan->status !== BorrowerEditRequestService::EDITABLE_STATUS) {
            throw new RuntimeException("Changes cannot be applied — loan status has moved to {$loan->status}");
        }

        $items = $editRequest->items()->where('apply_status', 'pending')->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('No pending changes to apply');
        }

        // Group by target row so each record is saved once.
        $grouped = [];
        foreach ($items as $item) {
            $def = Registry::field($item->section, $item->field_key);
            if (!$def) {
                throw new RuntimeException("Field definition missing for {$item->section}.{$item->field_key}");
            }
            $grouped[$def['model'] . '|' . $item->record_id][] = $item;
        }

        $applied = 0;
        $now     = now();

        foreach ($grouped as $groupKey => $groupItems) {
            [$modelClass, $recordId] = explode('|', $groupKey, 2);

            $model = $modelClass::where('id', $recordId)->lockForUpdate()->first();
            if (!$model) {
                throw new RuntimeException("Target record #{$recordId} in {$groupItems[0]->table_name} no longer exists");
            }

            foreach ($groupItems as $item) {
                $def = Registry::field($item->section, $item->field_key);

                // snapshot the value as it stands right now, not as it stood at request time
                $item->applied_old_value = $this->stringifyValue($model->{$item->column_name});
                $model->{$item->column_name} = $this->castValue($item->new_value, $def['cast']);
            }

            if (in_array('updated_by', $model->getFillable(), true)) {
                $model->updated_by = $actorId;
            }

            $model->save();

            foreach ($groupItems as $item) {
                $item->apply_status = 'applied';
                $item->applied_at   = $now;
                $item->applied_by   = $actorId;
                $item->save();
                $applied++;
            }
        }

        return $applied;
    }
}