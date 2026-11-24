# Public access links (delta)

## ADDED Requirements

### Requirement: Whoever may publish a subject sees and revokes every link on it (REQ-ABL-005)

A caller who may mint a link over a subject MUST be able to list every link on
that subject, whoever minted it, and revoke any of them. A caller who may not
mint over the subject MUST get the same 404 a mint would give. A link somebody
else minted MUST be listed without its anchor. Without a named subject, listing
and revoking MUST stay limited to the caller's own links.

#### Scenario: a manager sees and ends a member's link

- GIVEN a member minted a read link over a shared view
- AND the manager may publish that view
- WHEN the manager lists the links on the view
- THEN the member's link is listed with its creator and use count and without its anchor
- AND when the manager revokes it naming the view, the link answers 404 on its next use

#### Scenario: an outsider learns nothing

- GIVEN a caller who may not publish the view
- WHEN they list the links on it, or revoke one naming it
- THEN both answer 404

#### Scenario: a link on another subject stays out of reach

- GIVEN a manager who may publish view A
- WHEN they revoke a link on view B naming view A
- THEN the answer is 404 and the link stays live
