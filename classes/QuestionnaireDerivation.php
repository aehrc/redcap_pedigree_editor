<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Derives a FHIR Questionnaire from a REDCap repeating instrument's Data
 * Dictionary, scoped to `@PEDIGREE_FIELD`-tagged fields.
 *
 * Pure/testable: takes REDCap's `REDCap::getDataDictionary(..., 'array', ...)`
 * shape as plain input (plus a pre-resolved field-name => FHIR ValueSet URI
 * map for ontology-provider convergence) and returns a plain Questionnaire
 * array — no REDCap framework/database access.
 *
 * Extension URLs and the `MAPS_TO_FIELD_*`/`RESERVED_LEGEND_TARGETS` tables
 * below must byte-match `open-pedigree`'s `questionnaireParser.ts`.
 */
class QuestionnaireDerivation
{
    const MAPPING_EXTENSION_URL = 'https://github.com/aehrc/open-pedigree/questionnaire-field-mapping';
    const PREDICATE_EXTENSION_URL = 'https://github.com/aehrc/open-pedigree/questionnaire-enable-predicate';
    const ACTION_EXTENSION_URL = 'https://github.com/aehrc/open-pedigree/questionnaire-action';
    const REDCAP_SOURCE_EXTENSION_URL = 'https://github.com/aehrc/open-pedigree/questionnaire-redcap-source';

    const UNSUPPORTED_FIELD_TYPES = ['calc', 'sql', 'file', 'slider', 'descriptive'];

    const KNOWN_PREDICATES = [
        'isFetus', 'hasRelationships', 'isProband', 'isRelatedToProband',
        'hasToBeAdopted', 'isTwin', 'isTwinWithConsistentGender',
        'canLinkPatient', 'canImportClinicalData',
    ];

    const MAPS_TO_FIELD_EXPECTED_TYPES = [
        'gender' => 'choice',
        'given' => 'string',
        'family' => 'string',
        'identifier' => 'string',
        'birthDate' => 'date',
        'deceasedDateTime' => 'date',
        'lifeStatus' => 'choice',
        'gestationAge' => 'integer',
        'carrierStatus' => 'choice',
        'comments' => 'text',
        'childlessStatus' => 'choice',
        'isAdopted' => 'boolean',
        'monozygotic' => 'boolean',
        'evaluated' => 'boolean',
        'lostContact' => 'boolean',
    ];

    const MAPS_TO_FIELD_DEFINITIONS = [
        'gender' => 'http://hl7.org/fhir/StructureDefinition/Patient#Patient.gender',
        'given' => 'http://hl7.org/fhir/StructureDefinition/Patient#Patient.name.given',
        'family' => 'http://hl7.org/fhir/StructureDefinition/Patient#Patient.name.family',
        'identifier' => 'http://hl7.org/fhir/StructureDefinition/Patient#Patient.identifier',
        'birthDate' => 'http://hl7.org/fhir/StructureDefinition/Patient#Patient.birthDate',
        'deceasedDateTime' => 'http://hl7.org/fhir/StructureDefinition/Patient#Patient.deceasedDateTime',
        'lifeStatus' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.lifeStatus',
        'gestationAge' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.gestationAge',
        'carrierStatus' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.carrierStatus',
        'comments' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.comments',
        'childlessStatus' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.childlessStatus',
        'isAdopted' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.isAdopted',
        'monozygotic' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.monozygotic',
        'evaluated' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.evaluated',
        'lostContact' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.lostContact',
    ];

    /**
     * `legend=` target name => `questionnaire-field-mapping` kind.
     *
     * Note: unlike `mapsTo`, a successful `legend` mapping overrides
     * `item.linkId` to this reserved target name (not the REDCap field
     * name) — `open-pedigree`'s `RESERVED_LEGEND_TARGETS` is keyed by exact
     * `linkId`, so this is the only way a REDCap-derived disorders/genes/
     * phenotypes field actually reaches the real Disorder/Gene/Phenotype
     * legend rather than becoming an orphaned generic legend.
     */
    const RESERVED_LEGEND_TARGETS = [
        'disorders' => 'mapsToLegendCondition',
        'candidate_genes' => 'mapsToLegendObservation',
        'hpo_positive' => 'mapsToLegendObservation',
    ];

    /**
     * Resolves each `@PEDIGREE_FIELD`-tagged, supported field to its
     * effective Questionnaire `linkId`/`type`, without building the full
     * Questionnaire — used both by {@see derive()} and by the
     * `RedcapInstrumentPatientProvider` import path (which needs the same
     * linkId-resolution rules, including the legend-target `linkId`
     * override, to correctly key the answer bag it returns).
     *
     * @return array Ordered list of `['redcapField', 'linkId', 'type', 'repeats', 'choices']`,
     *   where `choices` is the field's parsed `code => display` choice map
     *   (empty for non-choice types).
     */
    public static function resolveTaggedFields(array $dataDictionary, array $fieldAnswerValueSets = []): array
    {
        $resolved = [];
        foreach ($dataDictionary as $fieldName => $field) {
            $tag = PedigreeFieldTag::parse($field['field_annotation'] ?? null);
            if (!$tag->present) {
                continue;
            }
            $typeInfo = self::mapFieldType($field);
            if ($typeInfo === null) {
                continue;
            }

            $answerValueSet = $fieldAnswerValueSets[$fieldName] ?? null;
            $linkId = $fieldName;
            if ($tag->legend !== null && self::legendMappingIsValid($typeInfo, $answerValueSet, $tag->legend)) {
                $linkId = $tag->legend;
            }

            $choices = [];
            if (in_array($typeInfo['type'], ['choice', 'open-choice'], true)) {
                foreach (self::parseChoices($field['select_choices_or_calculations'] ?? '') as $option) {
                    $choices[$option['valueCoding']['code']] = $option['valueCoding']['display'];
                }
            }

            $resolved[] = [
                'redcapField' => $fieldName,
                'linkId' => $linkId,
                'type' => $typeInfo['type'],
                'repeats' => $typeInfo['repeats'],
                'choices' => $choices,
            ];
        }
        return $resolved;
    }

    private static function legendMappingIsValid(array $typeInfo, ?string $answerValueSet, string $target): bool
    {
        return isset(self::RESERVED_LEGEND_TARGETS[$target])
            && $typeInfo['type'] === 'choice'
            && $typeInfo['repeats']
            && !empty($answerValueSet);
    }

    /**
     * @param array $dataDictionary Field-name-keyed array as returned by
     *   `REDCap::getDataDictionary($project_id, 'array', false, null, $instrument)`.
     * @param string $instrumentName The instrument's unique name.
     * @param array $fieldAnswerValueSets Optional field-name => FHIR
     *   ValueSet URI map, pre-resolved by the caller from
     *   `advanced_fhir_ontology_provider`/`simple_ontology_provider`
     *   configuration (see D5) — omit or leave a field unset to fall back
     *   to static `answerOption` choices.
     * @return array{questionnaire: array, warnings: string[]}
     */
    public static function derive(array $dataDictionary, string $instrumentName, array $fieldAnswerValueSets = []): array
    {
        $warnings = [];
        $items = [];
        $itemTypesByField = [];
        $mappedFieldNames = [];
        $order = [];
        $sectionOfField = [];
        $currentSection = null;

        foreach ($dataDictionary as $fieldName => $field) {
            if (!empty($field['section_header'])) {
                $currentSection = trim(strip_tags($field['section_header']));
            }

            $tag = PedigreeFieldTag::parse($field['field_annotation'] ?? null);
            if (!$tag->present) {
                continue;
            }

            $typeInfo = self::mapFieldType($field);
            if ($typeInfo === null) {
                $warnings[] = 'Field "' . $fieldName . '" has unsupported field_type "' . ($field['field_type'] ?? '') . '" and was skipped.';
                continue;
            }

            $item = self::buildBaseItem($fieldName, $field, $typeInfo, $fieldAnswerValueSets[$fieldName] ?? null);
            $extensions = [];
            $isMapped = false;

            if ($tag->mapsTo !== null) {
                $isMapped = self::applyMapsTo($item, $extensions, $fieldName, $tag->mapsTo, $typeInfo['type'], $warnings) || $isMapped;
            }
            if ($tag->legend !== null) {
                $isMapped = self::applyLegend($item, $extensions, $fieldName, $tag->legend, $typeInfo, $warnings) || $isMapped;
            }
            if ($tag->predicate !== null) {
                if (in_array($tag->predicate, self::KNOWN_PREDICATES, true)) {
                    $item['_pendingPredicate'] = $tag->predicate;
                } else {
                    $warnings[] = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(predicate="' . $tag->predicate
                        . '") which is not a recognised predicate — condition omitted.';
                }
            }

            $extensions[] = [
                'url' => self::REDCAP_SOURCE_EXTENSION_URL,
                'extension' => [
                    ['url' => 'instrument', 'valueString' => $instrumentName],
                    ['url' => 'field', 'valueString' => $fieldName],
                ],
            ];
            $item['extension'] = $extensions;

            $items[$fieldName] = $item;
            $order[] = $fieldName;
            $sectionOfField[$fieldName] = $currentSection;
            $itemTypesByField[$fieldName] = $typeInfo['type'];
            if ($isMapped) {
                $mappedFieldNames[$fieldName] = true;
            }
        }

        self::applyBranchingLogicAndPredicates($items, $order, $dataDictionary, $itemTypesByField, $mappedFieldNames, $warnings);

        $topLevelItems = self::groupIntoSections($items, $order, $sectionOfField);
        array_unshift($topLevelItems, self::linkedRecordGroup());

        $questionnaire = [
            'resourceType' => 'Questionnaire',
            'status' => 'active',
            'item' => $topLevelItems,
        ];

        return ['questionnaire' => $questionnaire, 'warnings' => $warnings];
    }

    /**
     * A "Linked Record" tab carrying the two standard PatientProvider
     * action buttons (`linkPatient`/`importClinicalData`), matching
     * `open-pedigree`'s own `defaultQuestionnaire.ts` pattern exactly —
     * without this, a project wiring a `RedcapInstrumentPatientProvider`
     * (or any other provider) would have no button to invoke it from,
     * since a derived Questionnaire fully replaces the built-in default
     * rather than merging with it. Visibility is entirely self-gating via
     * the `canLinkPatient`/`canImportClinicalData` predicates, so this is
     * always safe to include even when no provider is configured (it just
     * stays hidden, exactly like the default Questionnaire's own buttons).
     */
    private static function linkedRecordGroup(): array
    {
        return [
            'linkId' => '__group_linked_record',
            'type' => 'group',
            'text' => 'Linked Record',
            'item' => [
                [
                    'linkId' => 'link_patient',
                    'type' => 'display',
                    'text' => 'Link to record',
                    'extension' => [
                        ['url' => self::MAPPING_EXTENSION_URL, 'valueCode' => 'invokesAction'],
                        ['url' => self::ACTION_EXTENSION_URL, 'valueCode' => 'linkPatient'],
                    ],
                    'enableWhen' => [
                        ['extension' => [['url' => self::PREDICATE_EXTENSION_URL, 'valueCode' => 'canLinkPatient']]],
                    ],
                ],
                [
                    'linkId' => 'import_from_record',
                    'type' => 'display',
                    'text' => 'Import from linked record',
                    'extension' => [
                        ['url' => self::MAPPING_EXTENSION_URL, 'valueCode' => 'invokesAction'],
                        ['url' => self::ACTION_EXTENSION_URL, 'valueCode' => 'importClinicalData'],
                    ],
                    'enableWhen' => [
                        ['extension' => [['url' => self::PREDICATE_EXTENSION_URL, 'valueCode' => 'canImportClinicalData']]],
                    ],
                ],
            ],
        ];
    }

    private static function buildBaseItem(string $fieldName, array $field, array $typeInfo, ?string $answerValueSet): array
    {
        $item = [
            'linkId' => $fieldName,
            'type' => $typeInfo['type'],
            'text' => $field['field_label'] ?? $fieldName,
        ];
        if ($typeInfo['repeats']) {
            $item['repeats'] = true;
        }
        if (!empty($field['required_field']) && strtolower($field['required_field']) === 'y') {
            $item['required'] = true;
        }

        if (in_array($typeInfo['type'], ['choice', 'open-choice'], true)) {
            if ($answerValueSet) {
                $item['answerValueSet'] = $answerValueSet;
            } else {
                $options = self::parseChoices($field['select_choices_or_calculations'] ?? '');
                if (!empty($options)) {
                    $item['answerOption'] = $options;
                }
            }
        }

        return $item;
    }

    private static function mapFieldType(array $field): ?array
    {
        $fieldType = $field['field_type'] ?? '';
        $validation = $field['text_validation_type_or_show_slider_number'] ?? '';

        switch ($fieldType) {
            case 'text':
                if (strpos($validation, 'date_') === 0) {
                    return ['type' => 'date', 'repeats' => false];
                }
                if ($validation === 'integer') {
                    return ['type' => 'integer', 'repeats' => false];
                }
                if ($validation === 'number') {
                    return ['type' => 'decimal', 'repeats' => false];
                }
                return ['type' => 'string', 'repeats' => false];
            case 'notes':
                return ['type' => 'text', 'repeats' => false];
            case 'yesno':
            case 'truefalse':
                return ['type' => 'boolean', 'repeats' => false];
            case 'radio':
            case 'dropdown':
                return ['type' => 'choice', 'repeats' => false];
            case 'checkbox':
                return ['type' => 'choice', 'repeats' => true];
            default:
                return null;
        }
    }

    private static function parseChoices(string $raw): array
    {
        $options = [];
        if (trim($raw) === '') {
            return $options;
        }
        foreach (explode('|', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = explode(',', $pair, 2);
            $value = trim($parts[0]);
            $label = isset($parts[1]) ? trim($parts[1]) : $value;
            $options[] = ['valueCoding' => ['code' => $value, 'display' => $label !== '' ? $label : $value]];
        }
        return $options;
    }

    private static function applyMapsTo(array &$item, array &$extensions, string $fieldName, string $target, string $itemType, array &$warnings): bool
    {
        if (isset(self::MAPS_TO_FIELD_EXPECTED_TYPES[$target]) && self::MAPS_TO_FIELD_EXPECTED_TYPES[$target] === $itemType) {
            $item['definition'] = self::MAPS_TO_FIELD_DEFINITIONS[$target];
            $extensions[] = ['url' => self::MAPPING_EXTENSION_URL, 'valueCode' => 'mapsToField'];
            return true;
        }
        $warnings[] = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(mapsTo="' . $target . '") but its derived type "'
            . $itemType . '" is incompatible with that target — mapping omitted, field derived as a plain item.';
        return false;
    }

    private static function applyLegend(array &$item, array &$extensions, string $fieldName, string $target, array $typeInfo, array &$warnings): bool
    {
        if (self::legendMappingIsValid($typeInfo, $item['answerValueSet'] ?? null, $target)) {
            $item['linkId'] = $target;
            $extensions[] = ['url' => self::MAPPING_EXTENSION_URL, 'valueCode' => self::RESERVED_LEGEND_TARGETS[$target]];
            return true;
        }
        $warnings[] = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(legend="' . $target
            . '") but is not a repeating, ontology-provider-backed choice field — mapping omitted, field derived as a plain item.';
        return false;
    }

    /**
     * @param array $mappedFieldNames Field names successfully mapped via
     *   `mapsTo`/`legend` (redcap field name => true) — {@see BranchingLogicTranslator}
     *   must reject `branching_logic` referencing these. A mapped field's
     *   current value lives in its own dedicated property (e.g. `isAdopted`,
     *   via `setAdopted`/`getAdopted`), not in open-pedigree's generic
     *   per-linkId `_questionnaireAnswers` map that `enableWhen` reads from
     *   (`view/person.ts`) — an `enableWhen` condition referencing a mapped
     *   field's REDCap field name would silently never resolve, since that
     *   linkId is never populated in that map. This is a limitation of
     *   `open-pedigree`'s enableWhen evaluator (out of scope for this
     *   change to fix), not something this derivation can safely paper
     *   over — so it degrades gracefully instead, exactly like any other
     *   untranslatable branching_logic.
     */
    private static function applyBranchingLogicAndPredicates(array &$items, array $order, array $dataDictionary, array $itemTypesByField, array $mappedFieldNames, array &$warnings): void
    {
        foreach ($order as $fieldName) {
            $branchingLogic = $dataDictionary[$fieldName]['branching_logic'] ?? '';
            $item =& $items[$fieldName];
            $enableWhen = [];

            if (!empty($branchingLogic)) {
                $result = BranchingLogicTranslator::translate($branchingLogic, $itemTypesByField, $mappedFieldNames);
                if ($result['warning']) {
                    $warnings[] = 'Field "' . $fieldName . '": ' . $result['warning'];
                }
                if ($result['enableWhen']) {
                    $enableWhen = array_merge($enableWhen, $result['enableWhen']);
                }
            }

            if (isset($item['_pendingPredicate'])) {
                $enableWhen[] = [
                    'extension' => [['url' => self::PREDICATE_EXTENSION_URL, 'valueCode' => $item['_pendingPredicate']]],
                ];
                unset($item['_pendingPredicate']);
            }

            if (!empty($enableWhen)) {
                $item['enableWhen'] = $enableWhen;
                $item['enableBehavior'] = 'all';
            }
            unset($item);
        }
    }

    private static function groupIntoSections(array $items, array $order, array $sectionOfField): array
    {
        $groups = [];
        foreach ($order as $fieldName) {
            $section = $sectionOfField[$fieldName];
            $groups[$section === null ? '' : $section][] = $fieldName;
        }

        $topLevelItems = [];
        foreach ($groups as $sectionLabel => $fieldNames) {
            $groupItems = array_map(function ($fieldName) use ($items) {
                return $items[$fieldName];
            }, $fieldNames);

            $topLevelItems[] = [
                'linkId' => '__group_' . ($sectionLabel === '' ? 'general' : preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($sectionLabel))),
                'type' => 'group',
                'text' => $sectionLabel === '' ? 'General' : $sectionLabel,
                'item' => $groupItems,
            ];
        }

        return $topLevelItems;
    }
}
