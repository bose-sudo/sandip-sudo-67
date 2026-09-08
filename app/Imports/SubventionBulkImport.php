<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

/**
 * A thin import class — only collects rows as-is.
 * All business validation is done separately in SubventionUploadService
 * so we can report errors by row number.
 */
class SubventionBulkImport implements ToCollection, WithHeadingRow
{
    public Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }
}