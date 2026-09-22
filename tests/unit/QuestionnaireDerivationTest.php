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

    public function testLegendParameterSetsLegendMappingAndOverridesLinkId(): void
    {
        $dd = ['family_disorders' => $this->field([
            'field_type' => 'checkbox',
            'field_annotation' => '@PEDIGREE_FIELD(legend="disorders")',
        ])];
        $result = QuestionnaireDerivation::derive($dd, 'family_members', [
            'family_disorders' => 'http://purl.bioontology.org/ontology/OMIM',
        ]);
        $flat = $this->flatten($result['questionnaire']);
        $this->assertArrayHasKey('disorders', $flat);
        $this->assertArrayNotHasKey('family_disorders', $flat);
        $this->assertContains(
            ['url' => QuestionnaireDerivation::MAPPING_EXTENSION_URL, 'valueCode' => 'mapsToLegendCondition'],
            $flat['disorders']['extension']
        );
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
            ['redcapField' => 'first_name', 'linkId' => 'first_name', 'type' => 'string', 'repeats' => false, 'choices' => []],
        ], $resolved);
    }

    public function testResolveTaggedFieldsOverridesLinkIdForValidLegendMapping(): void
    {
        $dd = ['family_disorders' => $this->field([
            'field_type' => 'checkbox',
            'field_annotation' => '@PEDIGREE_FIELD(legend="disorders")',
        ])];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd, ['family_disorders' => 'http://www.omim.org/vs']);
        $this->assertSame('disorders', $resolved[0]['linkId']);
        $this->assertSame('family_disorders', $resolved[0]['redcapField']);
    }

    public function testResolveTaggedFieldsKeepsFieldNameWhenLegendMappingIsInvalid(): void
    {
        // no answerValueSet supplied -> not ontology-backed -> legend mapping invalid
        $dd = ['family_disorders' => $this->field([
            'field_type' => 'checkbox',
            'field_annotation' => '@PEDIGREE_FIELD(legend="disorders")',
        ])];
        $resolved = QuestionnaireDerivation::resolveTaggedFields($dd);
        $this->assertSame('family_disorders', $resolved[0]['linkId']);
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
