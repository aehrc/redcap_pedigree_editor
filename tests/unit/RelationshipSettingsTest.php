<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RelationshipSettings;
use PHPUnit\Framework\TestCase;

class RelationshipSettingsTest extends TestCase
{
    const SETTINGS = [
        'project_pedigree_import_instrument' => 'family_members',
        'project_relationship_source_field' => 'pedigree_diagram',
        'project_relationship_instrument' => 'relationships',
        'project_relationship_person_a_field' => 'person_a',
        'project_relationship_person_b_field' => 'person_b',
        'project_relationship_type_field' => 'relationship_type',
        'project_relationship_to_proband_field' => 'relationship',
    ];

    const PEOPLE_EVENTS = [93 => 'Enrolment'];

    private static function kinChoices(array $leaveOut = []): string
    {
        $choices = [];
        foreach (RelationshipSettings::KIN_CODES as $code => $label) {
            if (!in_array($code, $leaveOut, true)) {
                $choices[] = RelationshipSettings::storedKinCode($code) . ', ' . $label . ' (' . $code . ')';
            }
        }
        return implode(' | ', $choices);
    }

    private static function dictionary(array $overrides = []): array
    {
        $dictionary = [
            'pedigree_diagram' => ['form_name' => 'family_history', 'field_type' => 'notes', 'field_annotation' => '@PEDIGREE=HIDE_TEXT'],
            'person_a' => ['form_name' => 'relationships', 'field_type' => 'text'],
            'person_b' => ['form_name' => 'relationships', 'field_type' => 'text'],
            'relationship_type' => ['form_name' => 'relationships', 'field_type' => 'dropdown', 'select_choices_or_calculations' => self::kinChoices()],
            'relationship' => ['form_name' => 'family_members', 'field_type' => 'dropdown', 'select_choices_or_calculations' => 'proband, Proband | other, Other'],
        ];
        foreach ($overrides as $field => $row) {
            if ($row === null) {
                unset($dictionary[$field]);
            } else {
                $dictionary[$field] = $row + ($dictionary[$field] ?? []);
            }
        }
        return $dictionary;
    }

    private static function validate(array $settings = [], array $dictionary = [], ?array $relationshipEvents = self::PEOPLE_EVENTS): string
    {
        return RelationshipSettings::validate(array_merge(self::SETTINGS, $settings), self::dictionary($dictionary), self::PEOPLE_EVENTS, $relationshipEvents, 'GA4GH');
    }

    public function testAFullyConfiguredProjectIsValid(): void
    {
        $this->assertSame('', self::validate());
        $this->assertSame('', self::validate(['project_relationship_to_proband_field' => '']), 'relationship to proband is optional');
    }

    public function testNoRelationshipSettingsIsValid(): void
    {
        $settings = ['project_pedigree_import_instrument' => 'family_members'];
        $this->assertSame('', RelationshipSettings::validate($settings, [], null, null, 'PED'));
        $this->assertFalse(RelationshipSettings::isUsed($settings + ['project_relationship_instrument' => ' ']));
        $this->assertTrue(RelationshipSettings::isUsed(['project_relationship_to_proband_field' => 'relationship']));
    }

    public function testFieldNamesAreTheFieldSettings(): void
    {
        $this->assertSame(['pedigree_diagram', 'person_a', 'person_b', 'relationship_type', 'relationship'], RelationshipSettings::fieldNames(self::SETTINGS));
        $this->assertSame([], RelationshipSettings::fieldNames([]));
    }

    public function testTheStorageFormatMustBeGa4gh(): void
    {
        $validate = function (?string $format) {
            return RelationshipSettings::validate(self::SETTINGS, self::dictionary(), self::PEOPLE_EVENTS, self::PEOPLE_EVENTS, $format);
        };
        $this->assertSame('', $validate('GA4GH'));
        $this->assertSame('', $validate('fhir_v1'), 'saves as GA4GH');
        $this->assertStringContainsString('(it is "PED")', $validate('PED'));
        $this->assertStringContainsString('(it is not set)', $validate(null));
    }

    public function testEachFieldMustBeTheRightType(): void
    {
        $this->assertStringContainsString('person A field "person_a" must be a Text Box', self::validate([], ['person_a' => ['field_type' => 'dropdown']]));
        $this->assertStringContainsString('person B field "person_b" must be a Text Box', self::validate([], ['person_b' => ['field_type' => 'calc']]));
        foreach (['checkbox', 'yesno', 'calc', 'file', 'notes'] as $type) {
            $this->assertStringContainsString('relationship type field "relationship_type" must be a Drop-down List, Radio Buttons or a Text Box',
                self::validate([], ['relationship_type' => ['field_type' => $type]]), $type);
        }
        $this->assertSame('', self::validate([], ['relationship_type' => ['field_type' => 'radio']]));
    }

    public function testReadNeedsEveryRequiredSetting(): void
    {
        $get = function (array $settings) {
            return function ($key) use ($settings) {
                return $settings[$key] ?? null;
            };
        };
        $this->assertSame([
            'people' => 'family_members', 'source' => 'pedigree_diagram', 'instrument' => 'relationships',
            'a' => 'person_a', 'b' => 'person_b', 'type' => 'relationship_type', 'toProband' => 'relationship',
        ], RelationshipSettings::read($get(self::SETTINGS)));
        $this->assertNull(RelationshipSettings::read($get(['project_relationship_instrument' => ''] + self::SETTINGS)));
        $this->assertNull(RelationshipSettings::read($get(['project_pedigree_import_instrument' => null] + self::SETTINGS)));
        $this->assertNull(RelationshipSettings::read($get([])));
    }

    public function testAHalfConfiguredProjectIsRefused(): void
    {
        $errors = self::validate(['project_relationship_instrument' => '', 'project_relationship_type_field' => null]);
        $this->assertStringContainsString('the relationship instrument is required', $errors);
        $this->assertStringContainsString('the relationship type field is required', $errors);

        $errors = self::validate(['project_pedigree_import_instrument' => '']);
        $this->assertStringContainsString('set the Repeating instrument', $errors);
    }

    public function testTheSourceMustBeAPedigreeField(): void
    {
        $this->assertStringContainsString('doesn\'t exist', self::validate([], ['pedigree_diagram' => null]));
        $this->assertStringContainsString('tagged @PEDIGREE', self::validate([], ['pedigree_diagram' => ['field_annotation' => '@PEDIGREE_FIELD']]));
        $this->assertStringContainsString('tagged @PEDIGREE', self::validate([], ['pedigree_diagram' => ['field_type' => 'text']]));
    }

    public function testARelationshipInstrumentInTheWrongEventIsRefused(): void
    {
        $errors = self::validate([], [], [94 => 'Follow-up']);
        $this->assertStringContainsString('must be a repeating instrument in every event where "family_members" repeats (not in: Enrolment)', $errors);
        $this->assertStringContainsString('must be a repeating instrument', self::validate([], [], []));
        $this->assertSame('', self::validate([], [], null), 'couldn\'t check');
    }

    public function testTheRelationshipInstrumentCantBeThePeopleInstrument(): void
    {
        $this->assertStringContainsString('different instrument', self::validate(['project_relationship_instrument' => 'family_members']));
    }

    public function testTheFieldsMustBeOnTheirInstruments(): void
    {
        $this->assertStringContainsString('person A field "person_a" isn\'t on the relationship instrument', self::validate([], ['person_a' => ['form_name' => 'family_members']]));
        $this->assertStringContainsString('person B field "person_b" isn\'t', self::validate([], ['person_b' => null]));
        $this->assertStringContainsString('three different fields', self::validate(['project_relationship_person_b_field' => 'person_a']));
        $this->assertStringContainsString('relationship to proband field "relationship" isn\'t on the Repeating instrument', self::validate([], ['relationship' => ['form_name' => 'relationships']]));
        $this->assertStringContainsString('relationship to proband field "relationship" must be', self::validate([], ['relationship' => ['field_type' => 'checkbox']]));
        $this->assertSame('', self::validate([], ['relationship' => ['field_type' => 'text']]));
    }

    public function testARelationshipTypeDropdownNeedsEveryKinChoice(): void
    {
        $errors = self::validate([], ['relationship_type' => ['select_choices_or_calculations' => self::kinChoices(['KIN:010', 'KIN:048'])]]);
        $this->assertStringContainsString('missing the choices KIN_048, KIN_010', $errors);
        $this->assertSame('', self::validate([], ['relationship_type' => ['field_type' => 'text']]), 'a text field takes any code');
    }

    public function testTheSourceFieldCantBeOnAPersonOrRelationshipRow(): void
    {
        foreach (['family_members', 'relationships'] as $form) {
            $this->assertStringContainsString('can\'t be on the Repeating instrument or the relationship instrument',
                self::validate([], ['pedigree_diagram' => ['form_name' => $form]]), $form);
        }
    }

    public function testTheKinChoicesAreEveryCodeARowCanHave(): void
    {
        $this->assertEqualsCanonicalizing(\AEHRC\PedigreeEditorExternalModule\PedigreeRelationships::ROW_CODES, array_keys(RelationshipSettings::KIN_CODES));
    }

    public function testChoiceCodes(): void
    {
        $this->assertSame(['proband', 'other'], RelationshipSettings::choiceCodes(self::dictionary()['relationship']));
        $this->assertNull(RelationshipSettings::choiceCodes(['field_type' => 'text']));
        $this->assertSame('KIN_027', RelationshipSettings::storedKinCode('KIN:027'));
        $this->assertSame('KIN_027', RelationshipSettings::storedKinCode('KIN:027', true));
        $this->assertSame('KIN:027', RelationshipSettings::storedKinCode('KIN:027', false), 'a text field stores the real code');
    }
}
