<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TerminologyErrorFormatter.php';

/**
 * Regression test for the reflected-XSS fix in
 * pedigree_editor_format_browser_error_message() (see pedigree-editor-trunk-
 * based-workflow-migration task 3.6) - request-derived content must be
 * HTML-escaped before being echoed back to Accept: text/html clients.
 */
class TerminologyErrorFormatterTest extends TestCase
{
    public function testRequestDerivedContentIsHtmlEscaped(): void
    {
        $message = pedigree_editor_format_browser_error_message(
            'Invalid Request',
            'Missing required parameter "type". Params = {"xss":"<script>alert(1)<\/script>"}'
        );

        $this->assertStringNotContainsString('<script>alert(1)</script>', $message);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;', $message);
    }

    public function testPlainMessagesAreUnaffected(): void
    {
        $message = pedigree_editor_format_browser_error_message('Invalid Method', 'Request method must be GET or POST');

        $this->assertStringContainsString('Invalid Method', $message);
        $this->assertStringContainsString('Request method must be GET or POST', $message);
    }
}
