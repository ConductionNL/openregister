# Tasks

- [x] MagicNumberColumn: the column's precision and scale from the property, the refusal of a value the column cannot hold, and the widening check.
- [x] MagicMapper::mapNumberProperty uses it; getExistingTableColumns reads numeric precision and scale.
- [x] Widen narrower decimal columns in updateTableStructure, reconcile --apply and the WidenMagicNumberColumns repair step; never on a plain read or save.
- [x] Refuse a number the column's actual size cannot hold on the single-object and the bulk write path (MagicNumberColumnSizes).
- [x] Unit tests, red first.
