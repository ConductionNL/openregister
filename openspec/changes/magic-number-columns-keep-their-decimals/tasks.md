# Tasks

- [x] MagicNumberColumn: the column's precision and scale from the property, the refusal of a value the column cannot hold, and the widening check.
- [x] MagicMapper::mapNumberProperty uses it; getExistingTableColumns reads numeric precision and scale.
- [x] Widen narrower decimal columns in handleExistingTable and updateTableStructure; reconcile reports them.
- [x] Refuse an unrepresentable number on the single-object and the bulk write path.
- [x] Unit tests, red first.
