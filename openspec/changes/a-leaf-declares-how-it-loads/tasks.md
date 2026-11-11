# Tasks: a-leaf-declares-how-it-loads

## 1. The declaration

- [x] 1.1 `LeafDescriptor::$loadStrategy`, null by default, with the three
      named conventions.
- [x] 1.2 `LeafRegistry` refuses only a claimed `shared-entry` with no bundle.
      Silence and `own-script` register.

## 2. The declaring apps

- [x] 2.1 Measured, 2026-09-18: of the five apps that declare a render surface,
      **humaniq** and **planninq** use `shared-entry` (bundle present, no init
      script), **decidiq** and **hermiq** use `own-script` (init script, no
      bundle), and **buildiq** no longer declares a render surface at all after
      buildiq#861 retired the one it never built.
- [ ] 2.2 Migrate the four remaining descriptors to state their strategy, one
      PR per repository. Until they do, they are silent, which registers.

## 3. What is deliberately not done

- [ ] 3.1 Make the declaration mandatory. Every descriptor written before this
      is silent, and a required field would refuse all of them. It becomes
      worth revisiting once the four have declared.
- [ ] 3.2 Verify `own-script`. The platform cannot: the app loads that bundle
      itself, from its own boot. Taking the app's word is the honest position,
      and it is why the strategy is named rather than inferred.
