# Design: platform-search-provider

## D-1. One implementation, several identities

Ten apps registering ten providers means ten queries, ten access
implementations and ten places for a leak. One provider that presents
itself under several identities gives the user the filter they want and
the fleet one code path.

## D-2. The identity comes from the deep link claim

An app already claims a (register, schema) pair to own its deep links.
Reusing that claim as the provider identity means there is one statement
of "this is ours" rather than two that can disagree.

## D-3. The result declaration is on the schema

Guessing the title from the first string property works until it does not,
and then it is the owning app that looks broken. The schema says which
property is the title, which is the subline and which date orders.

## D-4. kind

Code, in OpenRegister.
