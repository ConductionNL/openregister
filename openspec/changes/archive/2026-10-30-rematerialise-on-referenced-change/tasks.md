# Tasks: rematerialise-on-referenced-change

- [x] `ReferencedCalculationRefresher`: dependencies from `x-openregister-references` and materialised expressions, plus re-saving the referring objects
- [x] `ReferencedCalculationListener` on `ObjectUpdatedEvent`, registered in `Application`
- [x] Tests: a changed read field re-saves the referring object; a change no calculation reads re-saves nothing; the dependency names the pointing field and the fields read
