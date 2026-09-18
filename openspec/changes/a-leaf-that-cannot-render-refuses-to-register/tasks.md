# Tasks: a-leaf-that-cannot-render-refuses-to-register

## 1. One answer, two callers

- [x] 1.1 `LeafBundle` answers whether an app ships `js/<app>-leaves.js`.
- [x] 1.2 `LeafScriptListener` delegates to it instead of its own copy.

## 2. The refusal

- [x] 2.1 `LeafRegistry` refuses a render surface whose app ships no bundle,
      naming the leaf, the app and the file to build.
- [x] 2.2 Data providers, agent runners, built-in leaves and disabled apps are
      not refused. Each has a test; the disabled case is the one an existing
      test caught.

## 3. What this does not do

- [ ] 3.1 Fix the three dark leaves. buildiq, decidiq and hermiq each need a
      `leaves` webpack entry in their own repository, which is their lane's
      work, not this one's. hermiq's is the smallest: it already builds the
      bundle and needs the entry renamed to the name the loader reads.
- [ ] 3.2 A fleet gate. This refuses at runtime, where the instance knows which
      apps are installed. A build-time gate cannot see that, and would have to
      guess.
