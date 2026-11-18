<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../JsonErrorBody.php';

/**
 * The endpoints' JSON error bodies escape HTML-significant characters, since
 * their descriptions can echo request parameters (Psalm 7's TaintedHtml /
 * TaintedTextWithQuotes findings on the error responses).
 */
class JsonErrorBodyTest extends TestCase
{
    public function testHtmlSignificantCharactersAreEscaped(): void
    {
        $body = pedigree_editor_json_error_body([
            'error' => 'Invalid Request',
            'error_description' => "Params = <script>alert(\"x\" & 'y')</script>",
        ]);
        foreach (['<', '>', '&', "'"] as $raw) {
            $this->assertStringNotContainsString($raw, $body, "$raw left unescaped");
        }
        // (a double quote only as JSON's own string delimiters, never inside the description)
        $this->assertStringNotContainsString('\\"', $body);
        $this->assertStringContainsString('\\u003Cscript\\u003E', $body);
        $this->assertStringContainsString('\\u0022x\\u0022', $body);
        $this->assertStringContainsString('\\u0026', $body);
        $this->assertStringContainsString('\\u0027y\\u0027', $body);
    }

    public function testTheBodyIsStillTheSameJson(): void
    {
        $data = ['error' => 'Invalid Request', 'error_description' => 'a/b <c> "d"'];
        $this->assertSame($data, json_decode(pedigree_editor_json_error_body($data), true));
    }
}
