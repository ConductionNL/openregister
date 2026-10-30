# Proposal: party-kind-identity-shapes

## Why

A party link's kind comes from the party schema (`x-openregister-party.kind`: `person`, `organisation`, and now an app's
own kind, such as dossiq's `colleague`). A host can also read which kinds a record accepts from another vocabulary.
pipelinq's party kinds are role kinds (`aanvrager`, `gemachtigde`), each listing the identity shapes it accepts
(`naturalPerson`, `organization`). Compared key for key, the two vocabularies never match, so nc-vue's parties widget
marked every party "Kind not accepted here".

## What changes

A `partyKinds` entry on a schema may name `identityShapes`: the shapes a party of that kind has in a shared vocabulary.
Normalisation keeps the list (distinct, trimmed, non-empty strings), as it keeps `roles`. A host compares a party's shapes
with the shapes the accepted kinds name.

## Scope

`lib/Db/Schema.php` (normalisation of `partyKinds`). The party listing already returns `getPartyKinds()` as `kinds`.
