<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Converts a single repeating-instrument row's raw REDCap data values into
 * the `{linkId, value}[]` answer bag `RedcapInstrumentPatientProvider`'s
 * `openClinicalImportModal` returns to `open-pedigree`'s generalized
 * `onImported` callback (see the `generalize-patient-provider-import`
 * companion change).
 *
 * Pure/testable: takes a plain row-data array — the per-row shape
 * `REDCap::getData($project_id, 'json-array', null, $fields)` produces
 * (field name => value, with checkbox fields exploded into
 * `fieldName___optionCode => '0'|'1'` entries; REDCap's `'array'` format
 * instead nests checkboxes as `[field][optionCode] => '0'|'1'`, which this
 * class does NOT handle — the caller must use `'json-array'`) — plus
 * {@see QuestionnaireDerivation::resolveTaggedFields()}'s output. No REDCap
 * database access here — fetching the row and the Data Dictionary is the
 * caller's job.
 */
class RedcapInstrumentRowImporter
{
    /**
     * @param array $rowData Field-name-keyed raw values for one record
     *   (checkbox options as `fieldName___optionCode` keys).
     * @param array $resolvedFields {@see QuestionnaireDerivation::resolveTaggedFields()}'s output.
     * @return array{linkId: string, value: mixed}[]
     */
    public static function buildAnswers(array $rowData, array $resolvedFields): array
    {
        $answers = [];
        foreach ($resolvedFields as $field) {
            $value = self::extractValue($rowData, $field);
            if ($value === null) {
                continue;
            }
            $answers[] = ['linkId' => $field['linkId'], 'value' => $value];
        }
        return $answers;
    }

    private static function extractValue(array $rowData, array $field): mixed
    {
        $fieldName = $field['redcapField'];

        if ($field['type'] === 'choice' && $field['repeats']) {
            $checkedCodes = [];
            foreach ($field['choices'] as $code => $display) {
                // PHP coerces numeric string array keys to int; REDCap
                // choice codes are conventionally treated as strings.
                $code = (string) $code;
                if (($rowData[$fieldName . '___' . $code] ?? '0') === '1') {
                    $checkedCodes[] = $code;
                }
            }
            if (empty($checkedCodes)) {
                return null;
            }
            // RESERVED_LEGEND_TARGETS linkIds require an array of {id, name}
            // objects; a plain repeating custom item just gets raw codes.
            if ($field['linkId'] !== $fieldName) {
                return array_map(function ($code) use ($field) {
                    return ['id' => $code, 'name' => $field['choices'][$code]];
                }, $checkedCodes);
            }
            return $checkedCodes;
        }

        if (!array_key_exists($fieldName, $rowData) || $rowData[$fieldName] === '') {
            return null;
        }
        $raw = $rowData[$fieldName];

        switch ($field['type']) {
            case 'boolean':
                return $raw === '1' || strtolower((string) $raw) === 'true';
            case 'integer':
                return (int) $raw;
            case 'decimal':
                return (float) $raw;
            default:
                return $raw;
        }
    }
}
