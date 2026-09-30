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

    public function testEmptyValueIsSentAsNull(): void
    {
        // The answers are the row's full state: open-pedigree clears a value the record emptied.
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['comments' => ''],
            [$this->field(['redcapField' => 'comments', 'linkId' => 'comments'])]
        );
        $this->assertSame([['linkId' => 'comments', 'value' => null]], $answers);
    }

    public function testFieldAbsentFromRowIsOmitted(): void
    {
        // Not fetched at all is "unknown", not "empty".
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            [],
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

    public function testNoCheckedOptionsIsSentAsNull(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['symptoms___1' => '0'],
            [$this->field(['redcapField' => 'symptoms', 'linkId' => 'symptoms', 'type' => 'choice', 'repeats' => true, 'choices' => ['1' => 'Fever']])]
        );
        $this->assertSame([['linkId' => 'symptoms', 'value' => null]], $answers);
    }

    public function testCheckboxAbsentFromRowIsOmitted(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['other___1' => '1'],
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
        // The field is present (its export column symptoms___a is unticked), so it's sent - as
        // null, since nothing is ticked - and the raw-case symptoms___A isn't read.
        $this->assertSame([['linkId' => 'symptoms', 'value' => null]], $answers);
    }

    public function testLegendEntriesKeepOriginalCodesAndNames(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['dx___a' => '1'],
            [$this->field(['redcapField' => 'dx', 'linkId' => 'disorders', 'type' => 'choice', 'repeats' => true, 'choices' => ['A' => 'Disorder A']])]
        );
        $this->assertSame([['id' => 'A', 'name' => 'Disorder A']], $answers[0]['value']);
    }

    public function testFieldsSharingALinkIdAreSentAsOneAnswer(): void
    {
        // Two checkbox fields feeding the disorders legend, one of them empty (e.g. hidden by
        // branching): one combined answer, not a list plus a null that reads as "emptied".
        $disorderField = function (string $name) {
            return $this->field(['redcapField' => $name, 'linkId' => 'disorders', 'type' => 'choice', 'repeats' => true, 'choices' => ['1' => 'One', '2' => 'Two']]);
        };
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['dx_a___1' => '1', 'dx_a___2' => '0', 'dx_b___1' => '0', 'dx_b___2' => '0'],
            [$disorderField('dx_a'), $disorderField('dx_b')]
        );
        $this->assertSame([['linkId' => 'disorders', 'value' => [['id' => '1', 'name' => 'One']]]], $answers);

        $both = RedcapInstrumentRowImporter::buildAnswers(
            ['dx_a___1' => '1', 'dx_a___2' => '0', 'dx_b___1' => '1', 'dx_b___2' => '1'],
            [$disorderField('dx_a'), $disorderField('dx_b')]
        );
        $this->assertSame([['linkId' => 'disorders', 'value' => [['id' => '1', 'name' => 'One'], ['id' => '2', 'name' => 'Two']]]], $both);
    }

    public function testASingleValueFieldFeedingALegendGivesItOneEntry(): void
    {
        $legendField = function (string $name) {
            return $this->field(['redcapField' => $name, 'linkId' => 'disorders']);
        };
        // redcap_fhir_ontology_provider stores code|system
        $answers = RedcapInstrumentRowImporter::buildAnswers(['dx' => 'C0000001|http://example.org/cs'], [$legendField('dx')]);
        $this->assertSame([['linkId' => 'disorders', 'value' => [['id' => 'C0000001', 'name' => 'C0000001']]]], $answers);
        // advanced_fhir_ontology_provider stores a bare code
        $answers = RedcapInstrumentRowImporter::buildAnswers(['dx' => '71641006'], [$legendField('dx')]);
        $this->assertSame([['id' => '71641006', 'name' => '71641006']], $answers[0]['value']);
        // empty is null, like any empty field
        $answers = RedcapInstrumentRowImporter::buildAnswers(['dx' => ''], [$legendField('dx')]);
        $this->assertSame([['linkId' => 'disorders', 'value' => null]], $answers);
    }

    public function testSeveralSingleValueFieldsFeedingALegendAreCombined(): void
    {
        $legendField = function (string $name) {
            return $this->field(['redcapField' => $name, 'linkId' => 'disorders']);
        };
        $fields = [$legendField('primary'), $legendField('secondary'), $legendField('third')];
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['primary' => '111|http://snomed.info/sct', 'secondary' => '', 'third' => '222|http://snomed.info/sct'],
            $fields
        );
        $this->assertSame([['linkId' => 'disorders', 'value' => [['id' => '111', 'name' => '111'], ['id' => '222', 'name' => '222']]]], $answers);
        // the same code twice is one entry; none filled is null
        $answers = RedcapInstrumentRowImporter::buildAnswers(['primary' => '111', 'secondary' => '111|http://snomed.info/sct', 'third' => ''], $fields);
        $this->assertSame([['id' => '111', 'name' => '111']], $answers[0]['value']);
        $answers = RedcapInstrumentRowImporter::buildAnswers(['primary' => '', 'secondary' => '', 'third' => ''], $fields);
        $this->assertSame([['linkId' => 'disorders', 'value' => null]], $answers);
    }

    public function testACustomLegendItemTakesCodings(): void
    {
        // ADVANCED mode: an item with its own linkId and a legend mapping - open-pedigree stores
        // a custom legend's entries as {system, code, display}.
        $field = function ($name, $overrides = []) {
            return $this->field(array_merge(['redcapField' => $name, 'linkId' => 'my_conditions', 'legend' => true], $overrides));
        };
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['dx_a' => '111|http://snomed.info/sct', 'dx_b' => '71641006'],
            [$field('dx_a'), $field('dx_b')]
        );
        $this->assertSame([['linkId' => 'my_conditions', 'value' => [
            ['system' => 'http://snomed.info/sct', 'code' => '111', 'display' => '111'],
            ['code' => '71641006', 'display' => '71641006'],
        ]]], $answers);
        // the same code from two fields, one with its system, is one entry (the first)
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['dx_a' => '111|http://snomed.info/sct', 'dx_b' => '111'],
            [$field('dx_a'), $field('dx_b')]
        );
        $this->assertSame([['system' => 'http://snomed.info/sct', 'code' => '111', 'display' => '111']], $answers[0]['value']);
        // a checkbox feeding it
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['cb___1' => '1', 'cb___2' => '0'],
            [$field('cb', ['type' => 'choice', 'repeats' => true, 'choices' => ['1' => 'One', '2' => 'Two']])]
        );
        $this->assertSame([['code' => '1', 'display' => 'One']], $answers[0]['value']);
        // not a legend: raw codes, as before
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['cb___1' => '1', 'cb___2' => '0'],
            [$field('cb', ['type' => 'choice', 'repeats' => true, 'choices' => ['1' => 'One', '2' => 'Two'], 'legend' => false])]
        );
        $this->assertSame(['1'], $answers[0]['value']);
    }

    public function testASingleChoiceFeedingALegendUsesItsLabel(): void
    {
        // e.g. an ADVANCED-mode Questionnaire item "disorders" sourced from a dropdown
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['dx' => '615688'],
            [$this->field(['redcapField' => 'dx', 'linkId' => 'disorders', 'type' => 'choice', 'choices' => ['615688' => 'ADA2 deficiency']])]
        );
        $this->assertSame([['id' => '615688', 'name' => 'ADA2 deficiency']], $answers[0]['value']);
    }

    public function testSingleValueSharingALinkIdTakesTheFirstNonEmpty(): void
    {
        $answers = RedcapInstrumentRowImporter::buildAnswers(
            ['name_a' => '', 'name_b' => 'Alice'],
            [
                $this->field(['redcapField' => 'name_a', 'linkId' => 'first_name']),
                $this->field(['redcapField' => 'name_b', 'linkId' => 'first_name']),
            ]
        );
        $this->assertSame([['linkId' => 'first_name', 'value' => 'Alice']], $answers);
    }
}
