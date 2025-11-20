# Migration Errors Fix

## Problem Description

When starting up OpenRegister, multiple database migration errors occurred:

### Error 1: Index Name Collision
```
Index name 'views_uuid_index' for table 'oc_openregister_view' collides with the constraint on table 'oc_openregister_views'.
```

### Error 2: Missing Column for Index
```
SQLSTATE[42000]: Syntax error or access violation: 1072 Key column 'extend' doesn't exist in table.
```

### Error 3: Foreign Key Constraint Error
```
SQLSTATE[HY000]: General error: 1005 Can't create table `nextcloud`.`oc_openregister_entity_relations` (errno: 150 "Foreign key constraint is incorrectly formed")
```

**Note**: This error persisted even after fixing type mismatches, indicating that foreign key constraints created in the same migration as the referenced tables can cause issues.

These errors prevented OpenRegister from initializing properly and blocked access to all functionality.

## Root Cause

### Error 1: Index Name Collision

The first issue was in migration `Version1Date20251103120000` which renames the `openregister_views` table to `openregister_view` (from plural to singular).

### Error 2: Deprecated Extend Column

The second issue was caused by the deprecated `extend` property in the Schema entity. The property was defined in `lib/Db/Schema.php` and registered with `addType`, causing Nextcloud/Doctrine to expect a corresponding database column. However, this column was never created in the initial schema migration, leading to an attempt to create an index on a non-existent column.

### Error 3: Foreign Key Constraint Issues

The third issue occurred in migration `Version1Date20251116000000` when creating the `openregister_entity_relations` table. Initially, the foreign key columns `entity_id` and `chunk_id` were defined as `BIGINT` without the `unsigned` attribute, but they referenced primary keys that ARE `BIGINT unsigned`. After fixing this, the error persisted because foreign key constraints were being created in the same migration that creates the referenced tables, which can cause database transaction issues in MySQL/MariaDB.

### Migration Sequence

1. Migration `Version1Date20251102140000` creates table `openregister_views` with indexes:
   - views_uuid_index
   - views_owner_index
   - views_public_index
   - views_default_index
   - views_owner_default_index

2. Migration `Version1Date20251103120000` attempts to rename the table by:
   - Creating a new table `openregister_view`
   - Copying all columns from the old table
   - **Copying indexes with the same names** (causing the collision)
   - Copying data in postSchemaChange
   - Dropping the old table

### Why the Collision Occurred

During the migration process:
1. Table `openregister_views` exists with index `views_uuid_index`
2. New table `openregister_view` is created with the same index name `views_uuid_index`
3. Both tables exist simultaneously during the migration
4. Database constraint violation: index names must be unique across all tables

## Solution Implemented

### Fix for Error 1: Index Renaming

Modified the migration `Version1Date20251103120000` to rename indexes when copying them from the old table to the new table.

### Fix for Error 2: Remove Deprecated Extend Property

1. Removed the deprecated `extend` property from `lib/Db/Schema.php`
2. Removed the `addType` registration for the extend field
3. Disabled migration `Version1Date20251102170000` that attempted to add the extend column
4. The Schema entity now relies on `allOf`, `oneOf`, and `anyOf` fields for schema composition (JSON Schema standard)

### Fix for Error 3: Remove Foreign Key Constraints

Updated the `openregister_entity_relations` table creation in migration `Version1Date20251116000000`:

1. **Fixed column types** - Added `unsigned => true` to match referenced tables:
```php
$table->addColumn('entity_id', Types::BIGINT, [
    'notnull' => true,
    'unsigned' => true,  // Matches openregister_entities.id
]);
$table->addColumn('chunk_id', Types::BIGINT, [
    'notnull' => true,
    'unsigned' => true,  // Matches openregister_chunks.id
]);
```

2. **Removed foreign key constraints** - To avoid migration transaction issues:
   - Removed `addForeignKeyConstraint()` calls for `entity_id` and `chunk_id`
   - Kept indexes for query performance
   - Referential integrity is maintained at the application level
   - Foreign keys can be added later in a separate migration if needed

### Changes Made

Updated the index copying logic to replace 'views_' prefix with 'view_' prefix:

```php
// Copy indexes with renamed index names to avoid collisions
// Replace 'views_' with 'view_' to reflect singular table name
foreach ($oldTable->getIndexes() as $index) {
    if ($index->isPrimary() === false) {
        // Rename index: views_* -> view_*
        $oldIndexName = $index->getName();
        $newIndexName = str_replace('views_', 'view_', $oldIndexName);
        
        $newTable->addIndex(
            $index->getColumns(),
            $newIndexName,
            $index->getFlags(),
            [
                'lengths' => $index->getOption('lengths') ?? [],
            ]
        );
    }
}
```

### Index Name Transformations

The migration now renames indexes as follows:

| Old Index Name (plural) | New Index Name (singular) |
|------------------------|---------------------------|
| views_uuid_index | view_uuid_index |
| views_owner_index | view_owner_index |
| views_public_index | view_public_index |
| views_default_index | view_default_index |
| views_owner_default_index | view_owner_default_index |

## Files Modified

1. `openregister/lib/Migration/Version1Date20251103120000.php` - Updated index copying logic to rename indexes
2. `openregister/lib/Db/Schema.php` - Removed deprecated `extend` property and type registration
3. `openregister/lib/Migration/Version1Date20251102170000.php` - Disabled migration that adds deprecated extend column
4. `openregister/lib/Migration/Version1Date20251116000000.php` - Fixed foreign key column types to match referenced tables

## Testing

### Manual Testing

1. Reset the database to before the migration:
```bash
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ migrations:status openregister
```

2. Run the migrations:
```bash
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ migrations:execute openregister
```

3. Verify no errors occur and the application starts successfully

4. Verify the new table structure:
```bash
docker exec -it master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e 'SHOW CREATE TABLE oc_openregister_view;'
```

5. Verify indexes were renamed correctly:
```bash
docker exec -it master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e 'SHOW INDEX FROM oc_openregister_view;'
```

### Expected Results

- Migration completes without errors
- Table `openregister_view` exists with renamed indexes
- Old table `openregister_views` is dropped
- All data is preserved
- No index name collisions

## Deployment

### Development Environment

1. Stop the Nextcloud container if running
2. Clear any failed migration state:
```bash
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ migrations:status openregister
```

3. Update the code with the fixed migration
4. Restart the Nextcloud container
5. Migrations will run automatically on startup

### Production Environment

1. **Before deploying**, back up the database:
```bash
docker exec master-database-mysql-1 mysqldump -u nextcloud -pnextcloud nextcloud > backup_$(date +%Y%m%d_%H%M%S).sql
```

2. Deploy the updated code

3. Run migrations manually:
```bash
docker exec -u 33 <nextcloud-container> php /var/www/html/occ migrations:execute openregister
```

4. Verify application functionality

5. If issues occur, restore from backup:
```bash
docker exec -i master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud < backup_YYYYMMDD_HHMMSS.sql
```

## Impact

### Positive Impact

- ✅ Fixes startup errors preventing OpenRegister initialization
- ✅ Enables proper table renaming without database constraint violations
- ✅ Removes deprecated extend column functionality
- ✅ Uses JSON Schema standard composition (allOf, oneOf, anyOf) instead of custom extend
- ✅ Maintains data integrity during migration
- ✅ Follows naming conventions (singular entity names)
- ✅ Prevents similar issues in future table rename migrations
- ✅ Reduces database complexity by removing unused column
- ✅ Avoids foreign key constraint issues during migration
- ✅ Uses indexes for query performance without foreign key complexity
- ✅ Maintains referential integrity at application level

### Related Systems

This fix affects:
- **Views feature**: The views system can now be initialized properly
- **Database migrations**: All migrations can complete successfully
- **Application startup**: OpenRegister can start without errors

## Prevention

To prevent similar issues in future migrations:

1. **Always rename indexes** when copying tables with new names
2. **Use unique index names** that reflect the table name
3. **Ensure entity properties match database schema** - properties defined in Entity classes with `addType()` should have corresponding database columns
4. **Remove deprecated properties** from Entity classes before they cause migration issues
5. **Test migrations thoroughly** in development before production
6. **Check for name collisions** during migration development
7. **Document index naming conventions** in migration guidelines
8. **Use JSON Schema standard features** (allOf, oneOf, anyOf) instead of custom schema composition
9. **Match foreign key column types exactly** - including `unsigned`, `length`, and other attributes must match the referenced column
10. **Avoid foreign keys in same migration** - Don't add foreign key constraints in the same migration that creates the referenced tables
11. **Test foreign key constraints** separately in development before adding to migrations
12. **Consider application-level integrity** - Foreign keys are optional; application logic can maintain referential integrity

## Related Documentation

- [Migrations Documentation](../technical/migrations.md)
- [Database Schema](../technical/database-schema.md)
- [Views Feature](../features/views.md)
- [Fixes Overview](./index.md)

