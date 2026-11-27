<?php
/**
 * VaultSealService — seals the OpenBao vault (PUT /v1/sys/seal).
 *
 * Used only as the tripwire for the admin PIN: three wrong PINs in the
 * failure window seal the vault, so a guessing attacker cannot keep trying
 * and no service can fetch a fresh secret until an admin unseals it again
 * (BaoLock). The token here is a seal-only token — its policy grants
 * nothing but `update` on sys/seal — so even if it leaks, it can only
 * seal, never read a secret or unseal. See docs/openbao-seal-token.md.
 *
 * The token is read from the environment (portalEnv: getenv first, then
 * /etc/ai-pbx.env), never from the database, and never logged. Fail closed
 * is the caller's job: when this returns ok=false, the caller must still
 * lock the session — the seal not being confirmed must never allow more
 * PIN tries.
 */
class VaultSealService
{
    /**
     * Seal the vault. Returns ['ok' => bool, 'error' => string].
     * A 2xx from OpenBao means sealed. Anything else (no token configured,
     * unreachable vault, non-2xx) returns ok=false with the reason.
     */
    public static function seal(): array
    {
        $addr = rtrim((string) portalEnv('OPENBAO_ADDR', 'http://192.168.8.100:8200'), '/');
        $token = (string) portalEnv('OPENBAO_SEAL_TOKEN', '');
        if ($token === '') {
            return ['ok' => false, 'error' => 'OPENBAO_SEAL_TOKEN is not configured'];
        }

        $ch = curl_init($addr . '/v1/sys/seal');
        if ($ch === false) {
            return ['ok' => false, 'error' => 'cURL init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Vault-Token: ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // No curl_close(): the handle is released by GC (PHP 8.5 deprecates curl_close()).
        if ($raw === false) {
            return ['ok' => false, 'error' => 'cURL error: ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'OpenBao HTTP ' . $code];
        }
        return ['ok' => true, 'error' => ''];
    }
}
