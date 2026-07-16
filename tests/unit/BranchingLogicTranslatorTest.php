<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\BranchingLogicTranslator;
use PHPUnit\Framework\TestCase;

class BranchingLogicTranslatorTest extends TestCase
{
    public function testEmptyLogicYieldsNoEnableWhen(): void
    {
        $result = BranchingLogicTranslator::translate('', ['gender' => 'choice']);
        $this->assertNull($result['enableWhen']);
        $this->assertNull($result['warning']);
    }

    public function testSingleEqualityComparison(): void
    {
        $result = BranchingLogicTranslator::translate("[gender] = '2'", ['gender' => 'choice']);
        $this->assertNull($result['warning']);
        $this->assertSame(
            [['question' => 'gender', 'operator' => '=', 'answerCoding' => ['code' => '2']]],
            $result['enableWhen']
        );
    }

    public function testAndChainOfSimpleComparisons(): void
    {
        $result = BranchingLogicTranslator::translate(
            "[gender] = '2' and [age] > 18",
            ['gender' => 'choice', 'age' => 'integer']
        );
        $this->assertNull($result['warning']);
        $this->assertSame(
            [
                ['question' => 'gender', 'operator' => '=', 'answerCoding' => ['code' => '2']],
                ['question' => 'age', 'operator' => '>', 'answerInteger' => 18],
            ],
            $result['enableWhen']
        );
    }

    public function testNotEqualOperatorMapsToBangEquals(): void
    {
        $result = BranchingLogicTranslator::translate("[gender] <> '1'", ['gender' => 'choice']);
        $this->assertSame('!=', $result['enableWhen'][0]['operator']);
    }

    public function testBooleanFieldTypeProducesAnswerBoolean(): void
    {
        $result = BranchingLogicTranslator::translate("[adopted] = '1'", ['adopted' => 'boolean']);
        $this->assertSame(true, $result['enableWhen'][0]['answerBoolean']);
    }

    public function testStringFieldTypeProducesAnswerString(): void
    {
        $result = BranchingLogicTranslator::translate("[notes] = 'foo'", ['notes' => 'string']);
        $this->assertSame('foo', $result['enableWhen'][0]['answerString']);
    }

    public function testOrChainIsUntranslatable(): void
    {
        $result = BranchingLogicTranslator::translate(
            "[gender] = '1' or [gender] = '2'",
            ['gender' => 'choice']
        );
        $this->assertNull($result['enableWhen']);
        $this->assertStringContainsString('OR chain', $result['warning']);
    }

    public function testNestedParenthesesAreUntranslatable(): void
    {
        $result = BranchingLogicTranslator::translate(
            "([gender] = '1')",
            ['gender' => 'choice']
        );
        $this->assertNull($result['enableWhen']);
        $this->assertStringContainsString('parentheses', $result['warning']);
    }

    public function testCheckboxOptionSyntaxIsUntranslatable(): void
    {
        $result = BranchingLogicTranslator::translate(
            "[chk(1)] = '1'",
            ['chk' => 'choice']
        );
        $this->assertNull($result['enableWhen']);
        $this->assertNotNull($result['warning']);
    }

    public function testReferenceToUntaggedFieldIsUntranslatable(): void
    {
        $result = BranchingLogicTranslator::translate(
            "[other_field] = '1'",
            ['gender' => 'choice']
        );
        $this->assertNull($result['enableWhen']);
        $this->assertStringContainsString('other_field', $result['warning']);
    }

    public function testReferenceToAMappedFieldIsUntranslatable(): void
    {
        // A mapsTo/legend-mapped field's value lives in its own dedicated
        // property (e.g. isAdopted), not open-pedigree's generic per-linkId
        // enableWhen-answer map - referencing it would silently never resolve.
        $result = BranchingLogicTranslator::translate(
            "[is_adopted] = '1'",
            ['is_adopted' => 'boolean'],
            ['is_adopted' => true]
        );
        $this->assertNull($result['enableWhen']);
        $this->assertStringContainsString('is_adopted', $result['warning']);
        $this->assertStringContainsString('mapsTo', $result['warning']);
    }
}
