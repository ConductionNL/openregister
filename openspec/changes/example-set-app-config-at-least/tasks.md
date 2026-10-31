# Tasks: example-set-app-config-at-least

- [x] 1.1 The catalogue carries `atLeast` and refuses it on a generated or non-integer entry
  - Files: lib/Service/ExampleSet/ExampleSetCatalogue.php
  - Test: tests/Unit/Service/ExampleSet/ExampleSetAppConfigAtLeastTest.php
- [x] 1.2 Install raises but never lowers a floor; the read-back reports it lost only below the value; remove leaves a moved counter
  - Files: lib/Service/ExampleSet/ExampleSetAppConfig.php, lib/Service/ExampleSet/ExampleSetInstaller.php
  - Test: tests/Unit/Service/ExampleSet/ExampleSetAppConfigAtLeastTest.php
- [ ] 1.3 Live: pipelinq declares `ticket_reference_counter` as a floor, and REFRESH reports it kept
