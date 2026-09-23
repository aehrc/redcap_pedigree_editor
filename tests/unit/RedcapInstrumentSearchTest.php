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
}
