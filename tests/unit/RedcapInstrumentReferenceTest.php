<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentReference;
use PHPUnit\Framework\TestCase;

class RedcapInstrumentReferenceTest extends TestCase
{
    public function testEncodeAndDecodeRoundTrip(): void
    {
        $ref = RedcapInstrumentReference::encode('5', 2);
        $this->assertSame('record:5/instance:2', $ref);
        $this->assertSame(['record' => '5', 'instance' => 2], RedcapInstrumentReference::decode($ref));
    }

    public function testDoesNotStartWithPatientPrefix(): void
    {
        $ref = RedcapInstrumentReference::encode('5', 2);
        $this->assertFalse(str_starts_with($ref, 'Patient/'));
    }

    public function testDecodeRejectsMalformedReference(): void
    {
        $this->assertNull(RedcapInstrumentReference::decode('Patient/123'));
        $this->assertNull(RedcapInstrumentReference::decode('garbage'));
        $this->assertNull(RedcapInstrumentReference::decode(null));
    }

    public function testIsRedcapReference(): void
    {
        $this->assertTrue(RedcapInstrumentReference::isRedcapReference('record:5/instance:2'));
        $this->assertFalse(RedcapInstrumentReference::isRedcapReference('Patient/123'));
        $this->assertFalse(RedcapInstrumentReference::isRedcapReference(null));
    }
}
