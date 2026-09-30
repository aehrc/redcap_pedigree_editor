<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\PedigreeBundleReader;
use AEHRC\PedigreeEditorExternalModule\PedigreeRelationships;
use PHPUnit\Framework\TestCase;

class PedigreeRelationshipsTest extends TestCase
{
    private static function example(string $name): array
    {
        return PedigreeBundleReader::read(file_get_contents(__DIR__ . '/../fixtures/' . $name));
    }

    /**
     * A pedigree as {@see PedigreeBundleReader::read()} gives it.
     *
     * @param array<string, array{0: string|null, 1: string|null}> $people Key => [link ref, gender].
     * @param array<int, array{0: string, 1: string, 2: string}> $relationships [person, relative, KIN code].
     */
    private static function pedigree(string $proband, array $people, array $relationships): array
    {
        $result = ['ok' => true, 'reason' => null, 'probandId' => $proband, 'people' => [], 'relationships' => []];
        foreach ($people as $key => [$ref, $gender]) {
            $result['people'][$key] = ['ref' => $ref, 'gender' => $gender];
        }
        foreach ($relationships as [$person, $relative, $code]) {
            $result['relationships'][] = ['person' => $person, 'relative' => $relative, 'code' => $code];
        }
        return $result;
    }

    /** @return array<string, string> Link ref => code, for linked people. */
    private static function codesByRef(array $pedigree): array
    {
        $result = [];
        foreach (PedigreeRelationships::relationshipsToProband($pedigree) as $key => $code) {
            $result[$pedigree['people'][$key]['ref'] ?? $key] = $code;
        }
        ksort($result);
        return $result;
    }

    public function testExampleRecordOneRows(): void
    {
        $pedigree = self::example('example-record-1.gz.txt');
        $rows = PedigreeRelationships::rows($pedigree, PedigreeRelationships::linkedInstances($pedigree, '1'));

        $this->assertSame([
            ['a' => 2, 'b' => 1, 'type' => 'KIN:027'],
            ['a' => 2, 'b' => 3, 'type' => 'KIN:026'],
            ['a' => 2, 'b' => 4, 'type' => 'KIN:027'],
            ['a' => 2, 'b' => 5, 'type' => 'KIN:027'],
            ['a' => 3, 'b' => 1, 'type' => 'KIN:028'],
            ['a' => 3, 'b' => 4, 'type' => 'KIN:028'],
            ['a' => 3, 'b' => 5, 'type' => 'KIN:028'],
            ['a' => 6, 'b' => 3, 'type' => 'KIN:028'],
            ['a' => 6, 'b' => 8, 'type' => 'KIN:026'],
            ['a' => 7, 'b' => 2, 'type' => 'KIN:027'],
            ['a' => 7, 'b' => 9, 'type' => 'KIN:026'],
            ['a' => 8, 'b' => 3, 'type' => 'KIN:027'],
            ['a' => 9, 'b' => 2, 'type' => 'KIN:028'],
        ], $rows);
    }

    public function testExampleRecordOneRelationshipsMatchTheHandEnteredOnes(): void
    {
        $pedigree = self::example('example-record-1.gz.txt');
        $this->assertSame([
            'record:1/instance:1' => 'proband',
            'record:1/instance:2' => 'mother',
            'record:1/instance:3' => 'father',
            'record:1/instance:4' => 'sibling',
            'record:1/instance:5' => 'sibling',
            'record:1/instance:6' => 'grandparent_paternal',
            'record:1/instance:7' => 'grandparent_maternal',
            'record:1/instance:8' => 'grandparent_paternal',
            'record:1/instance:9' => 'grandparent_maternal',
        ], self::codesByRef($pedigree));
    }

    public function testExampleRecordThreeLeavesTheUnlinkedSisterOut(): void
    {
        $pedigree = self::example('example-record-3.json');
        $linked = PedigreeRelationships::linkedInstances($pedigree, '3');

        $this->assertSame([1, 3, 2], array_values($linked));
        $this->assertSame([
            ['a' => 2, 'b' => 1, 'type' => 'KIN:027'],
            ['a' => 2, 'b' => 3, 'type' => 'KIN:026'],
            ['a' => 3, 'b' => 1, 'type' => 'KIN:028'],
        ], PedigreeRelationships::rows($pedigree, $linked));
        $this->assertContains('sibling', PedigreeRelationships::relationshipsToProband($pedigree), 'computed, though unlinked');
    }

    public function testLinksToAnotherRecordOrAMissingRowOrATakenInstanceDontCount(): void
    {
        $pedigree = self::pedigree('p', [
            'p' => ['record:5/instance:1', 'female'],
            'm' => ['record:6/instance:2', 'female'],
            'f' => ['record:5/instance:3', 'male'],
            'g' => ['record:5/instance:1', 'male'],
            'x' => ['Patient/123', null],
        ], []);

        $this->assertSame(['p' => 1, 'f' => 3], PedigreeRelationships::linkedInstances($pedigree, '5'));
        $this->assertSame(['p' => 1], PedigreeRelationships::linkedInstances($pedigree, '5', [1, 2]));
        $this->assertSame(['m' => 2], PedigreeRelationships::linkedInstances($pedigree, '6'));
        $this->assertSame([], PedigreeRelationships::linkedInstances($pedigree, '50'), 'record names compare exactly');
    }

    public function testPartnersAndTwinsAreStoredOnceWithTheLowerInstanceFirst(): void
    {
        $pedigree = self::pedigree('a', [], [
            ['a', 'b', 'KIN:026'],
            ['b', 'a', 'KIN:026'],
            ['a', 'c', 'KIN:010'],
            ['c', 'd', 'KIN:049'],
        ]);
        $rows = PedigreeRelationships::rows($pedigree, ['a' => 3, 'b' => 2, 'c' => 7, 'd' => 1]);

        $this->assertSame([
            ['a' => 1, 'b' => 7, 'type' => 'KIN:049'],
            ['a' => 2, 'b' => 3, 'type' => 'KIN:026'],
            ['a' => 3, 'b' => 7, 'type' => 'KIN:010'],
        ], $rows);
    }

    public function testParentRowsPutTheParentFirstAndUnknownCodesAreLeftOut(): void
    {
        $pedigree = self::pedigree('c', [], [
            ['c', 'p', 'KIN:003'],
            ['c', 'q', 'KIN:022'],
            ['c', 'p', 'KIN:017'],
            ['c', 'unlinked', 'KIN:027'],
        ]);
        $this->assertSame([
            ['a' => 4, 'b' => 9, 'type' => 'KIN:003'],
            ['a' => 5, 'b' => 9, 'type' => 'KIN:022'],
        ], PedigreeRelationships::rows($pedigree, ['c' => 9, 'p' => 4, 'q' => 5]));
    }

    /** A three-generation family around proband `p`, everyone linked to record 1. */
    private static function family(array $extraPeople = [], array $extraRelationships = []): array
    {
        $people = [];
        $keys = ['p', 'mum', 'dad', 'sis', 'halfDad', 'halfMum', 'stepMum', 'stepDad', 'gmM', 'gfM', 'gmP', 'gfP',
            'auntM', 'auntHusband', 'cousinM', 'uncleP', 'cousinP', 'partner', 'son', 'grandson', 'niece', 'sisHusband',
            'stranger'];
        foreach ($keys as $i => $key) {
            $people[$key] = ['record:1/instance:' . ($i + 1), null];
        }
        $relationships = [
            ['p', 'mum', 'KIN:027'], ['p', 'dad', 'KIN:028'],
            ['sis', 'mum', 'KIN:027'], ['sis', 'dad', 'KIN:028'],
            ['halfDad', 'stepMum', 'KIN:027'], ['halfDad', 'dad', 'KIN:028'],
            ['halfMum', 'mum', 'KIN:027'], ['halfMum', 'stepDad', 'KIN:028'],
            ['mum', 'gmM', 'KIN:027'], ['mum', 'gfM', 'KIN:028'],
            ['auntM', 'gmM', 'KIN:027'], ['auntM', 'gfM', 'KIN:028'],
            ['auntM', 'auntHusband', 'KIN:026'],
            ['cousinM', 'auntM', 'KIN:027'], ['cousinM', 'auntHusband', 'KIN:028'],
            ['dad', 'gmP', 'KIN:027'], ['dad', 'gfP', 'KIN:028'],
            ['uncleP', 'gmP', 'KIN:027'], ['uncleP', 'gfP', 'KIN:028'],
            ['cousinP', 'uncleP', 'KIN:028'],
            ['p', 'partner', 'KIN:026'],
            ['son', 'p', 'KIN:027'], ['son', 'partner', 'KIN:028'],
            ['grandson', 'son', 'KIN:028'],
            ['niece', 'sis', 'KIN:027'], ['niece', 'sisHusband', 'KIN:028'],
            ['mum', 'dad', 'KIN:026'],
        ];
        return self::pedigree('p', $people + $extraPeople, array_merge($relationships, $extraRelationships));
    }

    public function testEveryRelationshipToProbandCode(): void
    {
        $codes = PedigreeRelationships::relationshipsToProband(self::family());

        $this->assertSame([
            'p' => 'proband',
            'mum' => 'mother',
            'dad' => 'father',
            'sis' => 'sibling',
            'halfMum' => 'half_sibling_maternal',
            'halfDad' => 'half_sibling_paternal',
            'son' => 'child',
            'partner' => 'partner',
            'gmM' => 'grandparent_maternal',
            'gfM' => 'grandparent_maternal',
            'gmP' => 'grandparent_paternal',
            'gfP' => 'grandparent_paternal',
            'auntM' => 'aunt_uncle_maternal',
            'uncleP' => 'aunt_uncle_paternal',
            'cousinM' => 'cousin_maternal',
            'cousinP' => 'cousin_paternal',
            'niece' => 'niece_nephew',
            'grandson' => 'grandchild',
        ], array_diff_key($codes, array_flip(['stepMum', 'stepDad', 'auntHusband', 'sisHusband'])));
    }

    public function testInLawsAndStepParentsAreOtherAndStrangersGetNothing(): void
    {
        $codes = PedigreeRelationships::relationshipsToProband(self::family());

        $this->assertSame('other', $codes['auntHusband'], 'an aunt or uncle by marriage');
        $this->assertSame('other', $codes['sisHusband']);
        $this->assertSame('other', $codes['stepMum']);
        $this->assertSame('other', $codes['stepDad']);
        $this->assertArrayNotHasKey('stranger', $codes);
    }

    public function testComputedThroughAnUnlinkedMother(): void
    {
        $pedigree = self::pedigree('p', [
            'p' => ['record:1/instance:1', 'female'],
            'mum' => [null, 'female'],
            'gran' => ['record:1/instance:2', 'female'],
        ], [
            ['p', 'mum', 'KIN:027'],
            ['mum', 'gran', 'KIN:027'],
        ]);
        $this->assertSame([
            'gran' => 'grandparent_maternal',
            'mum' => 'mother',
            'p' => 'proband',
        ], self::sorted(PedigreeRelationships::relationshipsToProband($pedigree)));
    }

    public function testAParentsSideComesFromTheirGenderForAPlainOrAdoptiveParent(): void
    {
        $pedigree = self::pedigree('p', [
            'p' => [null, 'male'],
            'a' => [null, 'female'],
            'b' => [null, 'male'],
            'c' => [null, 'unknown'],
            'aMum' => [null, 'female'],
            'cMum' => [null, 'female'],
        ], [
            ['p', 'a', 'KIN:003'],
            ['p', 'b', 'KIN:022'],
            ['a', 'aMum', 'KIN:027'],
        ]);
        $this->assertSame([
            'a' => 'mother',
            'aMum' => 'grandparent_maternal',
            'b' => 'father',
            'p' => 'proband',
        ], self::sorted(PedigreeRelationships::relationshipsToProband($pedigree)));

        $unknown = self::pedigree('p', ['p' => [null, 'male'], 'c' => [null, 'unknown'], 'cMum' => [null, 'female'], 'half' => [null, null]], [
            ['p', 'c', 'KIN:003'],
            ['c', 'cMum', 'KIN:027'],
            ['half', 'c', 'KIN:003'],
        ]);
        $codes = PedigreeRelationships::relationshipsToProband($unknown);
        $this->assertSame('other', $codes['c'], 'a parent of unknown sex has no side');
        $this->assertSame('other', $codes['cMum']);
        $this->assertSame('sibling', $codes['half'], 'shares every recorded parent');
    }

    public function testAChildWithAnotherParentIsAHalfSiblingWhenTheProbandHasOneParent(): void
    {
        $pedigree = self::pedigree('p', ['p' => [null, 'male'], 'mum' => [null, 'female'], 'x' => [null, 'male'], 'c' => [null, 'female'], 'd' => [null, 'male']], [
            ['p', 'mum', 'KIN:027'],
            ['c', 'mum', 'KIN:027'], ['c', 'x', 'KIN:028'],
            ['d', 'mum', 'KIN:027'],
        ]);
        $codes = PedigreeRelationships::relationshipsToProband($pedigree);
        $this->assertSame('half_sibling_maternal', $codes['c']);
        $this->assertSame('sibling', $codes['d'], 'the same one parent');
    }

    public function testATwinIsASiblingEvenWithFewerParentsRecorded(): void
    {
        $pedigree = self::pedigree('p', ['p' => [null, 'female'], 'mum' => [null, 'female'], 'dad' => [null, 'male'], 't' => [null, 'female']], [
            ['p', 'mum', 'KIN:027'], ['p', 'dad', 'KIN:028'],
            ['t', 'mum', 'KIN:027'],
            ['p', 't', 'KIN:011'],
        ]);
        $this->assertSame('sibling', PedigreeRelationships::relationshipsToProband($pedigree)['t']);
    }

    public function testATwinIsASiblingEvenWithoutParents(): void
    {
        $pedigree = self::pedigree('p', ['p' => [null, 'female'], 't' => [null, 'female']], [['p', 't', 'KIN:010']]);
        $this->assertSame(['p' => 'proband', 't' => 'sibling'], PedigreeRelationships::relationshipsToProband($pedigree));
    }

    public function testConsanguinityGivesTheFirstCodeInOrder(): void
    {
        // The proband's partner is also their maternal cousin.
        $pedigree = self::family([], [['cousinM', 'p', 'KIN:030']]);
        $this->assertSame('partner', PedigreeRelationships::relationshipsToProband($pedigree)['cousinM']);

        // The father is also the mother's maternal cousin: father wins over other.
        $pedigree = self::family([], [['dad', 'auntM', 'KIN:027']]);
        $codes = PedigreeRelationships::relationshipsToProband($pedigree);
        $this->assertSame('father', $codes['dad']);
        $this->assertSame('mother', $codes['mum']);
    }

    public function testAnUnknownProbandGivesNothing(): void
    {
        $pedigree = self::family();
        $pedigree['probandId'] = null;
        $this->assertSame([], PedigreeRelationships::relationshipsToProband($pedigree));
        $pedigree['probandId'] = 'nobody';
        $this->assertSame([], PedigreeRelationships::relationshipsToProband($pedigree));
    }

    private static function sorted(array $codes): array
    {
        ksort($codes);
        return $codes;
    }
}
