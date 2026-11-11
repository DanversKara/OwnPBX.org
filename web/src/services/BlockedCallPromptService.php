<?php
/**
 * Blocked Call Prompt Service.
 *
 * What a caller hears when an outbound call is blocked (permission denied —
 * e.g. no outside minutes left on their plan). A custom prompt replaces the
 * generic "number not in service" announcement; uploading a new one replaces
 * the old one. The prompt is stored as 8 kHz mono 16-bit WAV, like IVR
 * greetings. MP3 needs the 'sox' tool on the server; without it, upload a
 * WAV that is already 8 kHz, mono, 16-bit.
 *
 * This changes ONLY what a blocked caller hears. Who is blocked is decided
 * by the permission rules / minutes engine and is untouched here.
 *
 * The dialplan reads the prompt from the `blocked_call_prompt` system
 * setting (see SyncPermissions: sub-permission-denied). Empty = no custom
 * prompt, play the generic ss-noservice announcement.
 */
class BlockedCallPromptService
{
    /** System setting holding the Asterisk sound name, e.g. 'custom/blocked-call-prompt'. */
    public const SETTING_KEY = 'blocked_call_prompt';

    /** Fixed file name in the custom sounds dir. Uploading replaces it. */
    public const FILE_NAME = 'blocked-call-prompt.wav';

    /** Asterisk sound name for the file (what Playback() uses). */
    public const SOUND_NAME = 'custom/blocked-call-prompt';

    public const MAX_SECONDS = 60;
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * The current custom prompt, or null when none is set.
     * @return array{file: string, sound: string}|null
     */
    public static function current(): ?array
    {
        $sound = trim((string) getSystemSetting(self::SETTING_KEY, ''));
        if ($sound === '') return null;
        $path = SOUNDS_CUSTOM_DIR . '/' . self::FILE_NAME;
        if (!is_file($path)) return null;
        return ['file' => self::FILE_NAME, 'sound' => $sound];
    }

    /** Is the 'sox' converter available on this server? */
    public static function soxAvailable(): bool
    {
        $which = trim((string) shell_exec('command -v sox 2>/dev/null'));
        return $which !== '';
    }

    /**
     * Parse a WAV header. Returns [format, channels, sampleRate, bitsPerSample,
     * byteRate, dataBytes] or null when the file is not a readable PCM WAV.
     */
    public static function parseWavHeader(string $path): ?array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) return null;
        $head = fread($fh, 2048);
        fclose($fh);
        if (strlen($head) < 44 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') return null;

        $pos = 12;
        $fmt = null;
        $dataBytes = null;
        $len = strlen($head);
        while ($pos + 8 <= $len) {
            $id = substr($head, $pos, 4);
            $size = unpack('V', substr($head, $pos + 4, 4))[1];
            if ($id === 'fmt ' && $size >= 16 && $pos + 8 + 16 <= $len) {
                $f = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', substr($head, $pos + 8, 16));
                $fmt = $f;
            } elseif ($id === 'data') {
                $dataBytes = $size;
                break;
            }
            $pos += 8 + $size + ($size % 2);
        }
        if ($fmt === null || $dataBytes === null) return null;
        return [
            'format' => (int) $fmt['format'],
            'channels' => (int) $fmt['channels'],
            'sampleRate' => (int) $fmt['sampleRate'],
            'bitsPerSample' => (int) $fmt['bits'],
            'byteRate' => (int) $fmt['byteRate'],
            'dataBytes' => (int) $dataBytes,
        ];
    }

    /** Is this WAV already in the Asterisk format (8 kHz, mono, 16-bit PCM)? */
    public static function isAsteriskWav(string $path): bool
    {
        $h = self::parseWavHeader($path);
        return $h !== null
            && $h['format'] === 1
            && $h['channels'] === 1
            && $h['sampleRate'] === 8000
            && $h['bitsPerSample'] === 16;
    }

    /** Duration in seconds of a WAV file, from its header. Null when unreadable. */
    public static function wavDuration(string $path): ?float
    {
        $h = self::parseWavHeader($path);
        if ($h === null || $h['byteRate'] <= 0) return null;
        return $h['dataBytes'] / $h['byteRate'];
    }

    /**
     * Upload a prompt (WAV or MP3, max 60 seconds). Replaces the old one.
     * Returns a success message for the page notice.
     */
    public static function upload($data, $files): string
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data, $files) {
            if (!isset($files['prompt_file']) || $files['prompt_file']['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception(t('blocked_prompt.err_file'));
            }
            $tmp = $files['prompt_file']['tmp_name'];
            $orig = (string) ($files['prompt_file']['name'] ?? '');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['wav', 'mp3'], true)) {
                throw new \Exception(t('blocked_prompt.err_type'));
            }
            if (filesize($tmp) > self::MAX_BYTES) {
                throw new \Exception(t('blocked_prompt.err_size'));
            }

            $target = SOUNDS_CUSTOM_DIR . '/' . self::FILE_NAME;
            $raw = SOUNDS_CUSTOM_DIR . '/.raw_blocked_' . uniqid();
            if (!move_uploaded_file($tmp, $raw)) {
                throw new \Exception(t('blocked_prompt.err_upload'));
            }
            try {
                if (self::soxAvailable()) {
                    SoundService::convertToAsteriskWav($raw, $target);
                } else {
                    // No sox: only a WAV that is already 8 kHz, mono, 16-bit
                    // can be used as-is. MP3 (or a WAV in another format)
                    // cannot be converted here.
                    if ($ext !== 'wav' || !self::isAsteriskWav($raw)) {
                        throw new \Exception(t('blocked_prompt.err_sox'));
                    }
                    if (!copy($raw, $target)) {
                        throw new \Exception(t('blocked_prompt.err_upload'));
                    }
                }
            } finally {
                @unlink($raw);
            }

            $dur = self::wavDuration($target);
            if ($dur === null || $dur <= 0 || $dur > self::MAX_SECONDS) {
                @unlink($target);
                throw new \Exception(t('blocked_prompt.err_length'));
            }

            @chown($target, 'asterisk');
            @chgrp($target, 'asterisk');
            @chmod($target, 0664);

            setSystemSetting(self::SETTING_KEY, self::SOUND_NAME);
            // The permissions dialplan reads this setting when it is
            // generated, so regenerate it now.
            markPendingSync('permissions', 'blocked_call_prompt', self::FILE_NAME, 'Blocked call prompt', 'update', $_SESSION['user_id'] ?? null);

            return t('blocked_prompt.uploaded');
        });
    }

    /** Remove the custom prompt; blocked callers hear the generic announcement again. */
    public static function delete(): string
    {
        return PBXHelper::handleAction($_POST['csrf_token'] ?? '', function() {
            $target = SOUNDS_CUSTOM_DIR . '/' . self::FILE_NAME;
            @unlink($target);
            setSystemSetting(self::SETTING_KEY, '');
            markPendingSync('permissions', 'blocked_call_prompt', self::FILE_NAME, 'Blocked call prompt', 'delete', $_SESSION['user_id'] ?? null);
            return t('blocked_prompt.removed');
        });
    }

    /** Stream the current prompt file to the browser (admin-only page, for Listen). */
    public static function stream(): void
    {
        $current = self::current();
        if ($current === null) {
            http_response_code(404);
            exit;
        }
        $path = SOUNDS_CUSTOM_DIR . '/' . self::FILE_NAME;
        header('Content-Type: audio/wav');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . self::FILE_NAME . '"');
        readfile($path);
        exit;
    }
}
