<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\QuestionnaireDerivation;
use PHPUnit\Framework\TestCase;

class QuestionnaireDerivationTest extends TestCase
{
    private function field(array $overrides = []): array
    {
        return array_merge([
            'field_type' => 'text',
            'field_label' => 'A field',
            'field_annotation' => '',
            'required_field' => '',
            'section_header' => '',
            'branching_logic' => '',
            'select_choices_or_calculations' => '',
            'text_validation_type_or_show_slider_number' => '',
        ], $overrides);
    }

    /**
     * Flattens the derived Questionnaire's REDCap-derived group items into a
     * flat linkId => item map. `derive()` no longer builds any "Linked
     * Record" group of its own (see pedigree-editor-redcap-extension-
     * extraction - open-pedigree-upgrade's record-link-provider mechanism
     * synthesizes an equivalent tab automatically now), so every top-level
     * group is REDCap-derived; `redcapDerivedGroups()` is kept only as a
     * defensive no-op filter in case a hand-authored base Questionnaire
     * happens to reuse that legacy linkId.
     */
    private function flatten(array $questionnaire): array
    {
        $flat = [];
        foreach ($this->redcapDerivedGroups($questionnaire) as $group) {
            foreach ($group['item'] as $item) {
                $flat[$item['linkId']] = $item;
            }
        }
        return $flat;
    }

    private function redcapDerivedGroups(array $questionnaire): array
    {
        return array_values(array_filter($questionnaire['item'], function ($group) {
            return $group['linkId'] !== '__group_linked_record';
        }));
    }

    public function testUntaggedFieldIsExcluded(): void
    {
        $dd = ['plain_field' => $this->field()];
        $result = QuestionnaireDerivation::derive($dd, 'family_members');
        $this->assertSame([], $this->flatten($result['questionnaire']));
    }

    public function testTaggedFieldIsIncludedWithFieldNameAsLinkId(): void
    {
        $dd = ['first_name' => $this->field(['field_annotation' => '@PEDIGREE_FIELD'])];
        $result = QuestionnaireDerivation::derive($dd, 'family_members');
        $flat = $this->flatten($result['questionnaire']);
        $this->assertArrayHasKey('first_name', $flat);
        $this->assertSame('first_name', $flat['first_name']['linkId']);
    }

    public function testCheckboxFieldDerivesAsRepeatingChoice(): void
    {
        $dd = ['symptoms' => $this->field([
            'field_type' => 'checkbox',
            'field_annotation' => '@PEDIGREE_FIELD',
            'select_choices_or_calculations' => '1, Fever | 2, Cough',
        ])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['symptoms'];
        $this->assertSame('choice', $item['type']);
        $this->assertTrue($item['repeats']);
    }

    public function testUnsupportedFieldTypeIsSkippedWithWarning(): void
    {
        $dd = ['score' => $this->field(['field_type' => 'calc', 'field_annotation' => '@PEDIGREE_FIELD'])];
        $result = QuestionnaireDerivation::derive($dd, 'family_members');
        $this->assertSame([], $this->flatten($result['questionnaire']));
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('score', $result['warnings'][0]);
        $this->assertStringContainsString('calc', $result['warnings'][0]);
    }

    public function testStaticChoicesBecomeAnswerOption(): void
    {
        $dd = ['gender_field' => $this->field([
            'field_type' => 'radio',
            'field_annotation' => '@PEDIGREE_FIELD',
            'select_choices_or_calculations' => '1, Male | 2, Female',
        ])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['gender_field'];
        $this->assertSame(
            [
                ['valueCoding' => ['code' => '1', 'display' => 'Male']],
                ['valueCoding' => ['code' => '2', 'display' => 'Female']],
            ],
            $item['answerOption']
        );
        $this->assertArrayNotHasKey('answerValueSet', $item);
    }

    public function testRequiredFieldCarriesOver(): void
    {
        $dd = ['req_field' => $this->field(['field_annotation' => '@PEDIGREE_FIELD', 'required_field' => 'y'])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['req_field'];
        $this->assertTrue($item['required']);
    }

    public function testNonRequiredFieldHasNoRequiredKey(): void
    {
        $dd = ['opt_field' => $this->field(['field_annotation' => '@PEDIGREE_FIELD'])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['opt_field'];
        $this->assertArrayNotHasKey('required', $item);
    }

    public function testOntologyBackedFieldUsesAnswerValueSetInsteadOfAnswerOption(): void
    {
        $dd = ['disorder_field' => $this->field([
            'field_type' => 'radio',
            'field_annotation' => '@PEDIGREE_FIELD',
            'select_choices_or_calculations' => '1, Male | 2, Female',
        ])];
        $result = QuestionnaireDerivation::derive($dd, 'family_members', [
            'disorder_field' => 'http://www.omim.org/vs',
        ]);
        $item = $this->flatten($result['questionnaire'])['disorder_field'];
        $this->assertSame('http://www.omim.org/vs', $item['answerValueSet']);
        $this->assertArrayNotHasKey('answerOption', $item);
    }

    public function testSectionHeaderStartsANewTopLevelGroup(): void
    {
        $dd = [
            'before' => $this->field(['field_annotation' => '@PEDIGREE_FIELD', 'field_label' => 'Before']),
            'header_field' => $this->field(['field_annotation' => '@PEDIGREE_FIELD', 'section_header' => 'Medical History', 'field_label' => 'After']),
        ];
        $groups = $this->redcapDerivedGroups(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire']);
        $this->assertCount(2, $groups);
        $this->assertSame('General', $groups[0]['text']);
        $this->assertSame(['before'], array_column($groups[0]['item'], 'linkId'));
        $this->assertSame('Medical History', $groups[1]['text']);
        $this->assertSame(['header_field'], array_column($groups[1]['item'], 'linkId'));
    }

    public function testNoImplicitGeneralGroupWhenInstrumentStartsWithASection(): void
    {
        $dd = [
            'first' => $this->field(['field_annotation' => '@PEDIGREE_FIELD', 'section_header' => 'Section A']),
        ];
        $groups = $this->redcapDerivedGroups(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire']);
        $this->assertCount(1, $groups);
        $this->assertSame('Section A', $groups[0]['text']);
    }

    public function testMapsToSetsFieldMappingExtensionAndDefinition(): void
    {
        $dd = ['gender_field' => $this->field([
            'field_type' => 'radio',
            'field_annotation' => '@PEDIGREE_FIELD(mapsTo="gender")',
            'select_choices_or_calculations' => '1, Male | 2, Female',
        ])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['gender_field'];
        $this->assertSame('http://hl7.org/fhir/StructureDefinition/Patient#Patient.gender', $item['definition']);
        $this->assertContains(
            ['url' => QuestionnaireDerivation::MAPPING_EXTENSION_URL, 'valueCode' => 'mapsToField'],
            $item['extension']
        );
    }

    public function testMismatchedMapsToIsRejectedWithWarningAndDerivedAsPlainItem(): void
    {
        $dd = ['name_field' => $this->field([
            'field_type' => 'text',
            'field_annotation' => '@PEDIGREE_FIELD(mapsTo="gender")',
        ])];
        $result = QuestionnaireDerivation::derive($dd, 'family_members');
        $item = $this->flatten($result['questionnaire'])['name_field'];
        $this->assertArrayNotHasKey('definition', $item);
        foreach ($item['extension'] as $ext) {
            $this->assertNotSame('mapsToField', $ext['valueCode'] ?? null);
        }
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('name_field', $result['warnings'][0]);
    }

    /**
     * An ontology field as REDCap has it: a `text` field whose binding (here
     * redcap_fhir_ontology_provider's) is in its choices column, resolved by
     * the caller to a value set.
     */
    private function ontologyField(string $annotation, array $overrides = []): array
    {
        return $this->field(array_merge([
            'field_type' => 'text',
            'select_choices_or_calculations' => 'FHIR:' . self::SCT_VS,
            'field_annotation' => $annotation,
        ], $overrides));
    }

    const SCT_VS = 'http://snomed.info/sct?fhir_vs=refset/32570581000036105';

    private function sourceFields(array $item): array
    {
        $fields = [];
        foreach ($item['extension'] as $ext) {
            if ($ext['url'] === QuestionnaireDerivation::REDCAP_SOURCE_EXTENSION_URL) {
                $fields[] = $ext['extension'][1]['valueString'];
            }
        }
        return $fields;
    }

    public function testLegendParameterOnAnOntologyFieldMakesTheLegendItem(): void
    {
        $dd = ['condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")', ['required_field' => 'y'])];
        $result = QuestionnaireDerivation::derive($dd, 'family_members', ['condition' => self::SCT_VS]);
        $flat = $this->flatten($result['questionnaire']);
        $this->assertSame([], $result['warnings']);
        $this->assertArrayNotHasKey('condition', $flat);
        $legend = $flat['disorders'];
        $this->assertSame('A field', $legend['text']); // one field: its own label
        // The shape open-pedigree requires of a legend item.
        $this->assertSame('choice', $legend['type']);
        $this->assertTrue($legend['repeats']);
        $this->assertSame(self::SCT_VS, $legend['answerValueSet']);
        $this->assertArrayNotHasKey('answerOption', $legend);
        $this->assertArrayNotHasKey('required', $legend);
        $this->assertContains(
            ['url' => QuestionnaireDerivation::MAPPING_EXTENSION_URL, 'valueCode' => 'mapsToLegendCondition'],
            $legend['extension']
        );
        $this->assertSame(['condition'], $this->sourceFields($legend));
    }

    public function testLegendParameterOnAFieldThatCantBeALegendIsRejected(): void
    {
        $cases = [
            'a checkbox, even with a value set' => [$this->field(['field_type' => 'checkbox', 'select_choices_or_calculations' => '1, One',
                'field_annotation' => '@PEDIGREE_FIELD(legend="disorders")']), self::SCT_VS],
            'a plain text field' => [$this->field(['field_annotation' => '@PEDIGREE_FIELD(legend="disorders")']), null],
            'a date field with a value set' => [$this->ontologyField('@PEDIGREE_FIELD(legend="disorders")',
                ['text_validation_type_or_show_slider_number' => 'date_ymd']), self::SCT_VS],
        ];
        foreach ($cases as $case => [$field, $valueSet]) {
            $result = QuestionnaireDerivation::derive(['dx' => $field], 'family_members', $valueSet ? ['dx' => $valueSet] : []);
            $flat = $this->flatten($result['questionnaire']);
            $this->assertArrayHasKey('dx', $flat, $case);
            $this->assertArrayNotHasKey('disorders', $flat, $case);
            $this->assertCount(1, $result['warnings'], $case);
            $this->assertStringContainsString('"dx"', $result['warnings'][0], $case);
        }
    }

    public function testSeveralFieldsFeedOneLegend(): void
    {
        $dd = [
            'primary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")', ['field_label' => 'Primary condition']),
            'other' => $this->field(['field_annotation' => '@PEDIGREE_FIELD']),
            'secondary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
            'gene' => $this->ontologyField('@PEDIGREE_FIELD(legend="candidate_genes")'),
        ];
        $result = QuestionnaireDerivation::derive($dd, 'family_members', [
            'primary_condition' => self::SCT_VS, 'secondary_condition' => self::SCT_VS, 'gene' => 'http://www.genenames.org/vs',
        ]);
        $this->assertSame([], $result['warnings']);
        $flat = $this->flatten($result['questionnaire']);
        $this->assertSame(['disorders', 'other', 'candidate_genes'], array_keys($flat));
        // fed by two fields, so the legend's own label, not either field's
        $this->assertSame('Disorders', $flat['disorders']['text']);
        $this->assertSame('A field', $flat['candidate_genes']['text']);
        $this->assertSame(['primary_condition', 'secondary_condition'], $this->sourceFields($flat['disorders']));
        $this->assertSame(['gene'], $this->sourceFields($flat['candidate_genes']));
    }

    public function testBranchingLogicCantReferenceAFieldThatFeedsALegend(): void
    {
        // Its value is in the legend, which enableWhen can't see - like any mapped field.
        $dd = [
            'primary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
            'secondary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
            'notes' => $this->field(['field_annotation' => '@PEDIGREE_FIELD', 'branching_logic' => "[secondary_condition] = '111'"]),
        ];
        $result = QuestionnaireDerivation::derive($dd, 'family_members', ['primary_condition' => self::SCT_VS, 'secondary_condition' => self::SCT_VS]);
        $this->assertArrayNotHasKey('enableWhen', $this->flatten($result['questionnaire'])['notes']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testLegendLabelsMatchTheBuiltInForm(): void
    {
        $labels = [];
        $walk = function (array $items) use (&$walk, &$labels) {
            foreach ($items as $item) {
                if (isset(QuestionnaireDerivation::RESERVED_LEGEND_TARGETS[$item['linkId']])) {
                    $labels[$item['linkId']] = $item['text'];
                }
                $walk($item['item'] ?? []);
            }
        };
        $walk(json_decode(file_get_contents(__DIR__ . '/../../open-pedigree/dist/defaultQuestionnaire.json'), true)['item']);
        ksort($labels);
        $expected = QuestionnaireDerivation::LEGEND_LABELS;
        ksort($expected);
        $this->assertSame($expected, $labels);
    }

    public function testDefaultPlusTagsKeepsTheBuiltInLegend(): void
    {
        $base = ['resourceType' => 'Questionnaire', 'status' => 'active', 'item' => [
            ['linkId' => '__group_clinical', 'type' => 'group', 'text' => 'Clinical', 'item' => [
                ['linkId' => 'disorders', 'type' => 'choice', 'repeats' => true, 'answerValueSet' => 'http://purl.bioontology.org/ontology/OMIM'],
            ]],
        ]];
        $dd = ['condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")')];
        $result = QuestionnaireDerivation::deriveWithBaseQuestionnaire($base, $dd, 'family_members', ['condition' => self::SCT_VS]);

        $linkIds = [];
        array_walk_recursive($result['questionnaire'], function ($value, $key) use (&$linkIds) {
            if ($key === 'linkId') {
                $linkIds[] = $value;
            }
        });
        $this->assertSame(1, count(array_keys($linkIds, 'disorders', true)));
        $this->assertContains('condition', $linkIds);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('built-in', $result['warnings'][0]);
    }

    public function testPredicateParameterLayersAGraphPredicateCondition(): void
    {
        $dd = ['fetus_only_field' => $this->field(['field_annotation' => '@PEDIGREE_FIELD(predicate="isFetus")'])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['fetus_only_field'];
        $this->assertSame('all', $item['enableBehavior']);
        $this->assertSame(
            [['extension' => [['url' => QuestionnaireDerivation::PREDICATE_EXTENSION_URL, 'valueCode' => 'isFetus']]]],
            $item['enableWhen']
        );
    }

    public function testCombinedMapsToAndPredicateParameters(): void
    {
        $dd = ['gest_age' => $this->field([
            'field_type' => 'text',
            'text_validation_type_or_show_slider_number' => 'integer',
            'field_annotation' => '@PEDIGREE_FIELD(mapsTo="gestationAge",predicate="isFetus")',
        ])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['gest_age'];
        $this->assertContains(
            ['url' => QuestionnaireDerivation::MAPPING_EXTENSION_URL, 'valueCode' => 'mapsToField'],
            $item['extension']
        );
        $this->assertNotEmpty($item['enableWhen']);
    }

    public function testEveryDerivedItemCarriesTheRedcapSourceExtension(): void
    {
        $dd = ['a_field' => $this->field(['field_annotation' => '@PEDIGREE_FIELD'])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['a_field'];
        $this->assertContains(
            [
                'url' => QuestionnaireDerivation::REDCAP_SOURCE_EXTENSION_URL,
                'extension' => [
                    ['url' => 'instrument', 'valueString' => 'family_members'],
                    ['url' => 'field', 'valueString' => 'a_field'],
                ],
            ],
            $item['extension']
        );
    }

    public function testEveryDerivedItemAlsoCarriesTheGenericLinkedRecordSourceExtension(): void
    {
        // Distinct from REDCAP_SOURCE_EXTENSION_URL (routes import) - this one drives
        // open-pedigree-upgrade's own always-disabled/regrouped rendering and must be
        // attached alongside it on every derived item, not independently.
        $dd = ['a_field' => $this->field(['field_annotation' => '@PEDIGREE_FIELD'])];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['a_field'];
        $this->assertContains(
            ['url' => QuestionnaireDerivation::LINKED_RECORD_SOURCE_EXTENSION_URL],
            $item['extension']
        );
    }

    public function testSimpleAndChainBranchingLogicTranslatesToEnableWhen(): void
    {
        $dd = [
            'gender' => $this->field([
                'field_type' => 'radio',
                'field_annotation' => '@PEDIGREE_FIELD',
                'select_choices_or_calculations' => '1, Male | 2, Female',
            ]),
            'age' => $this->field([
                'field_type' => 'text',
                'text_validation_type_or_show_slider_number' => 'integer',
                'field_annotation' => '@PEDIGREE_FIELD',
            ]),
            'dependent_field' => $this->field([
                'field_annotation' => '@PEDIGREE_FIELD',
                'branching_logic' => "[gender] = '2' and [age] > '18'",
            ]),
        ];
        $item = $this->flatten(QuestionnaireDerivation::derive($dd, 'family_members')['questionnaire'])['dependent_field'];
        $this->assertSame('all', $item['enableBehavior']);
        $this->assertSame(
            [
                ['question' => 'gender', 'operator' => '=', 'answerCoding' => ['code' => '2']],
                ['question' => 'age', 'operator' => '>', 'answerInteger' => 18],
            ],
            $item['enableWhen']
        );
    }

    public function testUntranslatableBranchingLogicFallsBackToAlwaysVisibleWithWarning(): void
    {
        $dd = [
            'gender' => $this->field(['field_type' => 'radio', 'field_annotation' => '@PEDIGREE_FIELD']),
            'dependent_field' => $this->field([
                'field_annotation' => '@PEDIGREE_FIELD',
                'branching_logic' => "[gender] = '1' or [gender] = '2'",
            ]),
        ];
        $result = QuestionnaireDerivation::derive($dd, 'family_members');
        $item = $this->flatten($result['questionnaire'])['dependent_field'];
        $this->assertArrayNotHasKey('enableWhen', $item);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testBranchingLogicReferencingAMappedFieldIsUntranslatable(): void
    {
        // Regression test: found via a live end-to-end smoke test, not a unit test.
        // A field mapped via mapsTo/legend stores its value in its own dedicated
        // property (e.g. isAdopted via setAdopted/getAdopted), not open-pedigree's
        // generic per-linkId _questionnaireAnswers map that enableWhen reads from
        // (view/person.ts) - so an enableWhen condition referencing a mapped
        // field's REDCap field name would silently never resolve (always false),
        // regardless of the field's real value. Must degrade gracefully instead.
        $dd = [
            'is_adopted' => $this->field([
                'field_type' => 'yesno',
                'field_annotation' => '@PEDIGREE_FIELD(mapsTo="isAdopted")',
            ]),
            'adoption_reason' => $this->field([
                'field_annotation' => '@PEDIGREE_FIELD',
                'branching_logic' => "[is_adopted] = '1'",
            ]),
        ];
        $result = QuestionnaireDerivation::derive($dd, 'family_members');
        $item = $this->flatten($result['questionnaire'])['adoption_reason'];
        $this->assertArrayNotHasKey('enableWhen', $item);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('is_adopted', implode(' ', $result['warnings']));
    }

    public function testResolveTaggedFieldsUsesFieldNameAsLinkIdByDefault(): void
    {
        $dd = ['first_name' => $this->field(['field_annotation' => '@PEDIGREE_FIELD'])];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd);
        $this->assertSame([
            ['redcapField' => 'first_name', 'linkId' => 'first_name', 'type' => 'string', 'repeats' => false, 'choices' => [], 'mapsTo' => null],
        ], $resolved);
    }

    public function testResolveTaggedFieldsReportsMapsToTargetWhenTypeMatches(): void
    {
        $dd = ['gender' => $this->field([
            'field_type' => 'radio',
            'field_annotation' => '@PEDIGREE_FIELD(mapsTo="gender")',
            'select_choices_or_calculations' => 'M, Male | F, Female',
        ])];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd);
        $this->assertSame('gender', $resolved[0]['mapsTo']);
    }

    public function testResolveTaggedFieldsOmitsMapsToTargetWhenTypeMismatched(): void
    {
        // mapsTo="gender" expects a choice field (see MAPS_TO_FIELD_EXPECTED_TYPES) - a text
        // field is the wrong type, so applyMapsTo() would omit the mapping in the actual
        // derived Questionnaire too; resolveTaggedFields() must agree, not report it anyway.
        $dd = ['gender' => $this->field([
            'field_type' => 'text',
            'field_annotation' => '@PEDIGREE_FIELD(mapsTo="gender")',
        ])];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd);
        $this->assertNull($resolved[0]['mapsTo']);
    }

    public function testResolveTaggedFieldsOmitsMapsToTargetForARepeatingField(): void
    {
        // A checkbox field also derives to type 'choice' (matching
        // MAPS_TO_FIELD_EXPECTED_TYPES['gender']), but it's repeating - every
        // mapsTo target is a scalar Person property, so a repeating source
        // field is never valid even when its base type matches. Concretely:
        // REDCap explodes checkbox values into fieldName___code sub-keys, so
        // a consumer resolving this field by its plain name would never find
        // a value at all.
        $dd = ['gender' => $this->field([
            'field_type' => 'checkbox',
            'field_annotation' => '@PEDIGREE_FIELD(mapsTo="gender")',
            'select_choices_or_calculations' => 'M, Male | F, Female',
        ])];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd);
        $this->assertNull($resolved[0]['mapsTo']);
    }

    public function testResolveTaggedFieldsOverridesLinkIdForValidLegendMapping(): void
    {
        $dd = [
            'primary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
            'secondary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
        ];
        $valueSets = ['primary_condition' => self::SCT_VS, 'secondary_condition' => self::SCT_VS];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd, $valueSets);
        // Both feed the legend, so the import combines them.
        $this->assertSame(['disorders', 'disorders'], array_column($resolved, 'linkId'));
        $this->assertSame(['primary_condition', 'secondary_condition'], array_column($resolved, 'redcapField'));
        $this->assertSame([false, false], array_column($resolved, 'repeats'));

        // "default + tags": the built-in legend keeps the linkId.
        $builtIn = QuestionnaireDerivation::resolveTaggedFields($dd, $valueSets, true);
        $this->assertSame(['primary_condition', 'secondary_condition'], array_column($builtIn, 'linkId'));
    }

    public function testResolveTaggedFieldsKeepsFieldNameWhenLegendMappingIsInvalid(): void
    {
        $dd = [
            // no value set resolved -> not ontology-backed
            'text_dx' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
            // a checkbox can't feed a legend, value set or not
            'checkbox_dx' => $this->field(['field_type' => 'checkbox', 'field_annotation' => '@PEDIGREE_FIELD(legend="disorders")']),
        ];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd, ['checkbox_dx' => self::SCT_VS]);
        $this->assertSame(['text_dx', 'checkbox_dx'], array_column($resolved, 'linkId'));
    }

    public function testResolveTaggedFieldsExcludesUntaggedAndUnsupportedFields(): void
    {
        $dd = [
            'untagged' => $this->field(),
            'unsupported' => $this->field(['field_type' => 'calc', 'field_annotation' => '@PEDIGREE_FIELD']),
            'tagged' => $this->field(['field_annotation' => '@PEDIGREE_FIELD']),
        ];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd);
        $this->assertSame(['tagged'], array_column($resolved, 'redcapField'));
    }

    public function testResolveTaggedFieldsReturnsChoicesMapForChoiceTypes(): void
    {
        $dd = ['gender_field' => $this->field([
            'field_type' => 'radio',
            'field_annotation' => '@PEDIGREE_FIELD',
            'select_choices_or_calculations' => '1, Male | 2, Female',
        ])];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd);
        $this->assertSame(['1' => 'Male', '2' => 'Female'], $resolved[0]['choices']);
    }

    public function testDeriveWithBaseQuestionnaireAppendsTaggedGroupToBase(): void
    {
        $base = [
            'resourceType' => 'Questionnaire',
            'status' => 'active',
            'item' => [
                ['linkId' => '__group_personal', 'type' => 'group', 'text' => 'Personal', 'item' => [
                    ['linkId' => 'link_patient', 'type' => 'display', 'text' => 'Link to record'],
                ]],
            ],
        ];
        $dd = ['a_field' => $this->field(['field_annotation' => '@PEDIGREE_FIELD', 'field_label' => 'A Field'])];

        $result = QuestionnaireDerivation::deriveWithBaseQuestionnaire($base, $dd, 'family_members');
        $groups = $result['questionnaire']['item'];

        $this->assertCount(2, $groups);
        $this->assertSame('Personal', $groups[0]['text']);
        $this->assertSame(['link_patient'], array_column($groups[0]['item'], 'linkId'));
        $this->assertSame(['a_field'], array_column($groups[1]['item'], 'linkId'));
        // The base's own Linked Record content is untouched; derive() must not add its own.
        $this->assertNotContains('__group_linked_record', array_column($groups, 'linkId'));
    }

    public function testADerivedLegendFedBySeveralFieldsImportsThemAllInAdvancedMode(): void
    {
        // The derived Questionnaire, used as an advanced one: each source extension is a field.
        $dd = [
            'primary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
            'secondary_condition' => $this->ontologyField('@PEDIGREE_FIELD(legend="disorders")'),
        ];
        $derived = QuestionnaireDerivation::derive($dd, 'family_members',
            ['primary_condition' => self::SCT_VS, 'secondary_condition' => self::SCT_VS])['questionnaire'];
        $resolved = QuestionnaireDerivation::resolveFieldsFromQuestionnaire($derived, 'family_members', $dd);
        $this->assertSame(['primary_condition', 'secondary_condition'], array_column($resolved, 'redcapField'));
        $this->assertSame(['disorders', 'disorders'], array_column($resolved, 'linkId'));
    }

    public function testResolveFieldsFromQuestionnaireFindsRedcapSourceExtensions(): void
    {
        $dd = ['first_name' => $this->field(['field_type' => 'text', 'field_label' => 'First Name'])];
        $questionnaire = [
            'item' => [
                [
                    'linkId' => '__group_a', 'type' => 'group', 'text' => 'A', 'item' => [
                        [
                            'linkId' => 'given_name',
                            'type' => 'string',
                            'extension' => [
                                [
                                    'url' => QuestionnaireDerivation::REDCAP_SOURCE_EXTENSION_URL,
                                    'extension' => [
                                        ['url' => 'instrument', 'valueString' => 'family_members'],
                                        ['url' => 'field', 'valueString' => 'first_name'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = QuestionnaireDerivation::resolveFieldsFromQuestionnaire($questionnaire, 'family_members', $dd);

        $this->assertCount(1, $resolved);
        $this->assertSame('first_name', $resolved[0]['redcapField']);
        $this->assertSame('given_name', $resolved[0]['linkId']);
        $this->assertSame('string', $resolved[0]['type']);
    }

    public function testResolveFieldsFromQuestionnaireIgnoresOtherInstruments(): void
    {
        $dd = ['first_name' => $this->field(['field_type' => 'text'])];
        $questionnaire = [
            'item' => [
                [
                    'linkId' => 'given_name',
                    'type' => 'string',
                    'extension' => [
                        [
                            'url' => QuestionnaireDerivation::REDCAP_SOURCE_EXTENSION_URL,
                            'extension' => [
                                ['url' => 'instrument', 'valueString' => 'other_instrument'],
                                ['url' => 'field', 'valueString' => 'first_name'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = QuestionnaireDerivation::resolveFieldsFromQuestionnaire($questionnaire, 'family_members', $dd);
        $this->assertSame([], $resolved);
    }

    public function testResolveFieldsFromQuestionnaireDoesNotOverrideLinkId(): void
    {
        // Advanced mode: the admin's own linkId (e.g. the reserved "disorders"
        // legend target) is used verbatim, no legend-override logic applies.
        $dd = ['omim_code' => $this->field(['field_type' => 'text'])];
        $questionnaire = [
            'item' => [
                [
                    'linkId' => 'disorders',
                    'type' => 'choice',
                    'repeats' => true,
                    'extension' => [
                        [
                            'url' => QuestionnaireDerivation::REDCAP_SOURCE_EXTENSION_URL,
                            'extension' => [
                                ['url' => 'instrument', 'valueString' => 'family_members'],
                                ['url' => 'field', 'valueString' => 'omim_code'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $resolved = QuestionnaireDerivation::resolveFieldsFromQuestionnaire($questionnaire, 'family_members', $dd);
        $this->assertSame('disorders', $resolved[0]['linkId']);
    }
}
