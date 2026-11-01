<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentLinkedRows;
use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentRowImporter;
use PHPUnit\Framework\TestCase;

class RedcapInstrumentLinkedRowsTest extends TestCase
{
    private function fields(): array
    {
        return [[
            'redcapField' => 'comments',
            'linkId' => 'comments',
            'type' => 'string',
            'repeats' => false,
            'choices' => [],
        ]];
    }

    public function testEachRowIsKeyedByItsRefWithTheImportsAnswers(): void
    {
        $rows = [
            ['record' => '7', 'instance' => 1, 'fields' => ['comments' => 'First']],
            ['record' => '7', 'instance' => 2, 'fields' => ['comments' => '']],
        ];
        $this->assertSame([
            'record:7/instance:1' => RedcapInstrumentRowImporter::buildAnswers(['comments' => 'First'], $this->fields()),
            'record:7/instance:2' => [['linkId' => 'comments', 'value' => null]],
        ], RedcapInstrumentLinkedRows::answerAll($rows, $this->fields()));
    }

    public function testTheRecordNameIsKeptAsStored(): void
    {
        // The ref is built from the name getData returns, so it matches the refs the pedigree saved.
        $rows = [['record' => 'ABC-01', 'instance' => 3, 'fields' => ['comments' => 'x']]];
        $this->assertSame(['record:ABC-01/instance:3'], array_keys(RedcapInstrumentLinkedRows::answerAll($rows, $this->fields())));
    }

    public function testNoRowsIsNoAnswers(): void
    {
        $this->assertSame([], RedcapInstrumentLinkedRows::answerAll([], $this->fields()));
    }
}
