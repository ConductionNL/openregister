# Design: notification-routing-per-group-and-scope

## D-1. A group is resolved at dispatch, not at rule authoring

Storing the members when the rule is written means a new colleague never
gets the warning. The rule holds the group; the dispatcher resolves the
members at the moment it fires, and a member's own preference still
decides what they receive.

## D-2. Three layers, and the answer says which one decided

Schema default, group default, user override. A merged preference that
cannot say where it came from is a support call: "why am I not getting
this" has three possible answers and the API should name the one that
applied.

## D-3. A scope is a register, a schema or a declared domain

Kanboard scopes by project because a project is its unit of work. Ours is
the register and the schema, plus a domain a leaf app declares, which is
how dossiq expresses "vergunningen but not meldingen" without inventing a
second hierarchy.

## D-4. One rule, several transports, one record

Two dispatch paths for one event is how a notification and a ZGW message
drift apart, which dossiq already demonstrates. The rule lists its
transports; the dispatcher runs them and records one outcome per
transport under one event id.

## D-5. A missing template is named, never silently generic

Falling back to a generic body hides the gap forever. The validator lists
the events that have no template, so shipping the set is a finishable task
rather than a thing nobody notices.

## D-6. A broadcast is recorded, because it reaches everybody

One message to every user is the loudest act the system has. Who sent it,
what it said and when it stopped showing all belong on the record.

## D-7. kind

Code, in OpenRegister.
