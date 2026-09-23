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
     * @param string|int|null $groupId The calling user's Data Access Group
     *   (their `group_id` rights, or `null` if they aren't DAG-restricted).
     *   `\REDCap::getData()`'s own `$groups` argument defaults to "all
     *   groups" - passing this explicitly is what actually restricts a
     *   DAG-assigned user's search/import results to their own group,
     *   matching what every other export path in REDCap already enforces.
     * @param string[]|null $records Restrict to these record names, or
     *   `null` for every record (the search flow's case - it doesn't know
     *   the record in advance; a known-record lookup should pass this).
     */
    public static function fetchInstrumentRows(int $projectId, array $fieldNames, $groupId = null, ?array $records = null): array
    {
        $recordIdField = \REDCap::getRecordIdField($projectId);
        $fields = array_values(array_unique(array_merge([$recordIdField], $fieldNames)));

        try {
            $rawRows = \REDCap::getData($projectId, 'json-array', $records, $fields, null, $groupId);
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
        $proj = self::resolveProject($projectId);
        if (!$proj) {
            return [];
        }

        $result = [];
        foreach ($fieldNames as $fieldName) {
            $enum = $proj->metadata[$fieldName]['element_enum'] ?? null;
            if ($enum) {
                $result[$fieldName] = $enum;
            }
        }
        return $result;
    }

    /**
     * @return bool|null True/false if determinable, null if the project
     *   couldn't be resolved (validation should not block on null — it
     *   means "couldn't check", not "invalid").
     */
    public static function isRepeatingInstrument(int $projectId, string $instrument): ?bool
    {
        $proj = self::resolveProject($projectId);
        return $proj ? $proj->isRepeatingFormAnyEvent($instrument) : null;
    }

    private static $resolvedProjectCache = null;

    /**
     * Reuses REDCap core's own `global $Proj` opportunistically when it
     * already matches (the common case in a real request), but never writes
     * back to it - this gateway is called mid-request from module code, not
     * REDCap core's own bootstrap, so overwriting core's notion of "the
     * current project" would be a global side effect wider than this
     * method's own concern. A resolved instance is cached locally (by
     * project ID) instead, so a request needing this project's metadata
     * repeatedly still only constructs `\Project` once.
     */
    private static function resolveProject(int $projectId): ?\Project
    {
        global $Proj;
        if ($Proj && $Proj->project_id == $projectId) {
            return $Proj;
        }
        if (self::$resolvedProjectCache && self::$resolvedProjectCache->project_id == $projectId) {
            return self::$resolvedProjectCache;
        }
        try {
            self::$resolvedProjectCache = new \Project($projectId);
            return self::$resolvedProjectCache;
        } catch (\Exception $e) {
            return null;
        }
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
