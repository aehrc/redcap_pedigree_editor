<?php

/**
 * The JSON body of an endpoint's error response (TerminologyService.php,
 * PedigreeInstrumentService.php). The description can echo request parameters,
 * so besides being JSON, HTML-significant characters (< > & ' ") are escaped as
 * <-style sequences: the body is safe even if a browser renders it as HTML.
 *
 * @param array<string, mixed> $body
 * @psalm-taint-escape html
 * @psalm-taint-escape has_quotes
 */
function pedigree_editor_json_error_body(array $body): string
{
    return (string) json_encode(
        $body,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}
