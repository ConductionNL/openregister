# notificatie-engine Delta: notification-opt-in-channels

## ADDED Requirements

### Requirement: A rule can declare a channel that is off until someone switches it on

A notification rule MAY declare `optInChannels`, a list of per-recipient channels. Such a channel SHALL NOT be delivered to a recipient unless that recipient's effective preference (their own value, else their group's default) includes it. The effective channels SHALL default to the rule's `channels` (or `["nc-notification"]` when absent), and a preference layer SHALL be able to choose any channel in `channels` plus `optInChannels`, never another. The preferences list SHALL answer each entry's `availableChannels` (default channels, then opt-in ones) and `optInChannels`.

#### Scenario: E-mail is off by default
- GIVEN a rule with `channels: ["nc-notification"]` and `optInChannels: ["email"]`
- WHEN it fires for jan and piet, neither of whom set a preference
- THEN both get a Nextcloud notification and neither gets an e-mail

#### Scenario: A person switches e-mail on
- GIVEN jan set `channels: ["nc-notification", "email"]` for that rule
- WHEN it fires for jan and piet
- THEN jan gets a notification and an e-mail, piet only the notification

#### Scenario: A broadcast channel cannot be opt-in
- WHEN a rule declares `optInChannels: ["talk"]`
- THEN the validator reports `notification-bad-opt-in-channel`
