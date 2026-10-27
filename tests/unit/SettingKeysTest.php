<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RelationshipSettings;
use PHPUnit\Framework\TestCase;

/**
 * Every module setting the PHP reads must be one config.json defines. A misspelt key reads as
 * null with no error, which is how the project-level Allow manual entry setting went unread
 * (project__allow_edit for project_allow_edit) from v0.4 to 1.0.0.
 *
 * Found by scanning the source for quoted keys passed to get(Project|System)Setting, and for
 * quoted keys of the `$settings` array validateSettings() receives. Keys built at run time
 * (e.g. 'system_' . $setting) or read through a variable can't be seen this way; the
 * relationship settings, read through RelationshipSettings constants, are checked separately.
 */
class SettingKeysTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string, true> */
    private static function configKeys(): array
    {
        $config = json_decode((string) file_get_contents(self::ROOT . '/config.json'), true);
        self::assertIsArray($config, 'config.json is not valid JSON: ' . json_last_error_msg());
        $keys = [];
        foreach (['system-settings', 'project-settings'] as $section) {
            self::assertArrayHasKey($section, $config);
            foreach ($config[$section] as $setting) {
                self::assertArrayHasKey('key', $setting, "a $section entry has no key");
                $keys[$setting['key']] = true;
                foreach ($setting['sub_settings'] ?? [] as $sub) {
                    $keys[$sub['key']] = true;
                }
            }
        }
        return $keys;
    }

    /** @return array<string, list<string>> key => the files that read it */
    private static function keysReadByLiteral(): array
    {
        $patterns = [
            '/get(?:Project|System)Setting(?:AsArray)?\(\s*([\'"])([a-z0-9_]+)\1/i',
            '/\$settings\[\s*([\'"])([a-z0-9_]+)\1\s*\]/i',
        ];
        $files = array_merge(glob(self::ROOT . '/*.php') ?: [], glob(self::ROOT . '/classes/*.php') ?: []);
        $read = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach ($matches[2] as $key) {
                    $read[$key][basename($file)] = true;
                }
            }
        }
        return array_map('array_keys', $read);
    }

    public function testFindsTheSettingsThePhpReads(): void
    {
        // A guard against the patterns silently matching nothing.
        $read = self::keysReadByLiteral();
        $this->assertArrayHasKey('project_format', $read);
        $this->assertArrayHasKey('system_allow_edit', $read);
        $this->assertArrayHasKey('project_def_terminology', $read); // read from $settings[...]
    }

    public function testEverySettingReadByLiteralIsInConfigJson(): void
    {
        $missing = array_diff_key(self::keysReadByLiteral(), self::configKeys());
        $this->assertSame([], $missing, 'Settings read but not defined in config.json (key => files): ' . json_encode($missing));
    }

    public function testEveryRelationshipSettingIsInConfigJson(): void
    {
        $keys = array_merge([RelationshipSettings::PEOPLE_INSTRUMENT], RelationshipSettings::KEYS);
        $this->assertSame([], array_values(array_diff($keys, array_keys(self::configKeys()))));
    }
}
