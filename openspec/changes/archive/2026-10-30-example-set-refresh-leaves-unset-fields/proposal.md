# Proposal: example-set-refresh-leaves-unset-fields

## Why

REFRESH-4b: `--check` passed but `--refresh` did not refresh shillinq's fee rules leges-bouwen, leges-kappen and leges-intrekken: "Property 'intakeChannel' should be type 'string' but is 'null'". OpenRegister reads every schema property back, null when unset; the refresh merged that whole row into its update, so it sent nulls the schema refuses for fields the declaration never named. The check validates only what the declaration sends, which is why it did not see this: the two paths sent different payloads.

## What changes

A refresh leaves out every field that reads back as null, so it sends what the declaration names plus what is really stored, which is what the check validated.
