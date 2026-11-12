---
kind: code
---

## Why

A rule's `channels` are both what it sends by default and the only channels a
person may choose from: a preference can narrow them, never widen them. So an
app could offer e-mail per notification only by mailing everybody by default.
dossiq's personal settings board draws two columns, "In Nextcloud" and "Per
e-mail", with e-mail switched on for some notifications only; Ruben decided
(25 Oct 2026) that e-mail is an opt-in channel, off until a person switches it
on.

## What changes

- A notification may declare `optInChannels`: per-recipient channels
  (`nc-notification`, `email`, `activity`, `web-push`) that are off by default
  and delivered only to a person, or a group default, that switched them on.
- The effective preference defaults to `channels`; a layer may choose any of
  `channels` plus `optInChannels`.
- The dispatcher sends a recipient their chosen channels among those.
- The preferences list answers `availableChannels` and `optInChannels` per
  entry, so a settings screen can draw one column per channel.
- The validator refuses an opt-in channel that is not per-recipient.

Backwards compatible: a rule without `optInChannels` behaves as before.
