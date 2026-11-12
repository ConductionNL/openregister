# Tasks: example-set-recorded-floor

- [x] 1.1 A recorded entry follows a floor declared later: the key is raised when below it, and the record takes the floor and keeps the original for remove
  - Files: lib/Service/ExampleSet/ExampleSetAppConfig.php, lib/Service/ExampleSet/ExampleSetInstaller.php
  - Test: tests/Unit/Service/ExampleSet/ExampleSetAppConfigAtLeastTest.php
- [ ] 1.2 Live: a REFRESH on :8080 raises pipelinq's ticket_reference_counter to 423 and reports it kept afterwards
