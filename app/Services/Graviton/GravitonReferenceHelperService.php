<?php

namespace App\Services\Graviton;

use App\Models\GravitonReferenceData;

class GravitonReferenceHelperService
{
    protected static array $cache = [];

    /**
     * Get family member template JSON for a borrower
     */
    protected function getTemplate(int $borrowerId): array
    {
        if (isset(self::$cache[$borrowerId])) {
            return self::$cache[$borrowerId];
        }

        $record = GravitonReferenceData::where('borrower_id', $borrowerId)->first();

        if (!$record || empty($record->family_member_template_data)) {
            return self::$cache[$borrowerId] = [];
        }

        $data = is_array($record->family_member_template_data)
            ? $record->family_member_template_data
            : json_decode($record->family_member_template_data, true);

        return self::$cache[$borrowerId] = $data ?: [];
    }

    /**
     * Find ID by list key + name/code
     */
    public function findId(int $borrowerId, string $listKey, ?string $name): ?int
    {
        if (empty($name)) return null;

        $data = $this->getTemplate($borrowerId);
        $list = $data[$listKey] ?? [];

        $name = strtolower(trim($name));

        foreach ($list as $item) {
            $itemName = strtolower(trim($item['name'] ?? $item['value'] ?? ''));
            $itemCode = strtolower(trim($item['code'] ?? ''));

            if ($itemName === $name || $itemCode === $name) {
                return (int) $item['id'];
            }
        }

        return null;
    }

    /**
     * First active ID
     */
    public function getFirstId(int $borrowerId, string $listKey): ?int
    {
        $data = $this->getTemplate($borrowerId);
        $list = $data[$listKey] ?? [];

        foreach ($list as $item) {
            if (($item['active'] ?? true) === true) {
                return (int) $item['id'];
            }
        }

        return isset($list[0]['id']) ? (int) $list[0]['id'] : null;
    }

    /**
     * Get ID with fallback
     */
    public function getId(int $borrowerId, string $listKey, ?string $name = null, ?string $fallback = null): ?int
    {
        if ($name) {
            $id = $this->findId($borrowerId, $listKey, $name);
            if ($id !== null) return $id;
        }

        if ($fallback) {
            $id = $this->findId($borrowerId, $listKey, $fallback);
            if ($id !== null) return $id;
        }

        return $this->getFirstId($borrowerId, $listKey);
    }

    /**
     * Clear cache
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}