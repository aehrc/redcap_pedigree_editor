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

    private function rows(): array
    {
        return [
            ['record' => '7', 'instance' => 1, 'fields' => ['comments' => 'First']],
            ['record' => '7', 'instance' => 2, 'fields' => ['comments' => '']],
        ];
    }

    public function testPresentRowsComeBackAsTheImportShapesThem(): void
    {
        $result = RedcapInstrumentLinkedRows::answer(
            ['record:7/instance:1', 'record:7/instance:2'],
            '7',
            $this->rows(),
            $this->fields()
        );
        $this->assertSame([
            'record:7/instance:1' => ['answers' => RedcapInstrumentRowImporter::buildAnswers(['comments' => 'First'], $this->fields())],
            'record:7/instance:2' => ['answers' => [['linkId' => 'comments', 'value' => null]]],
        ], $result);
    }

    public function testMissingInstanceIsAnsweredOnItsOwn(): void
    {
        $result = RedcapInstrumentLinkedRows::answer(['record:7/instance:9', 'record:7/instance:1'], '7', $this->rows(), $this->fields());
        $this->assertSame(['missing' => true], $result['record:7/instance:9']);
        $this->assertArrayHasKey('answers', $result['record:7/instance:1']);
    }

    public function testAnotherRecordsRefIsNotRead(): void
    {
        // Even if that record's row were among the rows given, it isn't this record's.
        $rows = array_merge($this->rows(), [['record' => '8', 'instance' => 1, 'fields' => ['comments' => 'Other']]]);
        $result = RedcapInstrumentLinkedRows::answer(['record:8/instance:1'], '7', $rows, $this->fields());
        $this->assertSame(['record:8/instance:1' => ['otherRecord' => true]], $result);
    }

    public function testRecordNamePrefixIsNotTheSameRecord(): void
    {
        $result = RedcapInstrumentLinkedRows::answer(['record:77/instance:1'], '7', $this->rows(), $this->fields());
        $this->assertSame(['otherRecord' => true], $result['record:77/instance:1']);
    }

    public function testMalformedRefCountsAsAnotherRecords(): void
    {
        $result = RedcapInstrumentLinkedRows::answer(['Patient/123', 'record:7/instance:x'], '7', $this->rows(), $this->fields());
        $this->assertSame([
            'Patient/123' => ['otherRecord' => true],
            'record:7/instance:x' => ['otherRecord' => true],
        ], $result);
    }

    public function testRepeatedRefIsAnsweredOnce(): void
    {
        $result = RedcapInstrumentLinkedRows::answer(['record:7/instance:1', 'record:7/instance:1'], '7', $this->rows(), $this->fields());
        $this->assertCount(1, $result);
    }

    public function testParseRefsTakesAJsonListOfStrings(): void
    {
        $this->assertSame(['record:7/instance:1'], RedcapInstrumentLinkedRows::parseRefs('["record:7/instance:1"]'));
        $this->assertSame([], RedcapInstrumentLinkedRows::parseRefs('[]'));
    }

    public function testParseRefsRejectsOtherShapes(): void
    {
        $this->assertNull(RedcapInstrumentLinkedRows::parseRefs(null));
        $this->assertNull(RedcapInstrumentLinkedRows::parseRefs(['record:7/instance:1']));
        $this->assertNull(RedcapInstrumentLinkedRows::parseRefs('not json'));
        $this->assertNull(RedcapInstrumentLinkedRows::parseRefs('"record:7/instance:1"'));
        $this->assertNull(RedcapInstrumentLinkedRows::parseRefs('{"a":"record:7/instance:1"}'));
        $this->assertNull(RedcapInstrumentLinkedRows::parseRefs('[1]'));
    }

    public function testParseRefsRejectsMoreThanTheCap(): void
    {
        $atCap = array_fill(0, RedcapInstrumentLinkedRows::MAX_REFS, 'record:7/instance:1');
        $this->assertCount(RedcapInstrumentLinkedRows::MAX_REFS, RedcapInstrumentLinkedRows::parseRefs(json_encode($atCap)));
        $this->assertNull(RedcapInstrumentLinkedRows::parseRefs(json_encode(array_merge($atCap, ['record:7/instance:2']))));
    }
}
