# A fragment may give its objects timeline entries by named people

## Why
DqZaakHistorie draws ten timeline entries on the Lindelaan Woo case, by Pieter Jansen, Lars de Koning and the system, from 3 to 6 okt, under the chips Status, Documenten, Berichten, Taken and Toewijzing. A timeline entry is written by whoever is signed in, at the moment of writing. An example set could not give a case its history.

## What changes
- A new `timelineEntries` part. Each row names the `object`, a declared `kind`, the `message`, the `author` (an account the set creates or adopts) and the moment (`at`). It may give `fields`, checked against the kind's declaration, and a `visibility`.
- Each row becomes one timeline entry by that person, at that moment, marked with the source `example-set:<app>/<key>`.
- A second install finds the entry by that source and writes nothing.
- An entry of an undeclared kind is reported, not written.
- Remove deletes the entries the set wrote.
