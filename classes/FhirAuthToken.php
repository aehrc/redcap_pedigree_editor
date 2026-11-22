<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * The FHIR terminology server's client-credentials token, checked before it goes into the
 * "Authorization: Bearer" header of every request (same check as the FHIR ontology modules'
 * FhirRequestPolicy::bearerToken).
 */
class FhirAuthToken
{
    /**
     * Whether a stream-wrapper response (its $http_response_header lines) is a redirect - which
     * the file_get_contents fallback doesn't follow, since the Authorization header would go with it.
     *
     * @param array<int, string> $responseHeaders
     */
    public static function isRedirectResponse(array $responseHeaders): bool
    {
        return isset($responseHeaders[0]) && preg_match('#^HTTP/\S+\s+3\d\d\b#', (string) $responseHeaders[0]) === 1;
    }

    /**
     * The access token a token endpoint returned, if it can be sent in an Authorization header,
     * else null: printable ASCII with no spaces or control characters, so above all no CR/LF to
     * inject headers into every FHIR request. (Looser than RFC 6750's b64token on purpose: real
     * servers issue tokens with ! # | and the like, which can't inject anything. \z, not $: $
     * also matches before a trailing newline.)
     *
     * @param mixed $value
     * @psalm-taint-escape header
     */
    public static function bearerToken($value): ?string
    {
        // (a JSON-number token - unusual, but it worked before this check - is its digits)
        if (is_int($value)) {
            $value = (string) $value;
        }
        return (is_string($value) && preg_match('/^[\x21-\x7E]+\z/', $value) === 1) ? $value : null;
    }
}
