# OpenBao seal-only token (admin PIN tripwire)

Three wrong admin PINs seal the OpenBao vault. The portal does that with a
**seal-only token**: a token whose policy grants nothing but `update` on
`sys/seal`. It can seal the vault — and nothing else. It cannot read a
secret, list anything, create a token, or unseal. If it ever leaks, the
worst the holder can do is seal the vault (an outage, not a breach), and
you unseal it again with BaoLock.

## 1. The policy

Save this as `openbao-seal-only.hcl` (or paste it in the OpenBao UI under
Policies → Create policy, name `ownpbx-seal-only`):

```hcl
# Seal-only: the OwnPBX portal uses this token ONLY to seal the vault after
# three wrong admin PINs. It grants nothing else.
path "sys/seal" {
  capabilities = ["update"]
}
```

## 2. Create the policy and the token

Run these on the docker host, in `~/openbao-test`, with the root token
from Bitwarden (`OPENBAO_SEAL_TOKEN` is what the portal reads; the value
below is only ever in your terminal and in `/etc/ai-pbx.env`, never in git):

```bash
cd ~/openbao-test
export BAO_TOKEN='ROOT_TOKEN_HERE'
docker compose exec -T -e BAO_TOKEN openbao bao policy write ownpbx-seal-only - <<'EOF'
path "sys/seal" {
  capabilities = ["update"]
}
EOF
docker compose exec -e BAO_TOKEN openbao bao token create -policy=ownpbx-seal-only -ttl=768h
```

(The policy is piped in on stdin — the HCL file is not mounted into the
container, so `policy write` cannot read it from a host path. Or paste
the HCL in the OpenBao UI under Policies → Create policy, name
`ownpbx-seal-only`, and skip the `policy write` step.)

The `token create` output prints the token (starting with `s.`). Save it
in Bitwarden. The 768h TTL (32 days) matches the mail-inject badge; renew
it or mint a fresh one before it expires, the same way.

Note: never put a policy file under `/openbao/config/` — OpenBao loads
every file under the config directory as server config. Policies live
under `/openbao/policy/` (or in the UI).

## 3. Give it to the portal

In `/etc/ai-pbx.env` (mode 640, root:asterisk), add:

```
OPENBAO_ADDR=http://192.168.8.100:8200
OPENBAO_SEAL_TOKEN=<the token from step 2>
```

Then restart Apache so the portal picks it up. Leave
`OPENBAO_SEAL_TOKEN` empty to disable the seal: the 3-strikes account
lockout still applies, but the vault is not sealed.

## What happens on 3 wrong PINs

1. The portal counts the failure in `sys_users.admin_pin_failures`
   (persisted, so it survives a restart).
2. On the 3rd wrong PIN inside 60 minutes it calls
   `PUT /v1/sys/seal` with this token.
3. The vault seals. The admin's pending session is destroyed and they are
   sent back to the login page.
4. Recovery: unseal the vault with BaoLock (3 unseal keys), then sign in
   again. The failure counter resets 60 minutes after the first failure
   in the window, so a locked-out admin is not stuck forever — but the
   vault stays sealed until you unseal it.

## If the seal call itself fails

The lockout still applies: the session is destroyed and the counter stays
at 3, so no more PIN tries are possible until the window expires. The
failure is written to the audit log (`admin_pin_lockout`) and to the PHP
error log. Check that `OPENBAO_SEAL_TOKEN` is set and the vault is
reachable from the portal host.
