# Proposal: example-set-organisation-gains-its-quota

## Why

The Zuiddrecht set's second tenant, Gemeente Waterland, was created on 23 Oct before the set declared its quota. The loader only creates a missing organisation and never completes one it finds, so on the dev instance Waterland's object, object-schema and user quota stay null. The DqTenant board reads "Zaken 0 van 5.000" and "Gebruikers 1 van 50"; the instance has no limit to show.

## What changes

- An organisation the set itself created (its uuid is in the record's `organisations`) gains each declared quota field (storage, requests, bandwidth, users, objects, objectsSchema) where it holds none. A quota it already holds is never changed.
- An organisation the set found but did not create keeps its quota untouched, also where it has none: there, no quota can mean unlimited, and the tenant is not the set's to limit.
- A quota write that fails is a refusal, named for the organisation.
