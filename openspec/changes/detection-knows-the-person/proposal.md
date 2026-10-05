---
kind: code
depends_on: [anonymisation-discloses-itself]
---

# Proposal: detection-knows-the-person

## Why

Two rows ask the detector to know who a name belongs to. Our column (`baseline/openwoo.tsv`):

| row | capability | ours today |
|---|---|---|
| 14.19 | A detection is checked against the organisation's own register of people before it is proposed | no: detection is pattern, Presidio NER, LLM or hybrid over text (`EntityRecognitionHandler`); nothing tells a person's name from a street's |
| 18.8 | A name is released or withheld according to whether the person acted in a professional capacity, and the product records which applied | no: entity types are person, organisation, location and sensitive PII; ground 5.1.2e is chosen per document with no role input |

Under the Woo, the name of an official acting in a public role is normally released, a civil servant's name is normally withheld under article 5.1, second paragraph, sub e, and a private person's name is withheld. Officers make that call by hand for every name today. Decision D10 (Ruben, 2026-10-05) unflagged 14.19.

## What changes

- A detected PERSON gets a capacity suggestion: `public-office`, `professional`, `private` or `unknown`, from the organisation's own register of officials (a configured OpenRegister schema or Nextcloud groups) and from role words next to the name ("wethouder", "burgemeester", "namens", a signature block).
- An organisation policy maps a capacity to a suggested decision; the shipped default is release for `public-office` and withhold for the other three.
- A suggestion is never a decision. The reviewer confirms or changes it, and the relation records the capacity that applied, its source and who confirmed it. Until confirmed, the occurrence is treated as withheld.
- Before a PERSON detection is proposed, it is matched against a configured persons register (for example a BRP projection or a contact register) to raise its confidence, and against a configured place or street list to mark it uncertain. The lookup is bound to a declared purpose, reads in memory, and stores only that a match happened and in which source.

## What does not change

- The review gate (`redaction-release-safeguards`) and the decision path (`PATCH /api/entity-relations/{id}`), which this change feeds.
- The list of exception grounds: per decision D3 that is dossiq's. A capacity suggestion carries the ground identifier configured in the policy (default `5.1.2e`) as text.

## Dependencies and absent apps

- `anonymisation-discloses-itself` (wave 1): the confidence threshold setting the adjustment works against.
- No persons register configured: no confidence adjustment, and every run records `personRegister: not-configured`. No officials register: capacity comes from role words only, or is `unknown`.

## Wave and decision

Wave 1, size L. Implements D10 for 14.19. Closes 14.19 and 18.8.
