<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_helper.php';

/** What counts as a failed `asterisk -rx` and what is only noise beside a command that worked. */
final class AsteriskCliOutputTest extends TestCase
{
    public function testTheWorkingDirectoryWarningIsNotAFailure(): void
    {
        // Printed when the calling process sits in a directory its user cannot read (cron
        // from /root). The reload happened; treating it as a failure rolled back good
        // configuration files and left an install without inbound calls for two days.
        $out = "Unable to access the running directory (Permission denied).  Changing to '/' for compatibility.\nDialplan reloaded.";
        $this->assertFalse(AsteriskHelper::looksLikeCliFailure($out));
        $this->assertFalse(AsteriskHelper::looksLikeCliFailure("Dialplan reloaded."));
        $this->assertFalse(AsteriskHelper::looksLikeCliFailure(''));
    }

    public function testRealFailuresAreStillCaught(): void
    {
        $this->assertTrue(AsteriskHelper::looksLikeCliFailure('No such command "dialplan relaod"'));
        $this->assertTrue(AsteriskHelper::looksLikeCliFailure("Unable to connect to remote asterisk"));
        $this->assertTrue(AsteriskHelper::looksLikeCliFailure("Unable to access the running directory (Permission denied).\nError: parse error in extensions.conf"));
    }
}
