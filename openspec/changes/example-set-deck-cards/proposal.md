# A fragment may put Deck cards on its objects

## Why
A case's Kaarten card lists the Deck cards linked to it. An example set could not seed one, so DqZaakTaken showed no cards while its board draws "Lakken offerte lantaarnpalen" (Woo en informatie, Deze week, Lars de Koning, Bezig).

## What changes
- A new `deckCards` part. Each row names the object, the board and the list, the card's title, description, due and done dates, owner, assignees and labels (a title or `{title, color}`). It may also give `shareWith` (the groups that may edit a board the set writes) and `color`.
- The board, list and labels are found by title, or written when absent. The card is written and linked to the object as a linked card is, with its labels and assignees cached on the link.
- Deck's services act for the signed-in user, and an occ install has none, so the rows are written straight to Deck's tables (`ExampleSetDeckStore`).
- Remove deletes the card and its link. It also deletes the labels the set wrote, and the list and board the set wrote, but only when nothing else is on them.
- Without Deck the row is reported as not written. The owner and assignees must be accounts the set creates or adopts.
