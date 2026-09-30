<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\PedigreeBundleReader;
use PHPUnit\Framework\TestCase;

class PedigreeBundleReaderTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../fixtures/' . $name);
    }

    public function testReadsTheExampleProjectsCompressedPedigree(): void
    {
        $pedigree = PedigreeBundleReader::read(self::fixture('example-record-1.gz.txt'));

        $this->assertTrue($pedigree['ok']);
        $this->assertNull($pedigree['reason']);
        $this->assertCount(9, $pedigree['people']);
        $this->assertSame('record:1/instance:1', $pedigree['people'][$pedigree['probandId']]['ref']);
        $this->assertSame('female', $pedigree['people'][$pedigree['probandId']]['gender']);
        $this->assertCount(13, $pedigree['relationships']);
    }

    public function testReadsTheExampleProjectsPlainPedigreeWithAnUnlinkedPerson(): void
    {
        $pedigree = PedigreeBundleReader::read(self::fixture('example-record-3.json'));

        $this->assertTrue($pedigree['ok']);
        $refs = array_column($pedigree['people'], 'ref');
        $this->assertSame(['record:3/instance:1', 'record:3/instance:3', 'record:3/instance:2', null], $refs);
        $this->assertCount(5, $pedigree['relationships']);
    }

    public function testARelationshipSaysWhatTheRelativeIsToThePerson(): void
    {
        $pedigree = PedigreeBundleReader::read(self::fixture('example-record-3.json'));
        $refOf = function ($key) use ($pedigree) {
            return $pedigree['people'][$key]['ref'];
        };
        $mother = null;
        foreach ($pedigree['relationships'] as $relationship) {
            if ($relationship['code'] === 'KIN:027' && $refOf($relationship['person']) === 'record:3/instance:1') {
                $mother = $refOf($relationship['relative']);
            }
        }
        $this->assertSame('record:3/instance:2', $mother);
    }

    public function testEmptyValuesAreFailures(): void
    {
        foreach ([null, '', "  \n"] as $value) {
            $pedigree = PedigreeBundleReader::read($value);
            $this->assertFalse($pedigree['ok']);
            $this->assertSame('the pedigree is empty', $pedigree['reason']);
            $this->assertSame([], $pedigree['people']);
            $this->assertSame([], $pedigree['relationships']);
        }
    }

    public function testCorruptCompressedDataIsAFailure(): void
    {
        $truncated = substr(self::fixture('example-record-1.gz.txt'), 0, 400);
        foreach (['GZ:not base64 at all!', 'GZ:' . base64_encode('not gzip'), $truncated] as $value) {
            $pedigree = PedigreeBundleReader::read($value);
            $this->assertFalse($pedigree['ok'], $value);
            $this->assertSame('the compressed pedigree could not be decompressed', $pedigree['reason']);
        }
    }

    public function testCompressedDataThatIsntABundleIsAFailure(): void
    {
        $pedigree = PedigreeBundleReader::read('GZ:' . base64_encode(gzencode('{"resourceType": "Patient"}')));
        $this->assertFalse($pedigree['ok']);
        $this->assertStringContainsString('GA4GH', $pedigree['reason']);
    }

    public function testOtherStorageFormatsAreFailures(): void
    {
        $ped = "FAM1 1 0 0 2 2\nFAM1 2 0 0 1 1\n";
        $internal = '{"GG": [], "ranks": [], "order": []}';
        $pedx = '<pedigree><ped>FAM1 1 0 0 2 2</ped></pedigree>';
        foreach ([$ped, $internal, $pedx, '{"resourceType": "Bundle"}'] as $value) {
            $pedigree = PedigreeBundleReader::read($value);
            $this->assertFalse($pedigree['ok'], $value);
            $this->assertStringContainsString('GA4GH storage format', $pedigree['reason']);
        }
    }

    public function testABundleWithoutACompositionIsAFailure(): void
    {
        $pedigree = PedigreeBundleReader::read(json_encode(['resourceType' => 'Bundle', 'entry' => []]));
        $this->assertFalse($pedigree['ok']);
        $this->assertStringContainsString('Composition', $pedigree['reason']);
    }

    public function testAnUnresolvableProbandLeavesTheRestReadable(): void
    {
        $bundle = json_decode(self::fixture('example-record-3.json'), true);
        $bundle['entry'][0]['resource']['subject']['reference'] = 'urn:uuid:nobody';
        $pedigree = PedigreeBundleReader::read(json_encode($bundle));

        $this->assertTrue($pedigree['ok']);
        $this->assertNull($pedigree['probandId']);
        $this->assertCount(5, $pedigree['relationships']);
    }

    public function testRelationshipsToUnknownPeopleOrWithoutAKinCodeAreSkipped(): void
    {
        $bundle = [
            'resourceType' => 'Bundle',
            'entry' => [
                ['resource' => ['resourceType' => 'Composition', 'subject' => ['reference' => 'Patient/a']]],
                ['resource' => ['resourceType' => 'Patient', 'id' => 'a', 'gender' => 'male']],
                ['resource' => ['resourceType' => 'Patient', 'id' => 'b']],
                self::relationship('Patient/a', 'Patient/b', 'KIN:028'),
                self::relationship('Patient/a', 'Patient/missing', 'KIN:027'),
                self::relationship('Patient/a', 'Patient/a', 'KIN:026'),
                self::relationship('Patient/a', 'Patient/b', null),
                ['resource' => ['resourceType' => 'FamilyMemberHistory', 'patient' => ['reference' => 'Patient/a']]],
            ],
        ];
        $pedigree = PedigreeBundleReader::read(json_encode($bundle));

        $this->assertTrue($pedigree['ok']);
        $this->assertSame('Patient/a', $pedigree['probandId']);
        $this->assertSame([['person' => 'Patient/a', 'relative' => 'Patient/b', 'code' => 'KIN:028']], $pedigree['relationships']);
        $this->assertSame(['ref' => null, 'gender' => null], $pedigree['people']['Patient/b']);
    }

    private static function relationship(string $person, string $relative, ?string $code): array
    {
        return ['resource' => [
            'resourceType' => 'FamilyMemberHistory',
            'extension' => [['url' => PedigreeBundleReader::RELATIVE_EXTENSION_URL, 'valueReference' => ['reference' => $relative]]],
            'patient' => ['reference' => $person],
            'relationship' => ['coding' => $code === null ? [] : [['system' => PedigreeBundleReader::KIN_SYSTEM, 'code' => $code]]],
        ]];
    }
}
