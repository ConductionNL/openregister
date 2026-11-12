# Tasks: example-set-archival-setting

- [x] `destruction_list_register.json` + `ImportDestructionListRegister` repair step, own id in `DescriptorConfigIds`
- [x] `ExampleSetArchival` engine row: merge, prove, restore; `ExampleSetCatalogue` reads the part for OpenRegister only; `ExampleSetRecord` keeps `archival`
- [x] Zuiddrecht cast: `requires` destruction-lists, `archival` part
- [x] Tests: merge keeps other keys, remove restores, a changed key stays, unknown key and missing register refuse, another app is refused; the descriptor's statuses cover the open ones the review reads
