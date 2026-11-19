# L1-wiring: round 1 done (2026-11-19)

The PRs below are open against main and stacked, and none is merged. Branches pushed at 03bbdd5f6ed, 72440c2d700 and f2a97176ecb.
- #10679 refactor/L1-wiring-processes-mapper-bindings (1271 -> 1211)
- #10680 refactor/L1-wiring-integrations-mapper-bindings (contains #10679; 1211 -> 1167)
- #10681 refactor/L1-wiring-compliance-mapper-bindings (contains #10680; 1167 -> 1148)

## Round 2 (only when the coordinator resumes)
- DbRegistrar: Configuration (15), Platform (3), Files (1: EntityRelationMapperInterface into FilesRegistrar::BINDINGS).
- ContractBindings: the Platform bindings (8). ContractBindingsRegistrar (Core) -> ContractBindings (Configuration).
- ServiceRegistrar factories (20 references).
- ListenerRegistrar and ConfigurationListenerRegistrar: keep the listener order as it is.
- DoriathApplicationRegistrar -> AppSettingsAccess.

Helper scripts are in tools/. Each area's registrar and map go in lib/Db and bind through DbRegistrar::bind(). Add each new registrar to tests/Unit/Db/DbBindingMaps.php.
