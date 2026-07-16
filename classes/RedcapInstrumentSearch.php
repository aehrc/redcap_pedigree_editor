<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Searches already-fetched repeating-instrument rows by a project-designated
 * set of "name-like" fields (design D6). Pure/testable — flattening
 * `REDCap::getData($project_id, 'json-array', null, $fields)`'s rows into
 * the simple `['record', 'instance', 'fields']` shape this expects is the
 * caller's job, kept separate since that shape is REDCap-version-specific.
 */
class RedcapInstrumentSearch
{
    /**
     * @param array $rows List of `['record' => string, 'instance' => int|string, 'fields' => (field name => value)]`.
     * @param string[] $searchFieldNames Field names whose values are
     *   concatenated into the searchable/displayed name for each row.
     * @param string $query Case-insensitive substring match; empty string matches every row.
     * @param int $limit Maximum number of matches returned.
     * @return array List of `['record', 'instance', 'display', 'ref']`.
     */
    public static function search(array $rows, array $searchFieldNames, string $query, int $limit = 20): array
    {
        $query = trim($query);
        $matches = [];

        foreach ($rows as $row) {
            $display = self::buildDisplay($row['fields'], $searchFieldNames);
            if ($query !== '' && stripos($display, $query) === false) {
                continue;
            }

            $matches[] = [
                'record' => $row['record'],
                'instance' => $row['instance'],
                'display' => $display !== '' ? $display : ($row['record'] . ' #' . $row['instance']),
                'ref' => RedcapInstrumentReference::encode($row['record'], $row['instance']),
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    private static function buildDisplay(array $fields, array $searchFieldNames): string
    {
        $parts = [];
        foreach ($searchFieldNames as $fieldName) {
            if (!empty($fields[$fieldName])) {
                $parts[] = $fields[$fieldName];
            }
        }
        return implode(' ', $parts);
    }
}
