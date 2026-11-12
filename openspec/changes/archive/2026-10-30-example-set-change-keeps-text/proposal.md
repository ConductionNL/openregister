# Proposal: example-set-change-keeps-text

## Why

The example-set check before REFRESH-4 refused the Zuiddrecht set: `change "parkeren-henk-uitkomst" (dossiq case): Property 'geometry' should be type 'string or null' but is 'object'`. A dossiq case keeps its GeoJSON geometry as JSON text, and OpenRegister reads it back as a structure. A change validates and writes the whole object as read, so any change to a case with a geometry failed unless the change listed `geometry` in its `jsonFields`.

## What changes

A change (and the references refresh, and remove) treats a property its schema types as text only, and that the store read back as a structure, as JSON text: it is written, validated and restored as text, without the change naming it.
