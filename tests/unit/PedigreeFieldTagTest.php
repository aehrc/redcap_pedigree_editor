<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\PedigreeFieldTag;
use PHPUnit\Framework\TestCase;

class PedigreeFieldTagTest extends TestCase
{
    public function testAbsentTagIsNotPresent(): void
    {
        $tag = PedigreeFieldTag::parse('@SOME_OTHER_TAG');
        $this->assertFalse($tag->present);
    }

    public function testNullAnnotationIsNotPresent(): void
    {
        $tag = PedigreeFieldTag::parse(null);
        $this->assertFalse($tag->present);
    }

    public function testBareTagIsPresentWithNoParams(): void
    {
        $tag = PedigreeFieldTag::parse('@PEDIGREE_FIELD');
        $this->assertTrue($tag->present);
        $this->assertNull($tag->mapsTo);
        $this->assertNull($tag->legend);
        $this->assertNull($tag->predicate);
    }

    public function testMapsToParam(): void
    {
        $tag = PedigreeFieldTag::parse('@PEDIGREE_FIELD(mapsTo="gender")');
        $this->assertTrue($tag->present);
        $this->assertSame('gender', $tag->mapsTo);
        $this->assertNull($tag->legend);
    }

    public function testLegendParam(): void
    {
        $tag = PedigreeFieldTag::parse('@PEDIGREE_FIELD(legend="disorders")');
        $this->assertSame('disorders', $tag->legend);
    }

    public function testPredicateParam(): void
    {
        $tag = PedigreeFieldTag::parse('@PEDIGREE_FIELD(predicate="isFetus")');
        $this->assertSame('isFetus', $tag->predicate);
    }

    public function testCombinedMapsToAndPredicateParams(): void
    {
        $tag = PedigreeFieldTag::parse('@PEDIGREE_FIELD(mapsTo="gestationAge",predicate="isFetus")');
        $this->assertSame('gestationAge', $tag->mapsTo);
        $this->assertSame('isFetus', $tag->predicate);
    }

    public function testSingleQuotedParamsAreAccepted(): void
    {
        $tag = PedigreeFieldTag::parse("@PEDIGREE_FIELD(mapsTo='gender')");
        $this->assertSame('gender', $tag->mapsTo);
    }

    public function testTagAmongOtherAnnotationText(): void
    {
        $tag = PedigreeFieldTag::parse('@HIDDEN @PEDIGREE_FIELD(mapsTo="gender") @READONLY');
        $this->assertTrue($tag->present);
        $this->assertSame('gender', $tag->mapsTo);
    }

    public function testDoesNotCollideWithPedigreeStorageTags(): void
    {
        $tag = PedigreeFieldTag::parse('@PEDIGREE_HPO');
        $this->assertFalse($tag->present);
    }
}
