<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Thin, deliberately-untested glue between REDCap's live database/framework
 * and the pure classes in this namespace ({@see QuestionnaireDerivation},
 * {@see RedcapInstrumentSearch}, {@see RedcapInstrumentRowImporter},
 * {@see OntologyValueSetResolver}). Every method here touches
 * `\REDCap`/`\ExternalModules` APIs and cannot be exercised by a plain
 * PHPUnit run — keep it as small and simple as possible, push any real
 * logic into the pure classes instead.
 */
class RedcapInstrumentGateway
{
    /**
     * @return array Field-name-keyed Data Dictionary rows for the instrument,
     *   or `[]` if the instrument doesn't exist / has no fields.
     */
    public static function fetchDataDictionary(int $projectId, string $instrument): array
    {
        try {
            return \REDCap::getDataDictionary($projectId, 'array', false, null, $instrument) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Flattens `REDCap::getData(..., 'json-array', ...)`'s rows for the
     * given repeating instrument into `RedcapInstrumentSearch`'s expected
     * `['record', 'instance', 'fields']` shape.
     *
     * @param string[] $fieldNames The instrument's field names (e.g.
     *   `array_keys(self::fetchDataDictionary(...))`).
     */
    public static function fetchInstrumentRows(int $projectId, array $fieldNames): array
    {
        $recordIdField = \REDCap::getRecordIdField($projectId);
        $fields = array_values(array_unique(array_merge([$recordIdField], $fieldNames)));

        try {
            $rawRows = \REDCap::getData($projectId, 'json-array', null, $fields);
        } catch (\Exception $e) {
            return [];
        }

        $rows = [];
        foreach ($rawRows as $rawRow) {
            if (!isset($rawRow['redcap_repeat_instance']) || $rawRow['redcap_repeat_instance'] === '') {
                continue; // non-repeating row for this record (e.g. the arm's first/only event) - not an instrument row
            }
            $rows[] = [
                'record' => (string) $rawRow[$recordIdField],
                'instance' => (int) $rawRow['redcap_repeat_instance'],
                'fields' => array_diff_key($rawRow, array_flip([$recordIdField, 'redcap_repeat_instrument', 'redcap_repeat_instance'])),
            ];
        }
        return $rows;
    }

    /**
     * @return array field_name => raw `element_enum` string, for fields
     *   configured against an ontology-autocomplete provider (REDCap core's
     *   `"<SERVICE_PREFIX>:<category>"` convention — see
     *   {@see OntologyValueSetResolver}).
     */
    public static function fetchElementEnums(int $projectId, array $fieldNames): array
    {
        global $Proj;
        if (!$Proj || $Proj->project_id != $projectId) {
            try {
                $Proj = new \Project($projectId);
            } catch (\Exception $e) {
                return [];
            }
        }

        $result = [];
        foreach ($fieldNames as $fieldName) {
            $enum = $Proj->metadata[$fieldName]['element_enum'] ?? null;
            if ($enum) {
                $result[$fieldName] = $enum;
            }
        }
        return $result;
    }

    /**
     * Cross-module read of `advanced_fhir_ontology_provider`'s
     * `site-category-list` system setting. Returns `[]` (rather than
     * throwing) if that module isn't installed/enabled — ontology
     * convergence (D5) is an optional enhancement, not a hard dependency.
     */
    public static function fetchAdvancedOntologyCategories(): array
    {
        try {
            $module = \ExternalModules\ExternalModules::getModuleInstance('advanced_fhir_ontology_provider');
            return $module ? ($module->getSubSettings('site-category-list') ?: []) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @return array field_name => FHIR ValueSet URI, for `@PEDIGREE_FIELD`-
     *   tagged fields configured against `advanced_fhir_ontology_provider`.
     */
    public static function resolveAnswerValueSets(int $projectId, array $fieldNames): array
    {
        $elementEnums = self::fetchElementEnums($projectId, $fieldNames);
        if (empty($elementEnums)) {
            return [];
        }
        return OntologyValueSetResolver::resolve($elementEnums, self::fetchAdvancedOntologyCategories());
    }
}
