# OpenRegister Integration Tests

## Quick Start

### Run Tests in Both Storage Modes

```bash
# Automatically tests both normal storage AND magic mapper
./run-dual-storage-tests.sh
```

This script runs the Newman collection twice:
1. Normal storage (objects in JSON blob table)
2. Magic mapper (objects in dedicated SQL tables)

### Run Single Mode

```bash
# Normal storage only
docker exec -u 33 nextcloud newman run \
  /var/www/html/custom_apps/openregister/tests/integration/openregister-crud.postman_collection.json \
  --reporters cli

# Magic mapper only
docker exec -u 33 -e ENABLE_MAGIC_MAPPER=true nextcloud newman run \
  /var/www/html/custom_apps/openregister/tests/integration/openregister-crud.postman_collection.json \
  --reporters cli
```

## 📚 Documentation

All documentation is **in the Postman collection itself**!

### View in Postman

1. Import `openregister-crud.postman_collection.json` into Postman
2. Click on the collection name in the sidebar
3. View the **Description** tab

You'll see complete documentation including:
- Dual storage testing explanation
- How to add new tests
- Golden rules (Do's & Don'ts)
- Common pitfalls
- Examples

### View in Newman Output

```bash
newman run openregister-crud.postman_collection.json --reporters cli
```

The collection description is shown at the start of the run.

### View in CLI

```bash
# Extract and view the description
cat openregister-crud.postman_collection.json | jq -r '.info.description'
```

## Files

- `openregister-crud.postman_collection.json` - Main test collection (with full docs in description)
- `run-dual-storage-tests.sh` - Smart runner for dual storage testing
- `test-import.csv` - Test data for import/export tests

## Expected Results

Both storage modes should pass all tests:

```
╔═════════════════════════╦══════════╦══════════╗
║ Storage Mode            ║ Tests    ║ Failures ║
╠═════════════════════════╬══════════╬══════════╣
║ 📦 Normal (JSON blob)   ║ 199      ║ 0        ║
║ 🔮 Magic Mapper (SQL)   ║ 199      ║ 0        ║
╚═════════════════════════╩══════════╩══════════╝
```

If one mode fails → Storage compatibility bug!

## Why No Separate Docs?

Documentation is **in the collection description** because:
- ✅ Single source of truth
- ✅ Always up-to-date with tests
- ✅ Visible in Postman GUI
- ✅ Included in Newman output
- ✅ No separate files to maintain
- ✅ Can be version controlled together

**Want to read the docs?** Just open the collection in Postman! 📖
