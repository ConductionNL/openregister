# Design: platform-reference-provider

## D-1. Access decides the card, not the link

A card is metadata about a record, and it renders in a conversation whose
members are not the record's readers. The resolution is per reader: a user
who may not read the object sees the bare link, which leaks nothing and
still works if they later gain access.

## D-2. One provider for every schema, not one per app

Ten leaf apps with ten providers is ten resolutions of the same URL shape
and ten access implementations. One provider reading the deep link
registry resolves them all.

## D-3. The card is declared, and the default is dull rather than wrong

An undeclared schema renders its title and its schema name. That is
uninformative and it is never misleading, which is the right failure for
something that renders in a chat window.

## D-4. kind

Code, in OpenRegister.
