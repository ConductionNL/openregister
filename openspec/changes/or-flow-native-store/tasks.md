# Tasks: or-flow-native-store

- [x] `OpenRegisterFlowResolver` (resolveFlow / resolveSubject / flowsForTrigger)
      over a configurable `flow_register` / `flow_schema` store (defaults
      `flows` / `flow`); non-flow-shaped object -> null; absent store -> null/[].
- [x] `FlowResolverRegistrationListener` + registration on
      `RegisterFlowResolversEvent` in Application.php.
- [x] OpenRegisterFlowResolverTest — resolve, not-flow-shaped, not-found, trigger
      enabled/scoped filtering, absent store (6 tests). phpcs clean.
- [x] Live-verified on 8080: absent store -> null; a real flow-shaped object
      resolved through the OR resolver (nodes=2, edges=1); registry reachable.
- [ ] Ship + force-import a canonical `flow` schema on install — follow-up.
- [ ] Flow-authoring canvas — follow-up (objects are editable in the object editor today).
