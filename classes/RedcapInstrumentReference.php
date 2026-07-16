<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Encodes/decodes the REDCap-shaped link reference used in place of a FHIR
 * `Patient/<id>` reference: an opaque string carrying `record_id` +
 * `redcap_repeat_instance`, parsed only by `RedcapInstrumentPatientProvider`
 * (design D6).
 *
 * Deliberately does not start with `Patient/`, so `GA4GHFHIRConverter.ts`'s
 * existing `linkedPatientRef.startsWith('Patient/')` gate excludes it from
 * GA4GH export (task 6.6).
 */
class RedcapInstrumentReference
{
    const PREFIX = 'record:';
    const INSTANCE_MARKER = '/instance:';

    public static function encode(string $record, $instance): string
    {
        return self::PREFIX . $record . self::INSTANCE_MARKER . $instance;
    }

    /**
     * @return array{record: string, instance: int}|null Null if `$ref` is
     *   not a validly-formatted REDCap instrument reference.
     */
    public static function decode(?string $ref): ?array
    {
        if ($ref === null || !preg_match('/^record:(.+)\/instance:(\d+)$/', $ref, $m)) {
            return null;
        }
        return ['record' => $m[1], 'instance' => (int) $m[2]];
    }

    public static function isRedcapReference(?string $ref): bool
    {
        return $ref !== null && strpos($ref, self::PREFIX) === 0;
    }
}
