<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RelationshipTopologyPlan;
use PHPUnit\Framework\TestCase;

class RelationshipTopologyPlanTest extends TestCase
{
    const FIELDS = ['a' => 'person_a', 'b' => 'person_b', 'type' => 'relationship_type'];

    private static function saved(int $instance, $a, $b, string $type): array
    {
        return ['instance' => $instance, 'fields' => ['person_a' => (string) $a, 'person_b' => (string) $b, 'relationship_type' => $type, 'other' => 'x']];
    }

    public function testAnUnchangedSetIsLeftAlone(): void
    {
        $current = [self::saved(4, 3, 1, 'KIN:028'), self::saved(2, 2, 1, 'KIN:027')];
        $desired = [['a' => 2, 'b' => 1, 'type' => 'KIN:027'], ['a' => 3, 'b' => 1, 'type' => 'KIN:028']];

        $this->assertSame(['delete' => [], 'add' => []], RelationshipTopologyPlan::rows($current, $desired, self::FIELDS));
    }

    public function testOnlyTheChangedRowsAreDeletedOrAdded(): void
    {
        $current = [self::saved(5, 3, 1, 'KIN:028'), self::saved(2, 2, 1, 'KIN:027'), self::saved(7, 2, 3, 'KIN:026')];
        $desired = [['a' => 2, 'b' => 1, 'type' => 'KIN:027'], ['a' => 2, 'b' => 3, 'type' => 'KIN:048'], ['a' => 4, 'b' => 1, 'type' => 'KIN:027']];

        $this->assertSame([
            'delete' => [5, 7],
            'add' => [['a' => 2, 'b' => 3, 'type' => 'KIN:048'], ['a' => 4, 'b' => 1, 'type' => 'KIN:027']],
        ], RelationshipTopologyPlan::rows($current, $desired, self::FIELDS));
    }

    public function testADuplicatedSavedRowIsDeletedAndTheLowestKept(): void
    {
        $current = [self::saved(4, 2, 1, 'KIN:027'), self::saved(1, 2, 1, 'KIN:027'), self::saved(2, 2, 1, 'KIN:027')];
        $desired = [['a' => 2, 'b' => 1, 'type' => 'KIN:027']];

        $this->assertSame(['delete' => [2, 4], 'add' => []], RelationshipTopologyPlan::rows($current, $desired, self::FIELDS));
    }

    public function testNoRelationshipsLeftDeletesEveryRow(): void
    {
        $plan = RelationshipTopologyPlan::rows([self::saved(1, 2, 1, 'KIN:027')], [], self::FIELDS);
        $this->assertSame(['delete' => [1], 'add' => []], $plan);
        $this->assertSame(['delete' => [], 'add' => []], RelationshipTopologyPlan::rows([], [], self::FIELDS));
    }

    private static function person(int $instance, string $value): array
    {
        return ['instance' => $instance, 'fields' => ['relationship' => $value, 'first_name' => 'N' . $instance]];
    }

    public function testOnlyChangedValuesAreWrittenAndUnlinkedRowsAreCleared(): void
    {
        $people = [self::person(1, 'proband'), self::person(2, 'father'), self::person(3, ''), self::person(4, 'sibling'), self::person(5, '')];
        $plan = RelationshipTopologyPlan::probandValues($people, [1 => 'proband', 2 => 'mother', 3 => 'father'], 'relationship', null);

        $this->assertSame([2 => 'mother', 3 => 'father', 4 => ''], $plan['writes']);
        $this->assertSame([], $plan['warnings']);
    }

    public function testRowsThatDontExistAreNeverWritten(): void
    {
        $plan = RelationshipTopologyPlan::probandValues([self::person(1, '')], [1 => 'proband', 7 => 'mother'], 'relationship', null);
        $this->assertSame([1 => 'proband'], $plan['writes']);
    }

    public function testACodeMissingFromTheChoicesFallsBackToOther(): void
    {
        $plan = RelationshipTopologyPlan::probandValues([self::person(1, ''), self::person(2, '')],
            [1 => 'proband', 2 => 'cousin_maternal'], 'relationship', ['proband', 'mother', 'other']);

        $this->assertSame([1 => 'proband', 2 => 'other'], $plan['writes']);
        $this->assertCount(1, $plan['warnings']);
        $this->assertStringContainsString('"cousin_maternal" (row 2)', $plan['warnings'][0]);
    }

    public function testMissingChoicesGiveOneWarningPerSave(): void
    {
        $people = [self::person(1, ''), self::person(2, ''), self::person(3, ''), self::person(4, '')];
        $plan = RelationshipTopologyPlan::probandValues($people,
            [1 => 'proband', 2 => 'grandchild', 3 => 'grandchild', 4 => 'cousin_paternal'], 'relationship', ['proband', 'other']);

        $this->assertSame([1 => 'proband', 2 => 'other', 3 => 'other', 4 => 'other'], $plan['writes']);
        $this->assertSame(['The relationship-to-proband field "relationship" has no choice for "cousin_paternal" (row 4),'
            . ' "grandchild" (rows 2, 3), so those rows get "other".'], $plan['warnings']);
    }

    public function testTheSavedValueIsTheSavedFormsOwnRow(): void
    {
        $base = ['record_id' => '1', 'redcap_repeat_instrument' => '', 'redcap_repeat_instance' => '', 'pedigree' => ''];
        $repeatingForm = function ($instance, $value) {
            return ['record_id' => '1', 'redcap_repeat_instrument' => 'family_history', 'redcap_repeat_instance' => $instance, 'pedigree' => $value];
        };
        $other = ['record_id' => '1', 'redcap_repeat_instrument' => 'family_members', 'redcap_repeat_instance' => 2, 'pedigree' => ''];

        // A repeating form: the base row (record_id's form) comes first, with the field empty.
        $rows = [$base, $other, $repeatingForm(1, 'first'), $repeatingForm(2, 'second')];
        $this->assertSame('second', RelationshipTopologyPlan::savedValue($rows, 'family_history', 2, 'pedigree'));
        $this->assertSame('first', RelationshipTopologyPlan::savedValue($rows, 'family_history', '1', 'pedigree'));

        // A plain form.
        $rows = [['redcap_repeat_instrument' => '', 'redcap_repeat_instance' => '', 'pedigree' => 'plain'], $other];
        $this->assertSame('plain', RelationshipTopologyPlan::savedValue($rows, 'family_history', null, 'pedigree'));

        // A repeating event: every form's row carries the event's instance.
        $rows = [['redcap_repeat_instrument' => '', 'redcap_repeat_instance' => 1, 'pedigree' => 'one'],
            ['redcap_repeat_instrument' => '', 'redcap_repeat_instance' => 3, 'pedigree' => 'three']];
        $this->assertSame('three', RelationshipTopologyPlan::savedValue($rows, 'family_history', 3, 'pedigree'));

        $this->assertNull(RelationshipTopologyPlan::savedValue([$other], 'family_history', 1, 'pedigree'));
        $this->assertNull(RelationshipTopologyPlan::savedValue([], 'family_history', 1, 'pedigree'));
    }

    public function testWithoutAnOtherChoiceTheRowIsLeftAsItIs(): void
    {
        $plan = RelationshipTopologyPlan::probandValues([self::person(1, 'mother'), self::person(2, 'father')],
            [1 => 'aunt_uncle_paternal'], 'relationship', ['proband', 'mother', 'father']);

        $this->assertSame([2 => ''], $plan['writes'], 'row 1 is untouched; row 2 is no longer linked');
        $this->assertStringContainsString('left as they are', $plan['warnings'][0]);
    }
}
