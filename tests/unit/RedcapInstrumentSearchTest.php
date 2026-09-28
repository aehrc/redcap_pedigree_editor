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

    private function relativeRows(): array
    {
        return [
            ['record' => '1', 'instance' => 1, 'fields' => ['first_name' => 'Grace', 'relationship' => 'mother']],
            ['record' => '1', 'instance' => 2, 'fields' => ['first_name' => 'Arthur', 'relationship' => 'grandparent_paternal']],
            ['record' => '1', 'instance' => 3, 'fields' => ['first_name' => 'Pat', 'relationship' => 'unlisted_code']],
        ];
    }

    private function relationshipLabels(): array
    {
        return ['relationship' => ['mother' => 'Mother', 'grandparent_paternal' => "Grandparent (father's side)"]];
    }

    public function testACodedFieldIsDisplayedByItsLabel(): void
    {
        $results = RedcapInstrumentSearch::search($this->relativeRows(), ['first_name', 'relationship'], '', 20, null, null, $this->relationshipLabels());
        $this->assertSame(['Grace Mother', "Arthur Grandparent (father's side)", 'Pat unlisted_code'], array_column($results, 'display'));
    }

    public function testASearchMatchesTheLabelNotTheCode(): void
    {
        $labels = $this->relationshipLabels();
        $this->assertSame([], RedcapInstrumentSearch::search($this->relativeRows(), ['first_name', 'relationship'], 'paternal', 20, null, null, $labels));
        $this->assertSame(['Arthur'], array_map(
            fn ($r) => explode(' ', $r['display'])[0],
            RedcapInstrumentSearch::search($this->relativeRows(), ['first_name', 'relationship'], "father's", 20, null, null, $labels)
        ));
    }

    public function testWithoutLabelsTheStoredValueIsDisplayed(): void
    {
        $results = RedcapInstrumentSearch::search($this->relativeRows(), ['first_name', 'relationship'], 'mother');
        $this->assertSame(['Grace mother'], array_column($results, 'display'));
    }

    public function testChoiceLabelsComeFromTheDataDictionary(): void
    {
        $dataDictionary = [
            'first_name' => ['field_type' => 'text', 'select_choices_or_calculations' => ''],
            'relationship' => ['field_type' => 'dropdown', 'select_choices_or_calculations' => "mother, Mother | grandparent_paternal, Grandparent (father's side) | 0, None, not related"],
            'side' => ['field_type' => 'radio', 'select_choices_or_calculations' => '1, Maternal | 2, Paternal'],
            'tested' => ['field_type' => 'yesno', 'select_choices_or_calculations' => ''],
            'confirmed' => ['field_type' => 'truefalse', 'select_choices_or_calculations' => ''],
            'not_searched' => ['field_type' => 'radio', 'select_choices_or_calculations' => '1, One'],
        ];
        $this->assertSame([
            'relationship' => ['mother' => 'Mother', 'grandparent_paternal' => "Grandparent (father's side)", '0' => 'None, not related'],
            'side' => ['1' => 'Maternal', '2' => 'Paternal'],
            'tested' => ['1' => 'Yes', '0' => 'No'],
            'confirmed' => ['1' => 'True', '0' => 'False'],
        ], RedcapInstrumentSearch::choiceLabels($dataDictionary, ['first_name', 'relationship', 'side', 'tested', 'confirmed', 'missing_field']));
    }

    public function testChoiceLabelsDropHtmlFromTheLabel(): void
    {
        // REDCap lets a choice label carry HTML, stored as-is or entity-encoded.
        $dataDictionary = [
            'relationship' => ['field_type' => 'dropdown', 'select_choices_or_calculations' =>
                '1, <span style="color:red">Mother</span> | 2, &lt;b&gt;Father&lt;/b&gt; | 3, Aunt &amp; uncle | 4, <i></i>'],
        ];
        $this->assertSame(
            ['relationship' => ['1' => 'Mother', '2' => 'Father', '3' => 'Aunt & uncle', '4' => '4']],
            RedcapInstrumentSearch::choiceLabels($dataDictionary, ['relationship'])
        );
    }
}
