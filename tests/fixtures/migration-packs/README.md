# Migration pack fixtures

Pack definitions the editor's round-trip tests load (`src/views/migrationPack/packDraft.spec.js`).
Saving any of them through the editor without an edit must give the same definition back.

- `stackiq/topdesk-*.json`: copies of the TOPdesk CMDB import packs that stackiq ships in
  `lib/Settings/cmdb-import/`. They hold column names and lookup values only. They exercise a
  lookup with `"default": null`, lookup values that are lists, a date format starting with `!`,
  one column feeding two targets, two columns feeding one target, a join and pack defaults.
- `openregister/zgw-zaken-json.json`: OpenRegister's own seeded template pack, in the shape the
  API returns it, with the empty `defaults` and `skipRows` that PHP stores as `[]`.

Refresh the stackiq copies when stackiq changes its packs; the tests do not read stackiq itself.
