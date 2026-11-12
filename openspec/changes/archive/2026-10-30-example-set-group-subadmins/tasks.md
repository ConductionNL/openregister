# Tasks: example-set-group-subadmins

- [x] `ExampleSetAccounts` isSubAdmin / addSubAdmin / removeSubAdmin over `OCP\Group\ISubAdmin`
- [x] `ExampleSetPeople` installs, refuses and proves declared sub-admins; `ExampleSetRecord` keeps `subAdmins`; `ExampleSetRemover` takes recorded ones away before memberships
- [x] Zuiddrecht cast: `subAdmins: [pieter]` on every cast group
- [x] Tests: made, kept on a second install, taken away on remove; an unknown key refuses the set
