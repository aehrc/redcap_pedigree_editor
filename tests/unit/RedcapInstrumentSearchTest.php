<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentSearch;
use PHPUnit\Framework\TestCase;

class RedcapInstrumentSearchTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['record' => '1', 'instance' => 1, 'fields' => ['first_name' => 'Alice', 'last_name' => 'Smith']],
            ['record' => '1', 'instance' => 2, 'fields' => ['first_name' => 'Bob', 'last_name' => 'Smith']],
            ['record' => '2', 'instance' => 1, 'fields' => ['first_name' => 'Carol', 'last_name' => 'Jones']],
        ];
    }

    public function testEmptyQueryMatchesEveryRow(): void
    {
        $results = RedcapInstrumentSearch::search($this->rows(), ['first_name', 'last_name'], '');
        $this->assertCount(3, $results);
    }

    public function testCaseInsensitiveSubstringMatch(): void
    {
        $results = RedcapInstrumentSearch::search($this->rows(), ['first_name', 'last_name'], 'smith');
        $this->assertCount(2, $results);
    }

    public function testMatchReturnsRecordInstanceDisplayAndRef(): void
    {
        $results = RedcapInstrumentSearch::search($this->rows(), ['first_name', 'last_name'], 'carol');
        $this->assertCount(1, $results);
        $this->assertSame([
            'record' => '2',
            'instance' => 1,
            'display' => 'Carol Jones',
            'ref' => 'record:2/instance:1',
        ], $results[0]);
    }

    public function testLimitCapsResultCount(): void
    {
        $results = RedcapInstrumentSearch::search($this->rows(), ['first_name', 'last_name'], '', 2);
        $this->assertCount(2, $results);
    }

    public function testNoMatchesReturnsEmptyArray(): void
    {
        $results = RedcapInstrumentSearch::search($this->rows(), ['first_name', 'last_name'], 'nonexistent');
        $this->assertSame([], $results);
    }

    public function testFieldValueOfZeroIsNotTreatedAsAbsent(): void
    {
        $rows = [
            ['record' => '1', 'instance' => 1, 'fields' => ['kindred_code' => '0', 'first_name' => 'Alice']],
        ];
        $results = RedcapInstrumentSearch::search($rows, ['kindred_code', 'first_name'], '0');
        $this->assertCount(1, $results);
        $this->assertSame('0 Alice', $results[0]['display']);
    }

    private function genderedRows(): array
    {
        return [
            ['record' => '1', 'instance' => 1, 'fields' => ['first_name' => 'Alice', 'gender' => 'F']],
            ['record' => '1', 'instance' => 2, 'fields' => ['first_name' => 'Bob', 'gender' => 'M']],
            ['record' => '1', 'instance' => 3, 'fields' => ['first_name' => 'Sam', 'gender' => '9']],
        ];
    }

    public function testGenderIsOmittedWhenNoGenderFieldConfigured(): void
    {
        $results = RedcapInstrumentSearch::search($this->genderedRows(), ['first_name'], '');
        $this->assertArrayNotHasKey('gender', $results[0]);
    }

    public function testGenderIsAttachedWhenAGenderFieldIsConfigured(): void
    {
        $results = RedcapInstrumentSearch::search($this->genderedRows(), ['first_name'], '', 20, 'gender');
        $this->assertSame(['F', 'M', 'U'], array_column($results, 'gender'));
    }

    public function testNonMFCodesNormalizeToU(): void
    {
        // A raw REDCap code of "9" (or unset/empty) is neither 'M' nor 'F' -
        // normalized to 'U' ("unknown"), matching open-pedigree's own gender
        // getter and this module's README, so a project using non-standard
        // choice codes degrades to "unfiltered" rather than never matching.
        $results = RedcapInstrumentSearch::search($this->genderedRows(), ['first_name'], '', 20, 'gender');
        $this->assertSame('U', $results[2]['gender']);
    }

    public function testAllowedGendersFiltersOutIncompatibleRows(): void
    {
        $results = RedcapInstrumentSearch::search($this->genderedRows(), ['first_name'], '', 20, 'gender', ['M', 'U']);
        $this->assertSame(['Bob', 'Sam'], array_column($results, 'display'));
    }

    public function testAllowedGendersNeverFiltersARowWithNoGenderFieldConfigured(): void
    {
        // $allowedGenders is meaningless without a $genderFieldName - must not
        // accidentally exclude every row just because it was passed anyway.
        $results = RedcapInstrumentSearch::search($this->genderedRows(), ['first_name'], '', 20, null, ['M']);
        $this->assertCount(3, $results);
    }

    public function testAllowedGendersIsAppliedBeforeTheResultLimit(): void
    {
        // A limit smaller than the incompatible-gender rows that sort first
        // must not crowd out a compatible row that exists beyond that cutoff -
        // filtering has to happen before, not after, truncation.
        $rows = [
            ['record' => '1', 'instance' => 1, 'fields' => ['first_name' => 'IncompatibleA', 'gender' => 'F']],
            ['record' => '1', 'instance' => 2, 'fields' => ['first_name' => 'IncompatibleB', 'gender' => 'F']],
            ['record' => '1', 'instance' => 3, 'fields' => ['first_name' => 'CompatibleC', 'gender' => 'M']],
        ];
        $results = RedcapInstrumentSearch::search($rows, ['first_name'], '', 1, 'gender', ['M']);
        $this->assertSame(['CompatibleC'], array_column($results, 'display'));
    }
}
