# Register cards mount their schema-row action menus on demand

## What was wrong

Every register card on the Registers page lists up to five schemas, and every
schema row carried its own action menu (`NcActions` with seven action buttons).
With 20 cards on a page that is up to 100 action menus, mounted during the first
render although none of them is open. On the sandbox reference stack this
render was the largest task between the reads finishing and the cards painting:
the cards (the page's first useful content) painted ~350-500 ms after the
register list had arrived.

## What changes

- A schema row shows a plain icon trigger (a plain button drawn like the menu's tertiary icon trigger, labelled
  "Actions", `aria-haspopup="menu"`) until the card is pointed at or focus
  enters it. Then the card mounts the row menus, before the click lands.
- A trigger activated without that (assistive technology, a scripted click)
  mounts the menus and opens and focuses the menu of its own row.
- The header menu of each card and every action itself are unchanged.

## Out of scope

- The schema cards on the Schemas page (no row menus).
- Rendering the cards themselves in fewer components.
