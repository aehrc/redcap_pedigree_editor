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
    const MAPPING_EXTENSION_URL = FhirUrls::QUESTIONNAIRE_FIELD_MAPPING;
    const PREDICATE_EXTENSION_URL = FhirUrls::QUESTIONNAIRE_ENABLE_PREDICATE;
    const ACTION_EXTENSION_URL = FhirUrls::QUESTIONNAIRE_ACTION;
    const REDCAP_SOURCE_EXTENSION_URL = FhirUrls::QUESTIONNAIRE_REDCAP_SOURCE;

    // Generic, non-REDCap-named marker (see record-link-provider's
    // linked-record-questionnaire-rendering capability) attached alongside
    // REDCAP_SOURCE_EXTENSION_URL on every derived item - drives open-
    // pedigree-upgrade's own always-disabled/regrouped-onto-the-reserved-tab
    // rendering, entirely independent of REDCAP_SOURCE_EXTENSION_URL (which
    // only routes RedcapInstrumentPatientProvider's import).
    const LINKED_RECORD_SOURCE_EXTENSION_URL = FhirUrls::QUESTIONNAIRE_LINKED_RECORD_SOURCE;

    const UNSUPPORTED_FIELD_TYPES = ['calc', 'sql', 'file', 'slider', 'descriptive'];

    const KNOWN_PREDICATES = [
        'isFetus', 'hasRelationships', 'isProband', 'isRelatedToProband',
        'hasToBeAdopted', 'isTwin', 'isTwinWithConsistentGender',
        'canLinkRecord', 'canCreateNewRecord', 'canEditLinkedRecord',
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
        'lifeStatus' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.lifeStatus',
        'gestationAge' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.gestationAge',
        'carrierStatus' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.carrierStatus',
        'comments' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.comments',
        'childlessStatus' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.childlessStatus',
        'isAdopted' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.isAdopted',
        'monozygotic' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.monozygotic',
        'evaluated' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.evaluated',
        'lostContact' => FhirUrls::PEDIGREE_INDIVIDUAL . '#PedigreeIndividual.lostContact',
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
     * The built-in default Questionnaire's label for each legend - used for a
     * legend item fed by several fields, where no one field's label fits.
     */
    const LEGEND_LABELS = [
        'disorders' => 'Disorders',
        'candidate_genes' => 'Genes',
        'hpo_positive' => 'Phenotypic features',
    ];

    /**
     * The linkId a linked row's gene results travel under, `{<gene code>: <result>}` - reserved in
     * open-pedigree like the legends (pedigree-gene-results-from-linked-rows).
     */
    const GENE_RESULTS_LINK_ID = 'gene_results';

    /**
     * A `geneResult=` field's choice codes read as results: open-pedigree's own, and the LOINC
     * answer codes for the same results (69548-6 Genetic variant assessment / 53034-5 Allelic state).
     * Labels aren't read; any other code is "not tested". Mirrors open-pedigree's GENE_RESULT_CODES
     * (model/geneResults.ts, as of 2.3.0): a result open-pedigree adds needs adding here too.
     */
    const GENE_RESULT_CODES = [
        'heterozygous' => 'heterozygous', 'LA6706-1' => 'heterozygous',
        'homozygous' => 'homozygous', 'LA6705-3' => 'homozygous',
        'hemizygous' => 'hemizygous', 'LA6707-9' => 'hemizygous',
        'heteroplasmic' => 'heteroplasmic', 'LA6703-8' => 'heteroplasmic',
        'homoplasmic' => 'homoplasmic', 'LA6704-6' => 'homoplasmic',
        'positive' => 'positive', 'LA9633-4' => 'positive',
        'negative' => 'negative', 'LA9634-2' => 'negative',
    ];

    /**
     * The valid `@PEDIGREE_FIELD(geneResult="<gene field>")` pairings (design D2): a `radio` or
     * `dropdown` field naming a field of the same instrument that validly feeds the
     * `candidate_genes` legend (so never in "default + tags" mode), the first result field per
     * gene field. Used by both {@see derive()} and {@see resolveTaggedFields()}, so the form and the
     * import agree. A pairing that isn't valid is left out with a warning, and its field is derived
     * as a plain item.
     *
     * @return array{pairs: array<string, string>, warnings: string[]} result field => gene field
     */
    public static function geneResultPairings(array $dataDictionary, array $fieldAnswerValueSets = [], bool $builtInLegends = false): array
    {
        $pairs = [];
        $warnings = [];
        $paired = [];
        foreach ($dataDictionary as $fieldName => $field) {
            $tag = PedigreeFieldTag::parse($field['field_annotation'] ?? null);
            if (!$tag->present || $tag->geneResult === null) {
                continue;
            }
            $geneField = $tag->geneResult;
            $prefix = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(geneResult="' . $geneField . '")';
            $fallback = ' — pairing omitted, field derived as a plain item.';
            $reason = null;
            if (!in_array($field['field_type'] ?? '', ['radio', 'dropdown'], true)) {
                $reason = ' but isn\'t a radio or drop-down field';
            } elseif ($builtInLegends) {
                $reason = ', but the built-in form keeps its own Genes, results included, in "default + tags" mode';
            } elseif (!isset($dataDictionary[$geneField])) {
                $reason = ', but this instrument has no field "' . $geneField . '"';
            } elseif (!self::feedsGenesLegend($dataDictionary[$geneField], $fieldAnswerValueSets[$geneField] ?? null)) {
                $reason = ', but "' . $geneField . '" isn\'t a field tagged legend="candidate_genes" (a single-value text field bound to a FHIR ontology value set)';
            } elseif (isset($paired[$geneField])) {
                $reason = ', but "' . $paired[$geneField] . '" already holds that gene\'s result';
            }
            if ($reason !== null) {
                $warnings[] = $prefix . $reason . $fallback;
                continue;
            }
            $pairs[$fieldName] = $geneField;
            $paired[$geneField] = $fieldName;
            foreach (self::parseChoices($field['select_choices_or_calculations'] ?? '') as $option) {
                $code = (string) $option['valueCoding']['code'];
                if (!isset(self::GENE_RESULT_CODES[$code])) {
                    $warnings[] = 'Field "' . $fieldName . '" (geneResult) has the choice code "' . $code . '", which isn\'t a result:'
                        . ' use heterozygous, homozygous, hemizygous, heteroplasmic, homoplasmic, positive or negative, or their LOINC'
                        . ' answer codes — a row holding it gives its gene no result.';
                }
            }
        }
        return ['pairs' => $pairs, 'warnings' => $warnings];
    }

    /** Whether a field validly feeds the `candidate_genes` legend (its tag and its type). */
    private static function feedsGenesLegend(array $field, ?string $answerValueSet): bool
    {
        $tag = PedigreeFieldTag::parse($field['field_annotation'] ?? null);
        $typeInfo = self::mapFieldType($field);
        return $tag->present && $tag->legend === 'candidate_genes' && $typeInfo !== null
            && self::legendMappingIsValid($typeInfo, $answerValueSet, 'candidate_genes');
    }

    /**
     * Resolves each `@PEDIGREE_FIELD`-tagged, supported field to its
     * effective Questionnaire `linkId`/`type`, without building the full
     * Questionnaire — used both by {@see derive()} and by the
     * `RedcapInstrumentPatientProvider` import path (which needs the same
     * linkId-resolution rules, including the legend-target `linkId`
     * override, to correctly key the answer bag it returns).
     *
     * @return array Ordered list of `['redcapField', 'linkId', 'type', 'repeats', 'choices', 'mapsTo', 'legend']`
     *   (plus `geneResultFor`, the gene field, on a valid `geneResult=` field, whose linkId is
     *   {@see GENE_RESULTS_LINK_ID}),
     *   where `choices` is the field's parsed `code => display` choice map
     *   (empty for non-choice types), and `mapsTo` is the tag's validly-typed
     *   `mapsTo=` target (e.g. `'gender'`), or `null` if untagged/invalid -
     *   same validity rule as {@see applyMapsTo()} (kept in sync so a field
     *   this reports as mapped to a target is the same field the actual
     *   derived Questionnaire maps it to).
     * @param bool $builtInLegends True in "default + tags" mode, whose built-in
     *   form keeps the legends: `legend=` is then ignored (see {@see derive()}).
     */
    public static function resolveTaggedFields(array $dataDictionary, array $fieldAnswerValueSets = [], bool $builtInLegends = false): array
    {
        $resolved = [];
        $geneResultPairs = self::geneResultPairings($dataDictionary, $fieldAnswerValueSets, $builtInLegends)['pairs'];
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
            $legend = $tag->legend !== null && !$builtInLegends && self::legendMappingIsValid($typeInfo, $answerValueSet, $tag->legend);
            if ($legend) {
                // Every field validly tagged for a legend resolves to it, so the import combines them.
                $linkId = $tag->legend;
            }

            $mapsTo = null;
            if (!$legend // a legend field's value goes to the legend, not a mapsTo target (see derive())
                && $tag->mapsTo !== null
                && isset(self::MAPS_TO_FIELD_EXPECTED_TYPES[$tag->mapsTo])
                && self::MAPS_TO_FIELD_EXPECTED_TYPES[$tag->mapsTo] === $typeInfo['type']
                && !$typeInfo['repeats']
            ) {
                // Every MAPS_TO_FIELD_EXPECTED_TYPES target is a scalar Person
                // property (gender, given, family, ...) - a repeating REDCap
                // field (checkbox) is never a valid source for one, even
                // though its derived type ('choice') otherwise matches. A
                // checkbox mapped to "gender" would silently make
                // findMapsToFieldName() report it, then have its raw value
                // never resolve at all (REDCap explodes checkbox values into
                // fieldName___code sub-keys, not a plain fieldName key),
                // defeating the gender-compatible-picker filter with no
                // warning.
                $mapsTo = $tag->mapsTo;
            }

            $choices = [];
            if (in_array($typeInfo['type'], ['choice', 'open-choice'], true)) {
                foreach (self::parseChoices($field['select_choices_or_calculations'] ?? '') as $option) {
                    $choices[$option['valueCoding']['code']] = $option['valueCoding']['display'];
                }
            }

            $entry = [
                'redcapField' => $fieldName,
                'linkId' => $linkId,
                'type' => $typeInfo['type'],
                'repeats' => $typeInfo['repeats'],
                'choices' => $choices,
                'mapsTo' => $mapsTo,
                'legend' => $legend,
            ];
            if (isset($geneResultPairs[$fieldName])) {
                // (its value is the named gene's result, not an item of its own)
                $entry['linkId'] = self::GENE_RESULTS_LINK_ID;
                $entry['mapsTo'] = null;
                $entry['legend'] = false;
                $entry['geneResultFor'] = $geneResultPairs[$fieldName];
            }
            $resolved[] = $entry;
        }
        return $resolved;
    }

    /**
     * A legend can be fed by a single-value `text` field (no date or number
     * validation, so its derived type is `string`) bound to a FHIR ontology
     * value set - the only kind of field REDCap attaches ontology autocomplete
     * to. Each such field gives the legend one entry; several can feed the
     * same legend.
     */
    private static function legendMappingIsValid(array $typeInfo, ?string $answerValueSet, string $target): bool
    {
        return isset(self::RESERVED_LEGEND_TARGETS[$target])
            && $typeInfo['type'] === 'string'
            && !empty($answerValueSet);
    }

    /**
     * Mode "default + tags": starts from `open-pedigree`'s own built-in
     * default Questionnaire (plus the standard disorders/genes/phenotypes
     * legend, name/gender/dob, etc.) and appends the `@PEDIGREE_FIELD`-tagged
     * groups as one additional new top-level group — the default's own
     * content is left untouched, tags only ever add. The "Linked Record" tab
     * itself is never part of any derived/base Questionnaire content — see
     * {@see derive()}'s docblock.
     *
     * @param array $baseQuestionnaire The default Questionnaire (e.g.
     *   loaded from `open-pedigree/dist/defaultQuestionnaire.json`), used
     *   as-is aside from appending to its top-level `item` array.
     * @return array{questionnaire: array, warnings: string[]}
     */
    public static function deriveWithBaseQuestionnaire(array $baseQuestionnaire, array $dataDictionary, string $instrumentName, array $fieldAnswerValueSets = []): array
    {
        $result = self::derive($dataDictionary, $instrumentName, $fieldAnswerValueSets, true);
        $baseQuestionnaire['item'] = array_merge($baseQuestionnaire['item'], $result['questionnaire']['item']);
        return ['questionnaire' => $baseQuestionnaire, 'warnings' => $result['warnings']];
    }

    /**
     * Mode "advanced": resolves the same `['redcapField', 'linkId', 'type',
     * 'repeats', 'choices']` shape as {@see resolveTaggedFields()}, but by
     * scanning an admin-hand-authored Questionnaire for
     * `questionnaire-redcap-source` extensions instead of `@PEDIGREE_FIELD`
     * tags — advanced mode never scans the Data Dictionary for tags at all,
     * the admin's own Questionnaire item `linkId`s are used verbatim (no
     * legend-target override), but each referenced field's REDCap type
     * still needs to come from the real Data Dictionary to interpret its
     * raw value correctly on import.
     *
     * @param array $questionnaire The admin-supplied Questionnaire (parsed JSON).
     * @param string $instrumentName Only items sourced from this instrument are resolved.
     * @return array Same shape as {@see resolveTaggedFields()}.
     */
    public static function resolveFieldsFromQuestionnaire(array $questionnaire, string $instrumentName, array $dataDictionary): array
    {
        $resolved = [];
        self::walkItemsForRedcapSource($questionnaire['item'] ?? [], $instrumentName, $dataDictionary, $resolved);
        return $resolved;
    }

    private static function walkItemsForRedcapSource(array $items, string $instrumentName, array $dataDictionary, array &$resolved): void
    {
        foreach ($items as $item) {
            if (!empty($item['item'])) {
                self::walkItemsForRedcapSource($item['item'], $instrumentName, $dataDictionary, $resolved);
            }

            // An item can have several sources, e.g. a legend fed by several fields.
            foreach (self::findRedcapSourceExtensions($item, $instrumentName) as $source) {
                $fieldName = $source['field'];
                if (!isset($dataDictionary[$fieldName])) {
                    continue;
                }
                $typeInfo = self::mapFieldType($dataDictionary[$fieldName]);
                if ($typeInfo === null) {
                    continue;
                }

                $choices = [];
                if (in_array($typeInfo['type'], ['choice', 'open-choice'], true)) {
                    foreach (self::parseChoices($dataDictionary[$fieldName]['select_choices_or_calculations'] ?? '') as $option) {
                        $choices[$option['valueCoding']['code']] = $option['valueCoding']['display'];
                    }
                }

                $resolved[] = [
                    'redcapField' => $fieldName,
                    'linkId' => $item['linkId'] ?? $fieldName,
                    'type' => $typeInfo['type'],
                    'repeats' => $typeInfo['repeats'],
                    'choices' => $choices,
                    'legend' => self::isLegendItem($item),
                ];
            }
        }
    }

    /** Whether an item is a legend: one of the reserved targets, or an item with a legend mapping. */
    private static function isLegendItem(array $item): bool
    {
        if (isset(self::RESERVED_LEGEND_TARGETS[$item['linkId'] ?? ''])) {
            return true;
        }
        foreach ($item['extension'] ?? [] as $ext) {
            if (($ext['url'] ?? null) === self::MAPPING_EXTENSION_URL
                && in_array($ext['valueCode'] ?? null, ['mapsToLegendCondition', 'mapsToLegendObservation'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function findRedcapSourceExtensions(array $item, string $instrumentName): array
    {
        $sources = [];
        foreach ($item['extension'] ?? [] as $ext) {
            if (($ext['url'] ?? null) !== self::REDCAP_SOURCE_EXTENSION_URL) {
                continue;
            }
            $instrument = null;
            $field = null;
            foreach ($ext['extension'] ?? [] as $sub) {
                if (($sub['url'] ?? null) === 'instrument') {
                    $instrument = $sub['valueString'] ?? null;
                } elseif (($sub['url'] ?? null) === 'field') {
                    $field = $sub['valueString'] ?? null;
                }
            }
            if ($instrument === $instrumentName && $field !== null) {
                $sources[] = ['instrument' => $instrument, 'field' => $field];
            }
        }
        return $sources;
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
     * @param bool $builtInLegends True when the derived items are added to
     *   the built-in default Questionnaire ("default + tags" mode), which
     *   already has the disorders/genes/phenotypes legends: a `legend=` tag is
     *   then ignored with a warning, so no two items share a `linkId`.
     * @return array{questionnaire: array, warnings: string[]}
     */
    public static function derive(array $dataDictionary, string $instrumentName, array $fieldAnswerValueSets = [], bool $builtInLegends = false): array
    {
        $geneResults = self::geneResultPairings($dataDictionary, $fieldAnswerValueSets, $builtInLegends);
        $warnings = $geneResults['warnings'];
        // legend target => the field whose item is that legend's item
        $legendItemField = [];
        // fields whose item is a legend fed by more than one field
        $sharedLegendItems = [];
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
            if (isset($geneResults['pairs'][$fieldName])) {
                // A gene's result: shown beside the gene (the Genes item's result drop-down), not as
                // an item of its own (design D2). Another field's branching logic can't compare it,
                // like a mapped field's (so it's recorded as one). Its own branching logic is for
                // REDCap's form (e.g. [gene] <> ''), so it isn't warned about.
                if ($tag->mapsTo !== null || $tag->legend !== null || $tag->predicate !== null) {
                    $warnings[] = 'Field "' . $fieldName . '" holds a gene\'s result (geneResult=), shown beside its gene, so its'
                        . ' mapsTo/legend/predicate is ignored.';
                }
                $itemTypesByField[$fieldName] = 'choice';
                $mappedFieldNames[$fieldName] = true;
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
            $isLegend = $tag->legend !== null && !$builtInLegends
                && self::legendMappingIsValid($typeInfo, $fieldAnswerValueSets[$fieldName] ?? null, $tag->legend);

            if ($tag->mapsTo !== null) {
                if ($isLegend) {
                    $warnings[] = 'Field "' . $fieldName . '" has both legend= and mapsTo= — its value goes to the "'
                        . $tag->legend . '" legend, so mapsTo is ignored.';
                } else {
                    $isMapped = self::applyMapsTo($item, $extensions, $fieldName, $tag->mapsTo, $typeInfo['type'], $warnings) || $isMapped;
                }
            }
            if ($tag->legend !== null) {
                if (!isset(self::RESERVED_LEGEND_TARGETS[$tag->legend])) {
                    $warnings[] = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(legend="' . $tag->legend
                        . '"), which isn\'t a legend: use "' . implode('", "', array_keys(self::RESERVED_LEGEND_TARGETS))
                        . '" — mapping omitted, field derived as a plain item.';
                } elseif ($builtInLegends) {
                    $warnings[] = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(legend="' . $tag->legend
                        . '"), but the built-in form already has that legend in "default + tags" mode — mapping omitted, field derived as a plain item.';
                } elseif (!$isLegend) {
                    $warnings[] = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(legend="' . $tag->legend
                        . '") but is not a single-value text field bound to a FHIR ontology value set — mapping omitted, field derived as a plain item.';
                } elseif (isset($legendItemField[$tag->legend])) {
                    // Another field already made this legend's item: this one feeds it too, so it
                    // gets no item of its own, only its source recorded on the legend item, which
                    // takes the legend's own label since it's no longer one field's.
                    $items[$legendItemField[$tag->legend]]['extension'][] = self::redcapSourceExtension($instrumentName, $fieldName);
                    $items[$legendItemField[$tag->legend]]['text'] = self::LEGEND_LABELS[$tag->legend];
                    $sharedLegendItems[$legendItemField[$tag->legend]] = true;
                    if (!empty($field['branching_logic']) || $tag->predicate !== null) {
                        $warnings[] = 'Field "' . $fieldName . '" feeds the "' . $tag->legend . '" legend with another field, so its'
                            . ' branching logic/predicate is ignored: a legend fed by several fields is always shown.';
                    }
                    $itemTypesByField[$fieldName] = 'choice';
                    $mappedFieldNames[$fieldName] = true;
                    continue;
                } else {
                    self::makeLegendItem($item, $extensions, $tag->legend, $fieldAnswerValueSets[$fieldName]);
                    $legendItemField[$tag->legend] = $fieldName;
                    $isMapped = true;
                }
            }
            if ($tag->predicate !== null) {
                if (in_array($tag->predicate, self::KNOWN_PREDICATES, true)) {
                    $item['_pendingPredicate'] = $tag->predicate;
                } else {
                    $warnings[] = 'Field "' . $fieldName . '" has @PEDIGREE_FIELD(predicate="' . $tag->predicate
                        . '") which is not a recognised predicate — condition omitted.';
                }
            }

            $extensions[] = self::redcapSourceExtension($instrumentName, $fieldName);
            // Distinct from REDCAP_SOURCE_EXTENSION_URL above - see the
            // constant's own doc comment. Both must be attached together,
            // never independently (record-link-provider design's risk note).
            // (a marker: its definition fixes the value at true)
            $extensions[] = ['url' => self::LINKED_RECORD_SOURCE_EXTENSION_URL, 'valueBoolean' => true];
            $item['extension'] = $extensions;

            $items[$fieldName] = $item;
            $order[] = $fieldName;
            $sectionOfField[$fieldName] = $currentSection;
            $itemTypesByField[$fieldName] = $typeInfo['type'];
            if ($isMapped) {
                $mappedFieldNames[$fieldName] = true;
            }
        }

        self::applyBranchingLogicAndPredicates($items, $order, $dataDictionary, $itemTypesByField, $mappedFieldNames, $warnings, $sharedLegendItems);

        $topLevelItems = self::groupIntoSections($items, $order, $sectionOfField);

        $questionnaire = [
            'resourceType' => 'Questionnaire',
            'status' => 'active',
            'item' => $topLevelItems,
        ];

        return ['questionnaire' => $questionnaire, 'warnings' => $warnings];
    }

    // Note: this class used to build its own "Linked Record" top-level group
    // here, carrying `linkPatient`/`importClinicalData` action buttons
    // (open-pedigree's old AbstractPatientProvider contract) - removed by
    // pedigree-editor-redcap-extension-extraction. open-pedigree-upgrade's
    // record-link-provider mechanism now synthesizes an equivalent "Linked
    // Record" tab (with linkRecord/createNewRecord/editRecord actions)
    // automatically whenever a recordLinkProvider is configured, regardless
    // of which Questionnaire-derivation mode is in use - no mode needs to
    // build its own any more.

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

    /**
     * Parses a Data Dictionary `select_choices_or_calculations` string
     * (`code, label | code, label`) into `answerOption` entries. Also used by
     * {@see RedcapInstrumentSearch::choiceLabels()}.
     */
    public static function parseChoices(string $raw): array
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

    /**
     * Turns a legend field's item into the legend's item: the reserved
     * target's `linkId`, and the shape open-pedigree requires of a legend - a
     * repeating choice with an `answerValueSet` - since the legend is a list,
     * one entry per field feeding it.
     */
    private static function makeLegendItem(array &$item, array &$extensions, string $target, string $answerValueSet): void
    {
        $item['linkId'] = $target;
        $item['type'] = 'choice';
        $item['repeats'] = true;
        $item['answerValueSet'] = $answerValueSet;
        unset($item['answerOption'], $item['required']);
        $extensions[] = ['url' => self::MAPPING_EXTENSION_URL, 'valueCode' => self::RESERVED_LEGEND_TARGETS[$target]];
    }

    private static function redcapSourceExtension(string $instrumentName, string $fieldName): array
    {
        return [
            'url' => self::REDCAP_SOURCE_EXTENSION_URL,
            'extension' => [
                ['url' => 'instrument', 'valueString' => $instrumentName],
                ['url' => 'field', 'valueString' => $fieldName],
            ],
        ];
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
    private static function applyBranchingLogicAndPredicates(array &$items, array $order, array $dataDictionary, array $itemTypesByField, array $mappedFieldNames, array &$warnings, array $sharedLegendItems = []): void
    {
        foreach ($order as $fieldName) {
            $branchingLogic = $dataDictionary[$fieldName]['branching_logic'] ?? '';
            $item =& $items[$fieldName];
            $enableWhen = [];

            // One field's condition can't decide whether a legend other fields also feed is shown.
            if (isset($sharedLegendItems[$fieldName])) {
                if (!empty($branchingLogic) || isset($item['_pendingPredicate'])) {
                    $warnings[] = 'Field "' . $fieldName . '" feeds the "' . $item['linkId'] . '" legend with another field, so its'
                        . ' branching logic/predicate is ignored: a legend fed by several fields is always shown.';
                }
                unset($item['_pendingPredicate'], $item);
                continue;
            }

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
