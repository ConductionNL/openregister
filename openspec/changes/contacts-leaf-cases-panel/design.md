# Design: contacts leaf cases panel and name search

## D-1: the cases panel is the reverse lookup with a surface

`ContactsProvider` already answers "which objects link this contact" through
the link table (requirement Reverse Lookup). The panel is a read of that
answer, grouped by schema, joined with the object's title and, when the schema
declares `x-openregister-lifecycle` or a `status` property, that value. No new
storage.

## D-2: two leaf surfaces, declared by the consuming app

integration-leaf-foundation lets a provider expose surfaces (`tab`, `widget`,
`page`). The contacts leaf gains `detail` (one contact, with the cases panel)
and `index` (the name search). A fleet app places them in its manifest as
`leaf: contacts` pages; dossiq's future Contacts domain is two manifest
entries.

## D-3: the search reads the Contacts app, not a copy

The name search queries the address books the current user may read through
`OCP\Contacts\IManager::search()`, so the permission model stays the Contacts
app's. Results carry the contact's URI, which the link table already stores.

## D-4: kind

Code, in OpenRegister. The consuming apps ship config only.
