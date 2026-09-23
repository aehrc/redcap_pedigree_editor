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
     * @param string|null $genderFieldName The REDCap field name mapped to
     *   `mapsTo="gender"` (see {@see QuestionnaireDerivation::resolveTaggedFields()}),
     *   or `null` if the instrument has none - included as each match's `gender`
     *   so the client knows what was actually matched against (see
     *   `RedcapInstrumentPatientProvider.js`'s `openPicker`).
     * @param string[]|null $allowedGenders Gender codes ('M'/'F'/'U') to
     *   include, or `null` for no gender filtering. A row is excluded only
     *   when both a gender field is configured AND its (normalized) value
     *   isn't in this list - applied *before* `$limit`, so a caller can rely
     *   on getting up to `$limit` gender-compatible matches rather than
     *   incompatible rows crowding out compatible ones beyond the cutoff.
     * @return array List of `['record', 'instance', 'display', 'ref', 'gender']`
     *   (`gender` omitted when `$genderFieldName` is `null`). A row's raw
     *   gender value is normalized to 'U' unless it's exactly 'M' or 'F'
     *   (including when unset/empty) - matching open-pedigree's own gender
     *   getter and this module's README, so a project using different
     *   REDCap choice codes degrades to "unknown" rather than silently
     *   never matching any `$allowedGenders` filter.
     */
    public static function search(array $rows, array $searchFieldNames, string $query, int $limit = 20, ?string $genderFieldName = null, ?array $allowedGenders = null): array
    {
        $query = trim($query);
        $matches = [];

        foreach ($rows as $row) {
            $display = self::buildDisplay($row['fields'], $searchFieldNames);
            if ($query !== '' && stripos($display, $query) === false) {
                continue;
            }

            $gender = null;
            if ($genderFieldName !== null) {
                $rawGender = $row['fields'][$genderFieldName] ?? null;
                $gender = ($rawGender === 'M' || $rawGender === 'F') ? $rawGender : 'U';
            }
            if ($allowedGenders !== null && $gender !== null && !in_array($gender, $allowedGenders, true)) {
                continue;
            }

            $match = [
                'record' => $row['record'],
                'instance' => $row['instance'],
                'display' => $display !== '' ? $display : ($row['record'] . ' #' . $row['instance']),
                'ref' => RedcapInstrumentReference::encode($row['record'], $row['instance']),
            ];
            if ($genderFieldName !== null) {
                $match['gender'] = $gender;
            }
            $matches[] = $match;

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
            // isset()+!== '' (not !empty()) - a field value of "0" (a valid,
            // real identifier, e.g. a numeric kindred code) is not "absent".
            if (isset($fields[$fieldName]) && $fields[$fieldName] !== '') {
                $parts[] = $fields[$fieldName];
            }
        }
        return implode(' ', $parts);
    }
}
