<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\OntologyValueSetResolver;
use PHPUnit\Framework\TestCase;

class OntologyValueSetResolverTest extends TestCase
{
    private function advancedCategories(): array
    {
        return [
            [
                'ontology-id' => 'omim-disorders',
                'valueset-type' => 'url',
                'valueset' => 'http://www.omim.org/vs',
            ],
            [
                'ontology-id' => 'inline-resource',
                'valueset-type' => 'resource',
                'valueset' => '{"resourceType":"ValueSet"}',
            ],
        ];
    }

    public function testResolvesUrlTypeAdvancedProviderCategory(): void
    {
        $result = OntologyValueSetResolver::resolve(
            ['disorder_field' => 'ADVFHIR:omim-disorders'],
            $this->advancedCategories()
        );
        $this->assertSame(['disorder_field' => 'http://www.omim.org/vs'], $result);
    }

    public function testFieldWithNoElementEnumIsSkipped(): void
    {
        $result = OntologyValueSetResolver::resolve(['plain_field' => ''], $this->advancedCategories());
        $this->assertSame([], $result);
    }

    public function testSimpleOntologyProviderServiceIsNotResolved(): void
    {
        $result = OntologyValueSetResolver::resolve(
            ['simple_field' => 'SIMPLE:some-category'],
            $this->advancedCategories()
        );
        $this->assertSame([], $result);
    }

    public function testResourceTypeValuesetIsNotResolved(): void
    {
        $result = OntologyValueSetResolver::resolve(
            ['resource_field' => 'ADVFHIR:inline-resource'],
            $this->advancedCategories()
        );
        $this->assertSame([], $result);
    }

    public function testUnknownCategoryIdIsSkipped(): void
    {
        $result = OntologyValueSetResolver::resolve(
            ['field' => 'ADVFHIR:does-not-exist'],
            $this->advancedCategories()
        );
        $this->assertSame([], $result);
    }
}
