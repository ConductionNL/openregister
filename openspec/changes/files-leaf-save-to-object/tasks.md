# Tasks: files-leaf-save-to-object

## 1. Backend

- [x] 1.1 Attach a node reusing the upsert pipeline: `lib/Service/File/AttachNodeHandler.php`
      (beside FileService, which it calls: every byte goes through `FileService::addFile()`),
      route `POST /api/objects/{register}/{schema}/{id}/files/attach` with `nodeId`, looked up in
      the CALLER's own Files (an id they cannot open is 404). A folder attaches the files directly
      inside it, at most 50, and reports how many it skipped. `AttachNodeHandlerTest` (4).
- [x] 1.2 Talk chat export to a `.txt` file, then attach: `TalkChatExporter` (oldest first, author
      and UTC time per line, header says who saved it), route
      `POST /api/objects/{register}/{schema}/{id}/files/chat` with `conversation` and `messages`
      (the Talk action reads the messages in the browser as the user). `TalkChatExporterTest` (3).
- [x] 1.3 The picker's rule, in `lib/Service/Integration/AttachTargetFilter.php`:
      which schemas may be offered (writable AND holding files, two different
      silent failures), which search hits may be shown, and why an attach is
      refused. Pure, so every rule is drivable without a session.
      **An unofferable target is not offered and not COUNTED**, which is
      deliberately the opposite of the contact panel: there a reader is owed a
      true total, so a row they may not read is counted and never named; here
      nobody is owed a count of registers they cannot write to, and a count
      would name which ones exist.
      **A manifest declaration narrows and never widens** — a pin that could
      add a target would be a manifest handing out write access.
      The ROUTE itself waits on 1.1, because there is nothing to attach to
      until the attach exists.

## 2. Plugins

- [x] 2.1 Files action "Add to object" with the object picker: `src/files/attachAction.js`
      (registered from the Files script entry, offered on one readable node) opens
      `src/dialogs/files/AttachToObjectDialog.vue`, which searches
      `GET /api/files/attach-targets` (`AttachTargetsController` over
      `AttachTargetSearch`, which feeds `AttachTargetFilter` the platform's update
      verdict per schema; a schema holds files unless `allowFiles` is false) and
      attaches through 1.1. Tests: `AttachTargetSearchTest` (6),
      `AttachTargetsControllerTest` (3), `attachAction.spec.js` (8),
      `AttachToObjectDialog.spec.js` (6).
- [x] 2.2 Talk conversation action "Save chat to object".
      Talk offers other apps message actions, so the action sits in a message's
      menu and saves the conversation that message belongs to:
      `src/talk/saveChatAction.js` reads the conversation through Talk's own chat
      API as the signed-in user (backwards a page of 200 at a time, at most 5000,
      without moving the read marker, system messages left out, `{file}`-style
      placeholders replaced by their names) and opens the picker in chat mode,
      which posts to the 1.2 route. Registered from the new `talkActions` entry
      (`src/talk-actions.js`, one self-contained script), which
      `lib/Listener/TalkAppScriptListener.php` adds to Talk's page only, for a
      signed-in user with Talk enabled. Tests: `saveChatAction.spec.js` (13),
      `AttachToObjectDialog.spec.js` (+2 chat), `TalkAppScriptListenerTest` (6).
- [x] 2.3 Read `fileActions.attachTargets` from the consuming manifest. (The route takes
      `targets` and the picker a `targets` prop; reading them from a consuming app's
      manifest is still open.)
      The picker takes an `app` prop and reads that app's manifest
      (`GET /api/manifest/{app}`) once, through `src/services/attachTargets.js`:
      an entry is a schema slug or a `{ register, schema }` pair, the schema is
      what the server narrows on, and a manifest that declares nothing or cannot
      be read pins nothing (the server's offer is already only what the caller
      may change). Passed `targets` win over the manifest. Tests:
      `attachTargets.spec.js` (6), `AttachToObjectDialog.spec.js` (+3).

## 3. Tests

- [x] 3.1 Unit tests for the writable filter:
      `tests/Unit/Service/Integration/AttachTargetFilterTest.php` (12),
      including both silent failures, the declaration that cannot widen, the
      bounded search, and the refusals that name a schema to nobody who did
      not already pick it. Attach-by-node waits on 1.1.
- [x] 3.2 `tests/e2e/ci/files-leaf-save-to-object.spec.ts`: from Files,
      run the action on a file, pick an object, see the file on the object.
      As the non-administrator `e2e-owner`: the picker's offer over HTTP holds the
      schema they may change and not the one they may only read (the administrator
      finds both cases), and in the browser the Files row's "Add to object" opens
      the picker, the case is found by its title, picking it shows "Added to ...",
      and the file is listed on the case. Live run (26 Nov, sandbox Nextcloud 34 on
      MariaDB, `php -S`): 2 passed. On SQLite the fixture cannot save an object at
      all (magic tables), which is the sandbox, not this change.
