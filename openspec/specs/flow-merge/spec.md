# flow-merge Specification

## Purpose
TBD - created by archiving change flow-or-merge-keeps-every-firing. Update Purpose after archive.

## Requirements

### Requirement: An OR-merge place keeps every firing's items

When several steps lead to one node without a declared `join`, every firing of a
predecessor SHALL add its items to the node's input place. A firing SHALL NOT
replace items an earlier firing left there and the node has not yet consumed.
When the node fires it SHALL read every item on its input place and clear it.

#### Scenario: Shards converge on one node

- **GIVEN** a flow where a trigger fans out to three shard steps, and each shard
  leads to one `hits` node without `join`
- **WHEN** the run walks the flow
- **THEN** the `hits` node receives the item of every shard exactly once

#### Scenario: Two firings onto an occupied place

- **GIVEN** a place holding one unconsumed item from step `s1`
- **WHEN** step `s2` fires one item onto the same place
- **THEN** the place holds both items, `s1`'s first
- **AND** the input places of `s1` and `s2` are cleared
