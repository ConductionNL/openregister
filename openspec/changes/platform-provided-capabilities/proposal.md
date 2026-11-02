---
kind: code
---

# Proposal: platform-provided-capabilities

## Summary

Nine capability rows are delivered by Nextcloud, not by OpenRegister code: single sign-on, LDAP accounts, sessions, passkeys, the administrator IP allowlist, external storage, the app store install, the Dutch interface and the language of system mail. All nine are built, but no requirement says what OpenRegister relies on or what it must not break. A later change could add its own login, write files past Nextcloud's storage layer or hard-code an English string, and nothing would refuse it.

This change writes them down as one `platform-provided-capabilities` spec. Each requirement names the Nextcloud surface OpenRegister relies on and the behaviour that keeps it working.

## The rows this covers

Source: `openspec/parity/capabilities.json`.

| row | capability | provided by |
|---|---|---|
| acc-sso | Sign in with the organisation's single sign-on provider | Nextcloud login (user_oidc, user_saml) |
| acc-ldap | Sign in with LDAP or Active Directory accounts | Nextcloud user backends (user_ldap) |
| acc-sessions | See active sessions and devices and sign them out | Nextcloud personal security settings |
| acc-passkey | Sign in with a passkey or security key | Nextcloud WebAuthn and two-factor login |
| acc-ip-allowlist | Allow administrator access only from listed networks | Nextcloud `allowed_admin_ranges` |
| file-external-storage | Store attachments in S3 or another external storage | Nextcloud primary and external storage |
| op-install | Install from the app store with no extra servers | Nextcloud app store |
| op-dutch-ui | Use the interface in Dutch as well as English | Nextcloud l10n (`l10n/nl.json`) |
| op-mail-language | Send system mail in the recipient's language | Nextcloud mail templates and user language |

## What changes

1. A new capability `platform-provided-capabilities` with one requirement per row.
2. Tasks that tag the code carrying each property and add the checks that keep them true.
3. The nine rows in `capabilities.json` cite this change and its requirement.

## Screens

None of the rows has a screen of its own; see `screens.md`.

## Out of scope

- Configuring SSO, LDAP, storage or the IP allowlist. That is Nextcloud administration.
- Translating strings. That runs through Transifex like every Nextcloud app.
