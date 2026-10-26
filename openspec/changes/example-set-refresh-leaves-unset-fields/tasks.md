# Tasks: example-set-refresh-leaves-unset-fields

- [x] `ExampleSetRewriter::rewrite()` drops null fields of the stored row before merging the declaration
- [x] Test (failing first): a field the declaration does not name, read back as null and refused as null, does not stop the refresh
