# Tasks: underscore-properties-and-config-paths

## 1. Underscore properties

- [x] 1.1 `MagicMetadataColumn::field()` and `::attribute()`
- [x] 1.2 `MagicStatisticsHandler::convertRowToObjectEntity()` reads a `_` property as a property and skips an unknown metadata column once, at debug
- [x] 1.3 `MagicSearchHandler::convertRowToObjectEntity()` and `MagicMapper::rowToObjectEntity()` read a `_` property
- [x] 1.4 `MagicBulkHandler::prepareObjectsForDynamicTable()` writes a `_` property the schema declares

## 2. Configuration paths

- [x] 2.1 `SchemaMapper::isPropertyPath()`, used for single names and fallback lists

## 3. Tests

- [x] 3.1 `MagicMetadataColumnTest`, `MagicStatisticsHandlerTest` (underscore property, unknown metadata column as control)
- [x] 3.2 `SchemaMapperConfigurationPathTest` (paths accepted, unknown first segment refused as control)
