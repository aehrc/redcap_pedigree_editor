<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\FhirAuthToken;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/FhirAuthToken.php';

/** The terminology server's token goes into an Authorization header: what may go there. */
class FhirAuthTokenTest extends TestCase
{
    public function testBearerTokenAcceptsTokensThatCanGoInAHeader(): void
    {
        // (RFC 6750 b64tokens, and real-world ones outside it - e.g. Salesforce's contain !)
        foreach (['tok-1', 'abc.def.ghi', 'eyJhbGciOiJIUzI1NiJ9.e30.sig_-~+/', 'YWJj==', 'A',
                  '00Dxx0000001gPL!AQ4AQFO.x', 'tok;x=1', 'a#b|c^d'] as $token) {
            $this->assertSame($token, FhirAuthToken::bearerToken($token), $token);
        }
    }

    public function testBearerTokenRefusesAnythingThatCouldInjectAHeader(): void
    {
        foreach (["tok\r\nX-Injected: 1", "tok\n", "tok\r", "tok\t1", 'tok with space', "tok\x7f", "t\u{00F6}k", '', null, 4.2, true, ['tok']] as $value) {
            $this->assertNull(FhirAuthToken::bearerToken($value), var_export($value, true));
        }
    }

    public function testBearerTokenAcceptsAJsonNumberTokenAsItsDigits(): void
    {
        $this->assertSame('123456789', FhirAuthToken::bearerToken(123456789));
    }

    // --- isRedirectResponse(): the file_get_contents fallback doesn't follow redirects ---

    public function testIsRedirectResponse(): void
    {
        foreach (['HTTP/1.1 302 Found', 'HTTP/1.0 301 Moved Permanently', 'HTTP/2 307'] as $status) {
            $this->assertTrue(FhirAuthToken::isRedirectResponse([$status, 'Location: https://elsewhere.test/']), $status);
        }
        foreach ([['HTTP/1.1 200 OK'], ['HTTP/1.1 404 Not Found'], ['HTTP/1.1 3000 Odd'], [], ['Location: x']] as $headers) {
            $this->assertFalse(FhirAuthToken::isRedirectResponse($headers), var_export($headers, true));
        }
    }
}
