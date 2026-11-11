<?php
/**
 * Guest prompt: what a guest hears when they try something their account does
 * not allow (an outside call with outside calls off, any emergency number, ...)
 * — e.g. "Your account doesn't allow this feature at this time. Contact support
 * to request it or upgrade your plan." One upload for every blocked guest
 * feature; without one, the generic not-in-service announcement plays.
 *
 * Same upload/convert/stream code as the blocked call prompt; only the
 * setting, the file and the dialplan that plays it ([sub-guest-gate] in the
 * outbound dialplan) differ.
 */
require_once __DIR__ . '/BlockedCallPromptService.php';

class GuestPromptService extends BlockedCallPromptService
{
    public const SETTING_KEY = 'guest_block_prompt';
    public const FILE_NAME = 'guest-block-prompt.wav';
    public const SOUND_NAME = 'custom/guest-block-prompt';
    public const SYNC_DOMAIN = 'outbound_dialplan';
    public const LABEL = 'Guest prompt';
    public const MSG_PREFIX = 'guest_prompt';

    /** The sound the dialplan plays: the uploaded prompt, else the generic announcement. */
    public static function soundForDialplan(): string
    {
        $sound = preg_replace('/[^a-zA-Z0-9\/_-]/', '', trim((string) getSystemSetting(self::SETTING_KEY, '')));
        return $sound !== '' ? (string) $sound : 'ss-noservice';
    }
}
