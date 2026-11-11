# Proposal: example-set-verbatim-fields

## Why

REFRESH-4b stopped: `example-set:install zuiddrecht --check` refused dossiq's mail templates (`mailsjabloon-ontvangst-woo-v1/-v2`) because their subject and body hold the template's own placeholders (`{{zaakNummer}}`, `{{contactNaam}}`, `{{startDate}}`, `{{deadline}}`), which the installer read as example-set tokens it cannot fill. The token resolver had no escape.

## What changes

A declared object MAY name `verbatimFields`: top-level fields of its `data` that are written exactly as declared. Every other field is filled as before, and a placeholder there that cannot be filled (a typo in a real token included) still refuses the set.
