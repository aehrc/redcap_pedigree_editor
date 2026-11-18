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
        foreach (["tok\r\nX-Injected: 1", "tok\n", "tok\r", "tok\t1", 'tok with space', "tok\x7f", "t\u{00F6}k", '', null, 42, ['tok']] as $value) {
            $this->assertNull(FhirAuthToken::bearerToken($value), var_export($value, true));
        }
    }
}
