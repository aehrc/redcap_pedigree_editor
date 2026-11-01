<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentRowsResponse as Response;
use PHPUnit\Framework\TestCase;

/**
 * Design D2's table: each read status, mapped for type=import (with and without requireRow, the
 * row there or not) and for type=import-rows.
 */
class RedcapInstrumentRowsResponseTest extends TestCase
{
    const REF = 'record:7/instance:2';
    const ANSWERS = [['linkId' => 'first_name', 'value' => 'Priya']];

    private function read(string $status, array $rows = [], ?string $record = '7'): array
    {
        return ['status' => $status, 'rows' => $rows, 'record' => $record, 'reason' => null];
    }

    // forImport for record 7, instance 2 (self::REF).
    private function import(array $read, bool $requireRow): array
    {
        return Response::forImport($read, '7', 2, $requireRow);
    }

    private function assertError(int $status, string $error, array $response, ?bool $log = null): void
    {
        $this->assertSame($status, $response['status']);
        if ($log !== null) {
            $this->assertSame($log, $response['log'], 'whether the reason is logged');
        }
        $this->assertSame($error, $response['body']['error']);
        $this->assertNotSame('', $response['body']['error_description']);
    }

    public function testImportAnswersARowThatIsThere(): void
    {
        $read = $this->read(Response::OK, [self::REF => self::ANSWERS, 'record:7/instance:1' => []]);
        $this->assertSame(['status' => 200, 'body' => self::ANSWERS, 'log' => false], $this->import($read, true));
        $this->assertSame(['status' => 200, 'body' => self::ANSWERS, 'log' => false], $this->import($read, false));
    }

    public function testImportAnswersARowWithNothingToImportAsEmpty(): void
    {
        $read = $this->read(Response::OK, [self::REF => []]);
        $this->assertSame(['status' => 200, 'body' => [], 'log' => false], $this->import($read, true));
    }

    public function testImportReportsAMissingRowOnlyWhenAskedTo(): void
    {
        $read = $this->read(Response::OK, ['record:7/instance:1' => self::ANSWERS]);
        $this->assertError(404, 'Not Found', $this->import($read, true), false);
        // An editor window from before requireRow: [] as before.
        $this->assertSame(['status' => 200, 'body' => [], 'log' => false], $this->import($read, false));
    }

    public function testTheRowIsLookedUpUnderTheStoredRecordName(): void
    {
        // Asked as 'abc', stored as 'ABC': the read keys rows by the stored name.
        $read = $this->read(Response::OK, ['record:ABC/instance:2' => self::ANSWERS], 'ABC');
        $this->assertSame(['status' => 200, 'body' => self::ANSWERS, 'log' => false], Response::forImport($read, 'abc', 2, true));
    }

    public function testImportReportsTheRowsOfARecordThatsGoneAsMissing(): void
    {
        $read = $this->read(Response::RECORD_GONE, [], null);
        $this->assertError(404, 'Not Found', $this->import($read, true));
        $this->assertSame(['status' => 200, 'body' => [], 'log' => false], $this->import($read, false));
    }

    public function testImportDoesNotAnswerRowsThatCantBeReadAsEmpty(): void
    {
        // [] with requireRow means the row exists; with no instrument to read, that isn't known.
        $read = $this->read(Response::NOT_CONFIGURED, [], null);
        $this->assertError(400, 'Not Configured', $this->import($read, true), false);
        $this->assertError(400, 'Not Configured', $this->import($read, false));
    }

    public function testImportDoesNotReportADataAccessGroupRefusalAsAMissingRow(): void
    {
        $response = $this->import($this->read(Response::NOT_IN_GROUP), true);
        $this->assertError(403, 'Not Available', $response, true);
        $this->assertStringContainsString('Data Access Group', $response['body']['error_description']);
        $this->assertError(403, 'Not Available', $this->import($this->read(Response::NOT_IN_GROUP), false));
    }

    public function testImportDoesNotReportAFailedReadAsAMissingRow(): void
    {
        $this->assertError(500, 'Server Error', $this->import($this->read(Response::READ_FAILED), true), true);
        $this->assertError(500, 'Server Error', $this->import($this->read(Response::READ_FAILED), false));
    }

    public function testImportRowsAnswersEveryRowAsAnObject(): void
    {
        $rows = [self::REF => self::ANSWERS];
        $response = Response::forImportRows($this->read(Response::OK, $rows));
        $this->assertSame(200, $response['status']);
        $this->assertEquals((object) $rows, $response['body']);
        // No rows is still an object: {} in JSON, not [].
        $this->assertSame('{}', json_encode(Response::forImportRows($this->read(Response::OK))['body']));
    }

    public function testImportRowsAnswersEverythingElseAsAnError(): void
    {
        $this->assertError(400, 'Not Configured', Response::forImportRows($this->read(Response::NOT_CONFIGURED)), false);
        foreach ([Response::RECORD_GONE, Response::NOT_IN_GROUP, Response::READ_FAILED] as $status) {
            $this->assertError(500, 'Server Error', Response::forImportRows($this->read($status)), true);
        }
    }

    public function testImportDoesNotReadAnUnknownStatusAsAMissingRow(): void
    {
        $this->assertError(500, 'Server Error', $this->import($this->read('somethingNew'), true), true);
        $this->assertError(500, 'Server Error', $this->import($this->read('somethingNew'), false), true);
    }
}
