<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Reads the people and direct relationships out of a saved `@PEDIGREE`
 * value, for relationship topology sync (pedigree-editor-relationship-
 * topology-sync D2). Pure: no REDCap access.
 *
 * Only the GA4GH Bundle open-pedigree saves is understood - the other
 * storage formats don't carry which person is linked to which row. A GA4GH
 * Bundle has:
 * - a Composition whose `subject` is the proband;
 * - a Patient per person, with its link to a People row as the
 *   `linked-record-ref` extension (`record:<r>/instance:<n>`);
 * - a `PedigreeRelationship` FamilyMemberHistory per direct relationship:
 *   `patient` is the person, the `familymemberhistory-patient-record`
 *   extension their relative, and the KIN code what the relative is to the
 *   person (so KIN:027 on child -> mother means "mother is the child's
 *   biological mother"). Partners and twins are written once per pair.
 */
class PedigreeBundleReader
{
    const KIN_SYSTEM = 'http://purl.org/ga4gh/kin.fhir';
    const RELATIVE_EXTENSION_URL = 'http://hl7.org/fhir/StructureDefinition/familymemberhistory-patient-record';
    const LINK_EXTENSION_URL = 'https://github.com/aehrc/open-pedigree/StructureDefinition/linked-record-ref';
    const RELATIONSHIP_PROFILE = 'http://purl.org/ga4gh/pedigree-fhir-ig/StructureDefinition/PedigreeRelationship';
    const COMPRESSED_PREFIX = 'GZ:';
    /** The failure reason for a field with nothing in it (no pedigree drawn yet). */
    const EMPTY = 'the pedigree is empty';

    /**
     * @param string|null $value The field's saved value, plain or `GZ:`
     *   (gzip, then base64) as `pedigreeEditorEM.save()` writes it.
     * @return array{ok: bool, reason: string|null, probandId: string|null,
     *   people: array<string, array{ref: string|null, gender: string|null}>,
     *   relationships: array<int, array{person: string, relative: string, code: string}>}
     *   On failure `ok` is false, `reason` says why and the lists are empty.
     *   People are keyed by their Bundle `fullUrl` (or `Patient/<id>`), in
     *   Bundle order; `gender` is the FHIR gender. Relationships keep every
     *   KIN code, known or not, and only those between two of the people.
     */
    public static function read(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return self::failure(self::EMPTY);
        }
        $json = $value;
        if (strpos($value, self::COMPRESSED_PREFIX) === 0) {
            $decoded = base64_decode(substr($value, strlen(self::COMPRESSED_PREFIX)), true);
            $json = $decoded === false ? false : @gzdecode($decoded);
            if ($json === false) {
                return self::failure('the compressed pedigree could not be decompressed');
            }
        }
        $bundle = json_decode($json, true);
        if (!is_array($bundle)) {
            return self::failure('the pedigree is not a GA4GH FHIR Bundle (relationships need the GA4GH storage format)');
        }
        if (($bundle['resourceType'] ?? null) !== 'Bundle' || !is_array($bundle['entry'] ?? null)) {
            return self::failure('the pedigree is not a GA4GH FHIR Bundle (relationships need the GA4GH storage format)');
        }

        $people = [];
        $aliases = []; // every way an entry can be referenced => its people key
        $composition = null;
        $relationshipResources = [];
        foreach ($bundle['entry'] as $entry) {
            $resource = is_array($entry) ? ($entry['resource'] ?? null) : null;
            if (!is_array($resource)) {
                continue;
            }
            switch ($resource['resourceType'] ?? null) {
                case 'Composition':
                    $composition = $composition ?? $resource;
                    break;
                case 'Patient':
                    $key = self::entryKey($entry, $resource);
                    if ($key === null || isset($people[$key])) {
                        break;
                    }
                    $people[$key] = ['ref' => self::linkRef($resource), 'gender' => self::stringOrNull($resource['gender'] ?? null)];
                    foreach (self::referencesTo($entry, $resource) as $alias) {
                        $aliases[$alias] = $key;
                    }
                    break;
                case 'FamilyMemberHistory':
                    if (self::isPedigreeRelationship($resource)) {
                        $relationshipResources[] = $resource;
                    }
                    break;
            }
        }
        if ($composition === null) {
            return self::failure('the pedigree Bundle has no Composition, so its proband is unknown');
        }
        $probandRef = self::stringOrNull($composition['subject']['reference'] ?? null);
        $probandId = $probandRef !== null ? ($aliases[$probandRef] ?? null) : null;

        $relationships = [];
        foreach ($relationshipResources as $resource) {
            $person = $aliases[self::stringOrNull($resource['patient']['reference'] ?? null) ?? ''] ?? null;
            $relative = $aliases[self::relativeRef($resource) ?? ''] ?? null;
            $code = self::kinCode($resource);
            if ($person === null || $relative === null || $code === null || $person === $relative) {
                continue;
            }
            $relationships[] = ['person' => $person, 'relative' => $relative, 'code' => $code];
        }

        return ['ok' => true, 'reason' => null, 'probandId' => $probandId, 'people' => $people, 'relationships' => $relationships];
    }

    private static function failure(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'probandId' => null, 'people' => [], 'relationships' => []];
    }

    private static function entryKey(array $entry, array $resource): ?string
    {
        $refs = self::referencesTo($entry, $resource);
        return $refs ? $refs[0] : null;
    }

    /**
     * open-pedigree references Patients by `fullUrl` (`urn:uuid:...`); a
     * hand-made Bundle might use `Patient/<id>` instead, so both count.
     *
     * @return string[]
     */
    private static function referencesTo(array $entry, array $resource): array
    {
        $refs = [];
        $fullUrl = self::stringOrNull($entry['fullUrl'] ?? null);
        if ($fullUrl !== null) {
            $refs[] = $fullUrl;
        }
        $id = self::stringOrNull($resource['id'] ?? null);
        if ($id !== null) {
            $refs[] = 'Patient/' . $id;
            if (strpos($id, 'urn:') === 0) {
                $refs[] = $id;
            }
        }
        return array_values(array_unique($refs));
    }

    private static function linkRef(array $patient): ?string
    {
        foreach (self::extensions($patient) as $extension) {
            if (($extension['url'] ?? null) === self::LINK_EXTENSION_URL) {
                return self::stringOrNull($extension['valueString'] ?? null);
            }
        }
        return null;
    }

    private static function relativeRef(array $resource): ?string
    {
        foreach (self::extensions($resource) as $extension) {
            if (($extension['url'] ?? null) === self::RELATIVE_EXTENSION_URL) {
                return self::stringOrNull($extension['valueReference']['reference'] ?? null);
            }
        }
        return null;
    }

    /**
     * A relationship between two Patients of the pedigree: profiled as
     * `PedigreeRelationship`, or - when a Bundle leaves the profile out -
     * carrying the relative extension, which only relationships do.
     */
    private static function isPedigreeRelationship(array $resource): bool
    {
        $profiles = $resource['meta']['profile'] ?? [];
        if (is_array($profiles) && in_array(self::RELATIONSHIP_PROFILE, $profiles, true)) {
            return true;
        }
        return self::relativeRef($resource) !== null;
    }

    private static function kinCode(array $resource): ?string
    {
        $codings = $resource['relationship']['coding'] ?? [];
        if (!is_array($codings)) {
            return null;
        }
        foreach ($codings as $coding) {
            if (is_array($coding) && ($coding['system'] ?? null) === self::KIN_SYSTEM) {
                return self::stringOrNull($coding['code'] ?? null);
            }
        }
        return null;
    }

    private static function extensions(array $resource): array
    {
        $extensions = $resource['extension'] ?? [];
        return is_array($extensions) ? array_filter($extensions, 'is_array') : [];
    }

    private static function stringOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
