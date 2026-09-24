<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentRowImporter;
use PHPUnit\Framework\TestCase;

class RedcapInstrumentRowImporterTest extends TestCase
{
    private function field(array $overrides): array
    {
        return array_merge([
            'redcapField' => 'field',
            'linkId' => 'field',
            'type' => 'string',
            'repeats' => false,
            'choices' => [],
        ], $overrides);
    }

    public function testStringFieldPassesThrough(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['comments' => 'Some notes'],
            [$this->field(['redcapField' => 'comments', 'linkId' => 'comments'])]
        );
        $this->assertSame([['linkId' => 'comments', 'value' => 'Some notes']], $answers);
    }

    public function testMissingOrEmptyValueIsOmitted(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['comments' => ''],
            [$this->field(['redcapField' => 'comments', 'linkId' => 'comments'])]
        );
        $this->assertSame([], $answers);
    }

    public function testBooleanFieldConvertsRedcapOneZeroToRealBoolean(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['adopted' => '1'],
            [$this->field(['redcapField' => 'adopted', 'linkId' => 'adopted', 'type' => 'boolean'])]
        );
        $this->assertSame(true, $answers[0]['value']);
        $this->assertNotSame('1', $answers[0]['value']);
    }

    public function testIntegerAndDecimalFieldsAreCast(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['age' => '42', 'height' => '1.8'],
            [
                $this->field(['redcapField' => 'age', 'linkId' => 'age', 'type' => 'integer']),
                $this->field(['redcapField' => 'height', 'linkId' => 'height', 'type' => 'decimal']),
            ]
        );
        $this->assertSame(42, $answers[0]['value']);
        $this->assertSame(1.8, $answers[1]['value']);
    }

    public function testSingleChoiceFieldReturnsRawCode(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['gender_field' => '2'],
            [$this->field(['redcapField' => 'gender_field', 'linkId' => 'gender_field', 'type' => 'choice', 'choices' => ['1' => 'Male', '2' => 'Female']])]
        );
        $this->assertSame('2', $answers[0]['value']);
    }

    public function testRepeatingChoiceFieldReturnsCheckedCodesWhenNotLegendMapped(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['symptoms___1' => '1', 'symptoms___2' => '0', 'symptoms___3' => '1'],
            [$this->field([
                'redcapField' => 'symptoms',
                'linkId' => 'symptoms',
                'type' => 'choice',
                'repeats' => true,
                'choices' => ['1' => 'Fever', '2' => 'Cough', '3' => 'Rash'],
            ])]
        );
        $this->assertSame(['1', '3'], $answers[0]['value']);
    }

    public function testRepeatingChoiceFieldReturnsIdNamePairsWhenLegendMapped(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['family_disorders___omim1' => '1', 'family_disorders___omim2' => '0'],
            [$this->field([
                'redcapField' => 'family_disorders',
                'linkId' => 'disorders',
                'type' => 'choice',
                'repeats' => true,
                'choices' => ['omim1' => 'Marfan syndrome', 'omim2' => 'Some other disorder'],
            ])]
        );
        $this->assertSame(
            [['id' => 'omim1', 'name' => 'Marfan syndrome']],
            $answers[0]['value']
        );
    }

    public function testRepeatingChoiceFieldWithCustomLinkIdStillReturnsRawCodes(): void
    {
        // ADVANCED mode lets an admin declare any custom linkId for an
        // ordinary field - linkId differing from redcapField must not, by
        // itself, be mistaken for one of the three reserved legend targets
        // (disorders/candidate_genes/hpo_positive).
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['symptoms___1' => '1', 'symptoms___2' => '0'],
            [$this->field([
                'redcapField' => 'symptoms',
                'linkId' => 'my_custom_linkid',
                'type' => 'choice',
                'repeats' => true,
                'choices' => ['1' => 'Fever', '2' => 'Cough'],
            ])]
        );
        $this->assertSame(['1'], $answers[0]['value']);
    }

    public function testNoCheckedOptionsIsOmitted(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['symptoms___1' => '0'],
            [$this->field(['redcapField' => 'symptoms', 'linkId' => 'symptoms', 'type' => 'choice', 'repeats' => true, 'choices' => ['1' => 'Fever']])]
        );
        $this->assertSame([], $answers);
    }

    public function testMultipleFieldsProduceMultipleAnswers(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['first_name' => 'Alice', 'age' => '30'],
            [
                $this->field(['redcapField' => 'first_name', 'linkId' => 'first_name']),
                $this->field(['redcapField' => 'age', 'linkId' => 'age', 'type' => 'integer']),
            ]
        );
        $this->assertSame(
            [
                ['linkId' => 'first_name', 'value' => 'Alice'],
                ['linkId' => 'age', 'value' => 30],
            ],
            $answers
        );
    }

    public function testCheckboxCodesMatchRedcapExportColumnNames(): void
    {
        // REDCap lowercases checkbox codes and turns '-'/'.' into '_' in export
        // column names; the original codes must still come back as the answer.
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['symptoms___1' => '0', 'symptoms___a' => '1', 'symptoms____2' => '1', 'symptoms___1_5' => '1'],
            [$this->field([
                'redcapField' => 'symptoms',
                'linkId' => 'symptoms',
                'type' => 'choice',
                'repeats' => true,
                'choices' => ['1' => 'Fever', 'A' => 'Other', '-2' => 'Negative code', '1.5' => 'Decimal code'],
            ])]
        );
        $this->assertSame(['A', '-2', '1.5'], $answers[0]['value']);
    }

    public function testRawCaseCheckboxKeyIsNotRead(): void
    {
        // Only REDCap's export form counts; a raw-case key must not be read as well.
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['symptoms___A' => '1', 'symptoms___a' => '0'],
            [$this->field(['redcapField' => 'symptoms', 'linkId' => 'symptoms', 'type' => 'choice', 'repeats' => true, 'choices' => ['A' => 'Other']])]
        );
        $this->assertSame([], $answers);
    }

    public function testLegendEntriesKeepOriginalCodesAndNames(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['dx___a' => '1'],
            [$this->field(['redcapField' => 'dx', 'linkId' => 'disorders', 'type' => 'choice', 'repeats' => true, 'choices' => ['A' => 'Disorder A']])]
        );
        $this->assertSame([['id' => 'A', 'name' => 'Disorder A']], $answers[0]['value']);
    }
}
