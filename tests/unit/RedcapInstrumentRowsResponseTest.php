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

    private function read(string $status, array $rows = []): array
    {
        return ['status' => $status, 'rows' => $rows, 'record' => '7'];
    }

    private function assertError(int $status, string $error, array $response): void
    {
        $this->assertSame($status, $response['status']);
        $this->assertSame($error, $response['body']['error']);
        $this->assertNotSame('', $response['body']['error_description']);
    }

    public function testImportAnswersARowThatIsThere(): void
    {
        $read = $this->read(Response::OK, [self::REF => self::ANSWERS, 'record:7/instance:1' => []]);
        $this->assertSame(['status' => 200, 'body' => self::ANSWERS], Response::forImport($read, self::REF, true));
        $this->assertSame(['status' => 200, 'body' => self::ANSWERS], Response::forImport($read, self::REF, false));
    }

    public function testImportAnswersARowWithNothingToImportAsEmpty(): void
    {
        $read = $this->read(Response::OK, [self::REF => []]);
        $this->assertSame(['status' => 200, 'body' => []], Response::forImport($read, self::REF, true));
    }

    public function testImportReportsAMissingRowOnlyWhenAskedTo(): void
    {
        $read = $this->read(Response::OK, ['record:7/instance:1' => self::ANSWERS]);
        $this->assertError(404, 'Not Found', Response::forImport($read, self::REF, true));
        // An editor window from before requireRow: [] as before.
        $this->assertSame(['status' => 200, 'body' => []], Response::forImport($read, self::REF, false));
    }

    public function testImportReportsTheRowsOfARecordThatsGoneAsMissing(): void
    {
        $read = $this->read(Response::RECORD_GONE);
        $this->assertError(404, 'Not Found', Response::forImport($read, self::REF, true));
        $this->assertSame(['status' => 200, 'body' => []], Response::forImport($read, self::REF, false));
    }

    public function testImportAnswersNothingSetUpToImportAsEmpty(): void
    {
        // Not "Not Found": the editor explains this case itself (NOTHING_TO_IMPORT).
        $read = $this->read(Response::NOT_CONFIGURED);
        $this->assertSame(['status' => 200, 'body' => []], Response::forImport($read, self::REF, true));
    }

    public function testImportDoesNotReportADataAccessGroupRefusalAsAMissingRow(): void
    {
        $response = Response::forImport($this->read(Response::NOT_IN_GROUP), self::REF, true);
        $this->assertError(403, 'Not Available', $response);
        $this->assertStringContainsString('Data Access Group', $response['body']['error_description']);
        $this->assertError(403, 'Not Available', Response::forImport($this->read(Response::NOT_IN_GROUP), self::REF, false));
    }

    public function testImportDoesNotReportAFailedReadAsAMissingRow(): void
    {
        $this->assertError(500, 'Server Error', Response::forImport($this->read(Response::READ_FAILED), self::REF, true));
        $this->assertError(500, 'Server Error', Response::forImport($this->read(Response::READ_FAILED), self::REF, false));
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
        $this->assertError(400, 'Not Configured', Response::forImportRows($this->read(Response::NOT_CONFIGURED)));
        foreach ([Response::RECORD_GONE, Response::NOT_IN_GROUP, Response::READ_FAILED] as $status) {
            $this->assertError(500, 'Server Error', Response::forImportRows($this->read($status)));
        }
    }
}
