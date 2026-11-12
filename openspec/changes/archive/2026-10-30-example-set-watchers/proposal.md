## Why

A follower is a row in `openregister_watchers`, keyed by user and object. The case page shows a case's followers by their initials, and the "Cases you follow" lists read the same rows. An example set could not write one: the loader had no part for follows. So dossiq's DqZaak board, which shows Anouk Bakker (AB) following the Woo case, had nobody to draw. The cast also had no Anouk Bakker.

## What Changes

- A fragment MAY declare `watchers`: rows of `{user, objectUuid, register?, schema?, notify?}`. They are written through `WatcherMapper::subscribe`, recorded as the user and object pair, proved on a later install and taken away on remove.
- The check refuses a follower who is not one of the set's own accounts, a row without an object, a `notify` that is not true or false, and any other field.
- The Zuiddrecht cast gains Anouk Bakker, a legal adviser in Juridische zaken.
