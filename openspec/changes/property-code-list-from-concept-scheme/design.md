# Design: property-code-list-from-concept-scheme

## D-1: the value stored is the concept's URI by default

A URI survives a relabel and a merge of schemes; a notation is shorter and
is what a ZGW mapping expects. The declaration chooses, `uri` by default,
and the label is always derived, never stored.

## D-2: validation reads the scheme through the resolution API

The concept resolution API already answers "is this a concept of scheme X"
with RBAC applied. Validation calls it, cached per (scheme, version) for
the request, so a save with fifty coded fields costs one scheme read.

## D-3: options are bounded

A scheme can hold thousands of concepts. The schema read returns the first
page of options with a count and a search hint; the form fetches more
through the resolution API. A small scheme comes back whole.

## D-4: kind

Code, in OpenRegister. Consuming apps replace `enum` with one annotation.
