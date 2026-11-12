# A fragment may give its objects a history by named people

## Why
The Historie card on DqSociaalDomein now leaves out rows by System. The board draws two rows by Fatma Kaya on the Jeugdwet case: "Toestemming vastgelegd" (3 okt) and "Inzage medisch verslag" (6 okt). Every row an example set writes is by System, so the card was empty.

## What changes
- A new `auditEntries` part. Each row names the `object`, the `action`, the `user` who acted and the moment (`at`). It may give `changed`.
- Each row becomes one audit trail entry by that person, sealed into the hash chain like any other.
- Every such entry carries the cause `import` and the run `example-set:<app>/<key>`, so a seeded entry can always be told from a real one.
- A second install finds the entry by that run and writes nothing.
- An audit trail is history, so remove leaves the entries.
