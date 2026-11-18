<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * JSON for the module's endpoints (TerminologyService.php, PedigreeInstrumentService.php, and the
 * module's FHIR proxy). An error description can echo request parameters, and other bodies carry
 * values people typed, so besides being JSON, HTML-significant characters (< > & ' ") are escaped
 * as \u003C-style sequences - the same JSON once decoded, and safe even if a browser renders the
 * body as HTML. Invalid UTF-8 is replaced rather than failing the encoding.
 *
 * The flags are literal in each call so Psalm can see JSON_HEX_TAG | JSON_HEX_AMP remove the html
 * taint itself; it doesn't model the quote flags, hence has_quotes.
 *
 * @param mixed $body
 * @psalm-taint-escape has_quotes
 * @throws \JsonException
 */
function json_for_response($body): string
{
    return json_encode(
        $body,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

/**
 * The JSON body of an endpoint's error response: as json_for_response, pretty-printed, and never
 * throwing (an error response is already being sent).
 *
 * @param array<string, mixed> $body
 * @psalm-taint-escape has_quotes
 */
function json_error_body(array $body): string
{
    return (string) json_encode(
        $body,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}
