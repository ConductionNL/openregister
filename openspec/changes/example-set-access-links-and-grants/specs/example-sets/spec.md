# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may declare access links on its objects

A fragment MAY declare `accessLinks`. Each row SHALL name `key`, `object`, `createdBy` and `expiresAt`, and MAY give `capabilities` and `label`. `createdBy` SHALL be an account the set creates or adopts. Install SHALL mint the link through OpenRegister's access link service as that account and record it, without ever writing its anchor to the record or the report. A second install SHALL write nothing and SHALL report a held link that is gone or revoked as missing. Remove SHALL delete the links the record holds, and only those.

The check SHALL refuse a row with any other field, without an expiry, with an expiry before the install day, with a capability the link service does not accept, over an object the set does not write, or minted by an account the set does not create or adopt.

#### Scenario: Sanne's read-only link
- **GIVEN** a fragment declares a link on the Lindelaan Woo case for Sanne de Vries, read only, minted by Pieter Jansen, ending 29 days after the install day at 23:59
- **WHEN** the set is installed
- **THEN** OpenRegister SHALL hold one active access link on that case, created by Pieter, with that label, capability and expiry
- **AND** the record SHALL hold its id and uuid, not its anchor

#### Scenario: Removing the set
- **GIVEN** the set was installed with one access link
- **WHEN** the set is removed
- **THEN** the link row SHALL be gone and the report SHALL count one removed access link

### Requirement: A fragment may declare grants on its objects

A fragment MAY declare `grants`. Each row SHALL name `key`, `object`, `type` (`user` or `group`), `shareWith` and `sharedBy`, and MAY give `permissions`, `verbs`, `sharedAt` and `expiresAt`. `shareWith` SHALL be an account or group the set creates or adopts, and `sharedBy` an account the set creates or adopts. Install SHALL share the object's folder with that principal through OpenRegister's object sharing service, acting as `sharedBy`, with the declared share time and expiry, and SHALL record the share. When the sharing service refuses the account, the row SHALL be reported as not written with the refusal's reason. A second install SHALL write nothing and SHALL report a held share that is gone as missing. Remove SHALL delete the shares the record holds, and only those.

#### Scenario: Juridische zaken, shared by Pieter
- **GIVEN** a fragment declares a grant of the Lindelaan Woo case to the group Juridische zaken, shared by Pieter Jansen 26 days before the install day, ending 14 days after it
- **WHEN** the set is installed
- **THEN** the case's grants list SHALL hold a `shared` row for Juridische zaken with `sharedBy` Pieter Jansen, that share time and that expiry

#### Scenario: An account that may not share
- **GIVEN** a grant declares `sharedBy` an account that neither owns the case nor holds `grant_manage`
- **WHEN** the set is installed
- **THEN** the row SHALL be reported as not written with the sharing service's reason, and the rest of the set SHALL be installed

### Requirement: A refresh makes the access links and grants of a set again

On `--refresh` the set SHALL delete every access link and grant the record holds and create it again from its declaration, so its dates follow the install day, and SHALL record the new ids. A change that quotes the link SHALL run after the new link is minted.

#### Scenario: A link revoked during a demonstration
- **GIVEN** the set's link for Sanne was revoked on the instance
- **WHEN** the set is refreshed
- **THEN** the case SHALL hold one active link for Sanne again, ending 29 days after the refresh day

### Requirement: A change may quote an access link of its fragment

A fragment's change MAY contain `{{accessLink:<key>.<field>}}` with the field `id`, `uuid` or `url`, where `<key>` is an access link the same fragment declares. The installer SHALL write a fragment's access links and grants after its objects and before its changes, and fill the placeholder from the minted link. The check SHALL accept the placeholder for a declared link before it is minted, and SHALL refuse the set when the key names no access link of the fragment.

#### Scenario: dossiq's link record names its link
- **GIVEN** a fragment declares Sanne's access link and a dossiq `caseShare` of type link, and a change that sets the share's `accessLinkId`, `accessLinkUuid` and `accessLinkUrl` from the link
- **WHEN** the set is installed
- **THEN** the Toegang card SHALL list "Link voor Sanne de Vries", and revoking it from the card SHALL revoke the minted link
