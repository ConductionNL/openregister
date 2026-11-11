# A linked Deck card carries its board and list

## Why
The Dq case boards draw a linked card's meta line as "Bord Woo en informatie · lijst Deze week · Lars de Koning". The nextcloud-vue Deck card already writes that line from `boardTitle` and `stackTitle`, but `getLinkedCards` returned neither, so live read only "Lars de Koning".

## What changes
`DeckLinkService::getLinkedCards` adds `boardTitle` and `stackTitle` to each row. They are the titles of the board and list that Deck's card lookup puts on the card, and empty when Deck gives none.
