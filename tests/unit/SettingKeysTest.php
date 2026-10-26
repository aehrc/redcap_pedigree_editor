<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RelationshipSettings;
use PHPUnit\Framework\TestCase;

/**
 * Every module setting the PHP reads must be one config.json defines. A misspelt key reads as
 * null with no error, which is how the project-level Allow manual entry setting went unread
 * (project__allow_edit for project_allow_edit) from v0.4 to 1.0.0.
 */
class SettingKeysTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string, true> */
    private static function configKeys(): array
    {
        $config = json_decode((string) file_get_contents(self::ROOT . '/config.json'), true);
        $keys = [];
        foreach (['system-settings', 'project-settings'] as $section) {
            foreach ($config[$section] as $setting) {
                $keys[$setting['key']] = true;
                foreach ($setting['sub_settings'] ?? [] as $sub) {
                    $keys[$sub['key']] = true;
                }
            }
        }
        return $keys;
    }

    /** @return array<string, string> key => where it's read */
    private static function keysReadByLiteral(): array
    {
        $files = array_merge(glob(self::ROOT . '/*.php') ?: [], glob(self::ROOT . '/classes/*.php') ?: []);
        $read = [];
        foreach ($files as $file) {
            preg_match_all("/get(?:Project|System)Setting(?:AsArray)?\\(\\s*'([^']+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                $read[$key] = basename($file);
            }
        }
        return $read;
    }

    public function testFindsTheSettingsThePhpReads(): void
    {
        // A guard against the pattern silently matching nothing.
        $read = self::keysReadByLiteral();
        $this->assertArrayHasKey('project_format', $read);
        $this->assertArrayHasKey('system_allow_edit', $read);
    }

    public function testEverySettingReadByLiteralIsInConfigJson(): void
    {
        $missing = array_diff_key(self::keysReadByLiteral(), self::configKeys());
        $this->assertSame([], $missing, 'Settings read but not defined in config.json (key => file): ' . json_encode($missing));
    }

    public function testEveryRelationshipSettingIsInConfigJson(): void
    {
        $keys = array_merge([RelationshipSettings::PEOPLE_INSTRUMENT], RelationshipSettings::KEYS);
        $this->assertSame([], array_values(array_diff($keys, array_keys(self::configKeys()))));
    }
}
