# Tasks: a-leaf-that-cannot-render-refuses-to-register

## 1. One answer, two callers

- [x] 1.1 `LeafBundle` answers whether an app ships `js/<app>-leaves.js`.
- [x] 1.2 `LeafScriptListener` delegates to it instead of its own copy.

## 2. The refusal

- [~] 2.1 `LeafRegistry` REPORTS a render surface whose app ships no bundle,
      naming the leaf, the app and the file to build.
  - 🔴 IT REFUSED, AND THAT WAS UNSOUND. Corrected the same evening. hermiq is
    the proof: it ships no `hermiq-leaves.js` and its leaf is NOT dark. It loads
    its own render-registration bundle on EVERY Nextcloud page with
    `Util::addInitScript('hermiq', 'hermiq-agent-leaf')`, precisely so it runs
    wherever another app renders the integration registry. The refusal would
    have taken down a working feature the day it shipped.
  - 🔑 THE LESSON IS ABOUT WHAT THE REGISTRY CAN KNOW. Whether a bundle reaches
    the page is a fact about the PAGE; the registry sees only the filesystem.
    The absence of one conventional filename is not proof of absence, because it
    is one convention out of at least three and the app chooses which. I had
    measured two of the three and called the answer complete.
  - The loud, actionable error STAYS, because it is what turned hermiq's
    invisibly-named bundle into a one-line fix. Only the skip goes.
- [x] 2.2 Data providers, agent runners, built-in leaves and disabled apps are
      not refused. Each has a test; the disabled case is the one an existing
      test caught.

## 3. What this does not do

- [ ] 3.1 Fix the three dark leaves. buildiq, decidiq and hermiq each need a
      `leaves` webpack entry in their own repository, which is their lane's
      work, not this one's. hermiq's is the smallest: it already builds the
      bundle and needs the entry renamed to the name the loader reads.
- [ ] 3.3 A sound refusal, which needs a DECLARATION rather than a guess: the
      descriptor saying it relies on the shared `leaves` entry, so an app that
      loads its own bundle is never refused and one that relies on the entry can
      be. Named rather than guessed at.
- [ ] 3.2 A fleet gate. This refuses at runtime, where the instance knows which
      apps are installed. A build-time gate cannot see that, and would have to
      guess.
