<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\FhirUrls;
use AEHRC\PedigreeEditorExternalModule\PedigreeBundleReader;
use AEHRC\PedigreeEditorExternalModule\QuestionnaireDerivation;
use PHPUnit\Framework\TestCase;

/**
 * open-pedigree 2.0.0's FHIR URLs: the module uses only those, and only from FhirUrls.
 */
class FhirUrlsTest extends TestCase
{
    public function testTheModuleUsesOpenPedigreesPublishedUrls(): void
    {
        $this->assertSame('https://aehrc.github.io/open-pedigree/fhir/StructureDefinition/linked-record-ref', PedigreeBundleReader::LINK_EXTENSION_URL);
        $this->assertSame('https://aehrc.github.io/open-pedigree/fhir/StructureDefinition/questionnaire-field-mapping', QuestionnaireDerivation::MAPPING_EXTENSION_URL);
        $this->assertSame('https://aehrc.github.io/open-pedigree/fhir/StructureDefinition/questionnaire-redcap-source', QuestionnaireDerivation::REDCAP_SOURCE_EXTENSION_URL);
        $this->assertStringStartsWith(FhirUrls::BASE, FhirUrls::PEDIGREE_INDIVIDUAL);
    }

    public function testFindsTheRetiredUrlsAQuestionnaireStillUses(): void
    {
        $json = json_encode(['resourceType' => 'Questionnaire', 'item' => [[
            'linkId' => 'x',
            'definition' => 'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual#PedigreeIndividual.lifeStatus',
            'extension' => [
                ['url' => 'https://github.com/aehrc/open-pedigree/questionnaire-redcap-source', 'extension' => []],
                ['url' => 'https://github.com/aehrc/open-pedigree/questionnaire-redcap-source', 'extension' => []],
                ['url' => FhirUrls::QUESTIONNAIRE_FIELD_MAPPING, 'valueCode' => 'mapsToField'],
            ],
            'code' => [['system' => 'https://github.com/aehrc/open-pedigree?externalID', 'code' => '1']],
        ]]]);
        // (json_encode escapes the slashes)
        $this->assertStringContainsString('\\/', $json);
        $this->assertSame([
            'https://github.com/aehrc/open-pedigree/StructureDefinition/PedigreeIndividual' => FhirUrls::PEDIGREE_INDIVIDUAL,
            'https://github.com/aehrc/open-pedigree/questionnaire-redcap-source' => FhirUrls::QUESTIONNAIRE_REDCAP_SOURCE,
        ], FhirUrls::retiredUrlsIn($json));
        $this->assertSame([], FhirUrls::retiredUrlsIn('{"url":"' . FhirUrls::QUESTIONNAIRE_ACTION . '"}'));
        // Free text and the Questionnaire's own canonical aren't read as extensions.
        $this->assertSame([], FhirUrls::retiredUrlsIn(json_encode([
            'resourceType' => 'Questionnaire',
            'url' => 'https://github.com/aehrc/open-pedigree/Questionnaire/default',
            'description' => 'Migrated from https://github.com/aehrc/open-pedigree/questionnaire-field-mapping',
            'item' => [['linkId' => 'x', 'text' => 'see https://github.com/aehrc/open-pedigree/questionnaire-action']],
        ])));
    }

    public function testNoCodeNamesAnOldUrl(): void
    {
        $root = dirname(__DIR__, 2);
        // (the bundled open-pedigree too: it and the module must agree)
        $files = array_merge(glob($root . '/classes/*.php'), glob($root . '/*.php'), glob($root . '/js/*.js'),
            [$root . '/open-pedigree/localEditor.html', $root . '/open-pedigree/dist/pedigree.min.js',
                $root . '/open-pedigree/dist/defaultQuestionnaire.json']);
        $offenders = [];
        foreach ($files as $file) {
            if (preg_match('#github\.com/aehrc/open-pedigree/(StructureDefinition|Questionnaire|questionnaire-)#', file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }
        $this->assertSame([], $offenders);
    }

    public function testNoExampleProjectOrFixtureHoldsAnOldUrl(): void
    {
        $compressedSeen = 0;
        $root = dirname(__DIR__, 2);
        $files = array_merge(glob($root . '/documentation/*/*.xml'), glob($root . '/documentation/*/*.json'), glob($root . '/tests/fixtures/*'));
        $offenders = [];
        foreach ($files as $file) {
            $text = file_get_contents($file);
            // (and inside each compressed pedigree)
            // (as scripts/rewrite-fhir-urls.js reads them: base64 whose slashes JSON may escape)
            preg_match_all('#GZ:([A-Za-z0-9+/\\\\]+=*)#', $text, $compressed);
            $this->assertSame(PREG_NO_ERROR, preg_last_error());
            $compressedSeen += count($compressed[1]);
            foreach ($compressed[1] as $base64) {
                $inner = @gzdecode((string) base64_decode(str_replace('\\/', '/', $base64), true));
                $this->assertIsString($inner, 'a compressed value in ' . basename($file) . ' could not be read');
                $text .= $inner;
            }
            if (preg_match('#github\.com\\\\?/aehrc\\\\?/open-pedigree\\\\?/(StructureDefinition|Questionnaire|questionnaire-)#', $text)) {
                $offenders[] = substr($file, strlen($root) + 1);
            }
        }
        $this->assertNotEmpty($files);
        // (the examples hold compressed pedigrees: the scan must have read them)
        $this->assertGreaterThan(0, $compressedSeen);
        $this->assertSame([], $offenders);
    }
}
