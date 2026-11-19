# Emergency Lockdown & SMS Kill-Switch

**System → Emergency lockdown** is for the case this whole page assumes: somebody else is in the portal and you are not, or you no longer trust an account that is signed in. It shuts doors quickly, including from a phone when you cannot sign in at all.

It is deliberately asymmetric. **Closing can be done from anywhere. Opening cannot.**

## The three controls

They are separate on purpose, so one can be used without the others.

| Control | What it does | What it does *not* do |
|---------|--------------|------------------------|
| **Sign everyone out** | Ends every session: portal, Android app, and REST API tokens | Does not stop anyone signing straight back in |
| **Lock an account** | That account cannot sign in at all, and its sessions end immediately | Does not affect anybody else |
| **Lock the PBX down** | Only the local network may sign in; everyone currently signed in is dropped and API tokens are suspended | Does not change passwords or touch SIP/trunk registration — calls keep working |

### How sessions actually end

Each user row carries a `token_epoch`, and every authenticated request compares the session's copy against it. Raising the number invalidates every session that user holds, wherever it is held, without hunting through session files on disk. This is the same mechanism that already signed people out when their password changed.

API tokens are switched off rather than deleted, and each one records that *the lockdown* suspended it (`sys_api_tokens.suspended_at`). Lifting the lockdown restores exactly those, and leaves alone any token an administrator had deliberately turned off beforehand.

### Locking is not deactivating

`is_active = 0` means "this person has left". `locked_at` means "this account was sealed during an incident". They are separate columns so that tidying up users later cannot silently unseal something, and so the lockdown page can list what is sealed and why.

The lock is checked **after** the password has been verified, not before. A locked account therefore fails exactly the way a wrong password does — otherwise the lock would become an oracle for testing which passwords are valid.

## What counts as "local"

By default: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `::1/128`, `fc00::/7`. Both IPv4 and IPv6 are matched by prefix on the packed address.

> **Behind Cloudflare Tunnel or any reverse proxy, turn on Network → Reverse proxy first.** Otherwise every request appears to come from the proxy's own address. If that address is inside a private range, *everyone on the internet* counts as local and the lockdown protects nothing.

## Locking down by text message

The point of the text channel is that the day you need it, you may not be able to reach the portal at all.

Text one of the PBX's own SMS numbers (requires the SMS plugin) from a phone on the allow-list:

```
<passphrase> <6-digit code> LOCKDOWN     seal the PBX and sign everyone out
<passphrase> <6-digit code> KICK         sign everyone out
<passphrase> <6-digit code> KICK 611     sign one person out
<passphrase> <6-digit code> LOCK 611     seal one account
<passphrase> <6-digit code> STATUS       what is sealed right now
```

### Why two secrets

A sending number proves nothing — it is trivial to put someone else's number in the "from" field — so the proof has to be inside the message.

- The **passphrase** is stored hashed, never in plain text. It must contain no spaces, because the message is read as passphrase, then code, then command.
- The **code** comes from an authenticator app (TOTP) and is refused once used, even within its own minute. A message that someone intercepts is worthless by the time they resend it.

Both halves are always checked, so the time a reply takes says nothing about which half was wrong, and a failure says only `Not accepted.`

### What it refuses

- A text from a number that is not on the list gets **no reply at all** — not even an error. A stranger texting the PBX cannot discover that this channel exists.
- Five wrong attempts and the channel stops answering for fifteen minutes.
- **There is no command that opens anything.** No unlock, no override, no "lockdown off", and no way to change these settings by text. This is the whole design: if a text could open the doors, a text is all an attacker would need. The test suite asserts that `UNLOCK`, `OVERRIDE` and `LOCKDOWN OFF` cannot reopen a sealed PBX.

## Getting back in

Two ways, both of which require presence rather than a secret someone could capture.

**From the local network.** The unlock controls on the lockdown page are disabled unless your address is local. An attacker signed in as an administrator from outside can seal the PBX; they cannot unseal it, and they cannot quietly re-point the text channel at their own phone — changing those settings is treated as opening a door too.

**From the console**, which cannot be reached over the network at all:

```bash
php /opt/aipbx/web/bin/unlock.php status          # what is sealed
php /opt/aipbx/web/bin/unlock.php lockdown off    # lift the lockdown
php /opt/aipbx/web/bin/unlock.php user 611        # unseal one account
php /opt/aipbx/web/bin/unlock.php all             # lift everything
```

## Setting it up

All of this must be done **from the local network**.

1. **System → Emergency lockdown.**
2. Add the mobile numbers allowed to command the PBX, comma separated.
3. Choose a passphrase: at least 8 characters, **no spaces**.
4. Press **New authenticator secret** and add it to your authenticator app. It is shown once.
5. Tick **Accept commands by text message** and save.
6. Send yourself a `STATUS` text to confirm the whole path works *before* you need it.

Keep the passphrase and the authenticator entry somewhere that survives losing the phone — and remember that losing both is not fatal, because the console always works.

## Audit

Every action here is written to the audit log with who asked and from where, including commands that arrived by text (recorded as `SMS …1234`, the last four digits only). **System → Audit Log**, domain `security`.
