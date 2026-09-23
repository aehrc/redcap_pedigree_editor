<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapDataEntryUrl;
use PHPUnit\Framework\TestCase;

class RedcapDataEntryUrlTest extends TestCase
{
    public function testBuildsDataEntryUrlWithoutInstance(): void
    {
        $this->assertSame(
            '/redcap/redcap_v16.0.32/DataEntry/index.php?pid=17&page=family_members&id=1&event_id=44',
            RedcapDataEntryUrl::build('/redcap/redcap_v16.0.32/', 17, 'family_members', '1', 44)
        );
    }

    public function testToleratesWebrootWithoutTrailingSlash(): void
    {
        $this->assertStringStartsWith(
            '/redcap/redcap_v16.0.32/DataEntry/index.php?',
            RedcapDataEntryUrl::build('/redcap/redcap_v16.0.32', 17, 'family_members', '1', 44)
        );
    }

    public function testEncodesRecordNames(): void
    {
        // Custom record IDs can contain spaces and other URL-significant characters.
        $url = RedcapDataEntryUrl::build('/redcap/redcap_v16.0.32/', 17, 'family_members', 'smith j&co', 44);
        $this->assertStringContainsString('&id=smith%20j%26co&', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('smith j&co', $query['id']);
    }
}
