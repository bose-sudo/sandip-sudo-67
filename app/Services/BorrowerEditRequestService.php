<?php

namespace App\Services;

use App\Models\BorrowerEditRequest;
use App\Models\BorrowerEditRequestItem;
use App\Models\LoanBasicDetails;
use App\Models\Occupation;
use App\Support\BorrowerEditFieldRegistry as Registry;
use App\Support\NormalizesEditValues;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

class BorrowerEditRequestService
{
    use NormalizesEditValues;

    public const ROUTE_USED      = 'borrower_edit.create-request';
    public const EDITABLE_STATUS = 'NEW';

    /** Cache of modelClass|borrowerId => model|null. */
    private array $recordCache = [];

    /** Occupation option list, resolved once per request. */
    private ?array $occupationOptions = null;

    // =====================================================================
    // Borrower / section resolution
    // =====================================================================

    public function loadBorrower(int $borrowerId): LoanBasicDetails
    {
        $loan = LoanBasicDetails::find($borrowerId);
        if (!$loan) {
            throw new RuntimeException('Invalid borrower');
        }

        return $loan;
    }

    public function assertEditableStatus(LoanBasicDetails $loan): void
    {
        if ($loan->status !== self::EDITABLE_STATUS) {
            throw new RuntimeException(
                "Borrower information can be edited only when the loan status is NEW. Current status: {$loan->status}"
            );
        }
    }

    /** Latest row of $modelClass for this borrower, memoised. */
    public function record(string $modelClass, int $borrowerId)
    {
        $key = $modelClass . '|' . $borrowerId;

        if (!array_key_exists($key, $this->recordCache)) {
            $this->recordCache[$key] = $modelClass::where('borrower_id', $borrowerId)
                ->orderByDesc('id')
                ->first();
        }

        return $this->recordCache[$key];
    }

    /** Section is editable only when every model it writes to has a row. */
    public function sectionHasRecords(string $section, int $borrowerId): bool
    {
        foreach (Registry::modelsFor($section) as $modelClass) {
            if (!$this->record($modelClass, $borrowerId)) {
                return false;
            }
        }

        return true;
    }

    /** Current values + input metadata + editability flags for the edit screen. */
    public function editableSnapshot(int $borrowerId): array
    {
        $sections = [];
        $flags    = [];

        foreach (Registry::sections() as $key => $section) {
            $editable = $this->sectionHasRecords($key, $borrowerId);
            $flags[$section['flag']] = $editable;

            $fields = [];
            foreach (array_keys($section['fields']) as $fieldKey) {
                $def   = Registry::field($key, $fieldKey);
                $model = $this->record($def['model'], $borrowerId);
                $value = $model ? ($this->stringifyValue($model->{$def['column']}) ?? '') : '';

                $fields[] = [
                    'field_key'  => $fieldKey,
                    'label'      => $def['label'],
                    'value'      => $value,
                    'input_type' => $def['input'],
                    'max_length' => $def['max_length'],
                    'options'    => $this->optionsFor($def['options']),
                ];
            }

            $sections[] = [
                'section'     => $key,
                'label'       => $section['label'],
                'is_editable' => $editable,
                'fields'      => $fields,
            ];
        }

        return ['sections' => $sections, 'flags' => $flags];
    }

    /** Resolved option list for a select field; [] for non-selects. */
    private function optionsFor(?string $optionKey): array
    {
        if ($optionKey === null) {
            return [];
        }

        if ($optionKey === Registry::OPTIONS_OCCUPATION) {
            return $this->occupationOptions ??= Occupation::orderBy('occupation')
                ->get(['id', 'occupation'])
                ->map(fn ($o) => ['value' => (string) $o->id, 'label' => $o->occupation])
                ->all();
        }

        return Registry::staticOptions($optionKey) ?? [];
    }

    // =====================================================================
    // Diff building
    // =====================================================================

    /**
     * Per-column diff. Throws when a submitted section has no record,
     * or when nothing actually changed.
     */
    public function buildItems(int $borrowerId, array $payloadSections): array
    {
        $items = [];

        foreach ($payloadSections as $sectionKey => $fields) {
            $section = Registry::section($sectionKey);
            if (!$section) {
                throw new RuntimeException("Unknown section: {$sectionKey}");
            }
            if (!is_array($fields) || $fields === []) {
                continue;
            }

            if (!$this->sectionHasRecords($sectionKey, $borrowerId)) {
                throw new RuntimeException(
                    "{$section['label']} cannot be edited — there is no record in the system for this borrower."
                );
            }

            foreach ($fields as $fieldKey => $newRaw) {
                $def = Registry::field($sectionKey, $fieldKey);
                if (!$def) {
                    throw new RuntimeException("Unknown field '{$fieldKey}' in section '{$sectionKey}'");
                }

                $model = $this->record($def['model'], $borrowerId);
                $old   = $this->stringifyValue($model->{$def['column']});
                $new   = $this->stringifyValue($newRaw);

                if ($this->isSameValue($old, $new, $def['cast'])) {
                    continue; // no-op, don't record it
                }

                $items[] = [
                    'borrower_id' => $borrowerId,
                    'section'     => $sectionKey,
                    'field_key'   => $fieldKey,
                    'table_name'  => $model->getTable(),
                    'record_id'   => $model->getKey(),
                    'column_name' => $def['column'],
                    'old_value'   => $old,
                    'new_value'   => $new,
                ];
            }
        }

        if ($items === []) {
            throw new RuntimeException('No changes detected — the submitted values match the existing data.');
        }

        return $items;
    }

    public function persist(array $header, array $items): BorrowerEditRequest
    {
        $editRequest = BorrowerEditRequest::create($header);

        $now  = now();
        $rows = array_map(fn ($item) => $item + [
            'ber_id'       => $editRequest->ber_id,
            'apply_status' => 'pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $items);

        BorrowerEditRequestItem::insert($rows);

        return $editRequest;
    }

    public static function clientContext(Request $request): array
    {
        return [
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ];
    }

    // =====================================================================
    // Reviewer / history presentation
    // =====================================================================

    /**
     * Grouped view for the "See Changes" screen:
     * [ { label, fields: [ { label, old_value, new_value } ] } ]
     *
     * @param  iterable  $items  BorrowerEditRequestItem rows (models or stdClass)
     */
    public function changeSheet($items): array
    {
        $occupations = $this->occupationLabels($items);
        $sections    = [];

        foreach ($items as $item) {
            $def = Registry::field($item->section, $item->field_key);

            $sections[$item->section]['label']    = Registry::sectionLabel($item->section);
            $sections[$item->section]['fields'][] = [
                'label'     => $def['label'] ?? $item->column_name,
                'old_value' => $this->readable($item->applied_old_value ?? $item->old_value, $def, $occupations),
                'new_value' => $this->readable($item->new_value, $def, $occupations),
            ];
        }

        return array_values($sections);
    }

    /**
     * Flat view keyed by beri_id, for the history/audit list.
     *
     * @return array<int,array{section_label:string,label:string,old_value:string,new_value:string}>
     */
    public function flatChanges($items): array
    {
        $occupations = $this->occupationLabels($items);
        $out         = [];

        foreach ($items as $item) {
            $def = Registry::field($item->section, $item->field_key);

            $out[$item->beri_id] = [
                'section_label' => Registry::sectionLabel($item->section),
                'label'         => $def['label'] ?? $item->column_name,
                'old_value'     => $this->readable($item->applied_old_value ?? $item->old_value, $def, $occupations),
                'new_value'     => $this->readable($item->new_value, $def, $occupations),
            ];
        }

        return $out;
    }

    /** Bulk-resolve occupation ids so nothing queries inside a loop. */
    private function occupationLabels($items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $def = Registry::field($item->section, $item->field_key);
            if (($def['display'] ?? null) !== Registry::DISPLAY_OCCUPATION) {
                continue;
            }
            foreach ([$item->old_value, $item->applied_old_value ?? null, $item->new_value] as $v) {
                if ($v !== null && $v !== '') {
                    $ids[] = (int) $v;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return Occupation::whereIn('id', array_unique($ids))
            ->pluck('occupation', 'id')
            ->all();
    }

    private function readable(?string $value, ?array $def, array $occupations): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($def['display'] ?? null) {
            Registry::DISPLAY_OCCUPATION => (string) ($occupations[(int) $value] ?? $value),
            Registry::DISPLAY_AMOUNT     => number_format((float) $value, 2, '.', ','),
            Registry::DISPLAY_DATE       => Carbon::parse($value)->format('d-m-Y'),
            default                      => $value,
        };
    }
}