<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Resolves which `@PEDIGREE_FIELD`-tagged fields are bound to a FHIR
 * ontology provider and, where the binding names one, the FHIR ValueSet URI
 * to feed into {@see QuestionnaireDerivation::derive()} as
 * `$fieldAnswerValueSets`.
 *
 * REDCap core links a field to an ontology provider via its
 * `element_enum` metadata column, formatted `"<SERVICE_PREFIX>:<category>"`
 * — see `Classes/OntologyProvider.php`/`Classes/OntologyManager.php`:
 * - `redcap_fhir_ontology_provider` (service prefix `FHIR`): the category is
 *   the ValueSet URL itself, e.g. `"FHIR:http://loinc.org/vs/LL3279-8"`.
 * - `advanced_fhir_ontology_provider` (service prefix `ADVFHIR`): the
 *   category is an ID into its `site-category-list`, whose entry gives the
 *   ValueSet URL when its valueset type is `url`.
 * `simple_ontology_provider` categories are static local lists with no FHIR
 * ValueSet concept, so they never resolve here.
 *
 * Pure/testable: takes the already-fetched `element_enum` values and the
 * `advanced_fhir_ontology_provider` module's `site-category-list`
 * sub-settings as plain input — no REDCap database or cross-module
 * settings access here; that's the caller's job.
 */
class OntologyValueSetResolver
{
    const ADVANCED_FHIR_SERVICE_PREFIX = 'ADVFHIR';
    const FHIR_SERVICE_PREFIX = 'FHIR';

    /**
     * @param array $elementEnumByField field_name => raw `element_enum`
     *   string (typically `$Proj->metadata[$field]['element_enum']`).
     * @param array $advancedProviderCategories `advanced_fhir_ontology_provider`'s
     *   `site-category-list` sub-settings array — each entry an assoc array
     *   with `ontology-id`, `valueset-type` (`'url'`|`'resource'`), `valueset`.
     * @return array field_name => FHIR ValueSet URI, only for fields bound
     *   to an absolute http(s) ValueSet URL via `redcap_fhir_ontology_provider`,
     *   or to a URL-type advanced-provider category.
     */
    public static function resolve(array $elementEnumByField, array $advancedProviderCategories): array
    {
        $categoriesById = [];
        foreach ($advancedProviderCategories as $category) {
            if (isset($category['ontology-id'])) {
                $categoriesById[$category['ontology-id']] = $category;
            }
        }

        $result = [];
        foreach ($elementEnumByField as $fieldName => $elementEnum) {
            if (!$elementEnum || strpos($elementEnum, ':') === false) {
                continue;
            }
            [$service, $categoryId] = explode(':', $elementEnum, 2);
            if ($service === self::FHIR_SERVICE_PREFIX) {
                if (preg_match('#^https?://\S+$#i', $categoryId)) {
                    $result[$fieldName] = $categoryId;
                }
                continue;
            }
            if ($service !== self::ADVANCED_FHIR_SERVICE_PREFIX || !isset($categoriesById[$categoryId])) {
                continue;
            }

            $category = $categoriesById[$categoryId];
            if (($category['valueset-type'] ?? null) === 'url' && !empty($category['valueset'])) {
                $result[$fieldName] = $category['valueset'];
            }
        }

        return $result;
    }
}
