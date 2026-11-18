<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/** *86 is a feature code like the others: visible on the page, and its digits can change. */
final class BlockCallerFeatureCodeTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        getDB()->exec("DELETE FROM pbx_feature_codes WHERE feature_key = 'block_caller'");
        getDB()->exec("INSERT INTO pbx_feature_codes (feature_key, title, code, allowed_roles, is_active)
            VALUES ('block_caller', 'Block the caller (press during a call)', '*86', 'admin,user', 1)");
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM pbx_feature_codes WHERE feature_key = 'block_caller'");
    }

    public function testTheApplicationMapComesFromTheFeatureCode(): void
    {
        syncFeatureCodes();
        $map = (string) @file_get_contents(ASTERISK_PBX_DIR . '/features_applicationmap.conf');
        $this->assertStringContainsString('blockcaller => *86,callee,Gosub(sub-block-caller,s,1)', $map);

        // Change the digits on the Feature Codes page and the file follows.
        getDB()->exec("UPDATE pbx_feature_codes SET code = '*77' WHERE feature_key = 'block_caller'");
        syncFeatureCodes();
        $map = (string) @file_get_contents(ASTERISK_PBX_DIR . '/features_applicationmap.conf');
        $this->assertStringContainsString('blockcaller => *77,callee', $map);
        $this->assertStringNotContainsString('*86', $map);

        // Switched off on that page, nothing is armed at all.
        getDB()->exec("UPDATE pbx_feature_codes SET is_active = 0 WHERE feature_key = 'block_caller'");
        syncFeatureCodes();
        $map = (string) @file_get_contents(ASTERISK_PBX_DIR . '/features_applicationmap.conf');
        $this->assertStringNotContainsString('blockcaller =>', $map);
    }

    public function testItIsNotAlsoGeneratedAsADialledCode(): void
    {
        syncFeatureCodes();
        $dialplan = (string) @file_get_contents(ASTERISK_PBX_DIR . '/extensions_featurecodes.conf');
        // Dialling *86 must not reach a dead extension: it is only ever pressed mid-call.
        $this->assertStringNotContainsString('exten => *86,', $dialplan);
    }
}
