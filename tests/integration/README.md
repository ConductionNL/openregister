# Newman Integration Tests

This directory contains Newman/Postman integration tests for the OpenRegister application.

## Quick Start

### Local Development

```bash
# Run tests with defaults (auto-detects Docker container):
./run-tests.sh

# Run with custom settings:
./run-tests.sh --url http://nextcloud.local --user admin --password secret

# Force clean start (clears all variables):
./run-tests.sh --clean

# Show all options:
./run-tests.sh --help
```

### Manual Newman Run

```bash
# Install Newman globally:
npm install -g newman

# Run the collection:
newman run openregister-crud.postman_collection.json \
  --env-var "base_url=http://localhost" \
  --env-var "admin_user=admin" \
  --env-var "admin_password=admin"
```

## Test Structure

The test collection covers:

1. **Core CRUD Operations** - Create, Read, Update, Delete for all entities
2. **Multitenancy** - Organization isolation and access control
3. **RBAC** - Role-based access control and permissions
4. **Schema Validation** - JSON schema validation and composition
5. **File Operations** - File uploads, downloads, and management
6. **Import/Export** - CSV import and data export
7. **Bulk Operations** - Batch operations for efficiency
8. **Conversation Management** - Chat and messaging features

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `NEXTCLOUD_URL` | `http://localhost` | Base URL for Nextcloud instance |
| `NEXTCLOUD_ADMIN_USER` | `admin` | Admin username |
| `NEXTCLOUD_ADMIN_PASSWORD` | `admin` | Admin password |
| `NEXTCLOUD_CONTAINER` | `master-nextcloud-1` | Docker container name |
| `RUN_MODE` | `local` | Run mode: `local` or `ci` |

### Script Options

```
OPTIONS:
    -h, --help              Show help message
    -u, --url URL           Base URL for Nextcloud
    -U, --user USER         Admin username
    -P, --password PASS     Admin password
    -c, --container NAME    Container name
    -m, --mode MODE         Run mode: local or ci
    -C, --clean             Force clean start
    -v, --verbose           Verbose output
```

## CI/CD Integration

The tests run automatically in GitHub Actions on:
- Push to `main` or `develop` branches
- Pull requests to `main` or `develop`
- Manual workflow dispatch

See `.github/workflows/newman-tests.yml` for the workflow configuration.

## Troubleshooting

### Tests Fail with "Schema not found" or "Object not found"

This can happen due to Newman variable persistence between runs. Solutions:

1. **Use the clean flag**:
   ```bash
   ./run-tests.sh --clean
   ```

2. **Run tests twice** (first run sets variables, second uses them):
   ```bash
   ./run-tests.sh && ./run-tests.sh
   ```

3. **Clear variables manually** using `jq`:
   ```bash
   jq '.variable = [.variable[] | if .key == "_test_run_initialized" then .value = "" else . end]' \
     openregister-crud.postman_collection.json > temp.json && \
     mv temp.json openregister-crud.postman_collection.json
   ```

### Container Not Found

If you get "Container not found" errors:

1. Check your container name:
   ```bash
   docker ps | grep nextcloud
   ```

2. Specify the correct container:
   ```bash
   ./run-tests.sh --container your-container-name
   ```

3. Run from host instead of container:
   ```bash
   newman run openregister-crud.postman_collection.json \
     --env-var "base_url=http://localhost" \
     --env-var "admin_user=admin" \
     --env-var "admin_password=admin"
   ```

### Newman Not Found

Install Newman globally:

```bash
npm install -g newman

# Verify installation:
newman --version
```

## Test Coverage

Current test statistics:
- **Total assertions**: 196
- **Passing tests**: ~176 (89.8%)
- **Test execution time**: ~30 seconds

## Development Guidelines

### Adding New Tests

1. Add your test to the appropriate folder in the collection
2. Use collection variables for dynamic data (UUIDs, slugs, etc.)
3. Add proper assertions for status codes and response data
4. Document the test purpose in the test description

### Variable Naming Convention

- `main_*` - Variables from the main test flow (register, schema)
- `file_test_*` - Variables specific to file operation tests
- `import_test_*` - Variables specific to import/export tests
- `test_timestamp` - Single timestamp for the entire test run

### Best Practices

1. ✅ Always check status codes first
2. ✅ Store important IDs in variables for reuse
3. ✅ Add descriptive test names
4. ✅ Use the `--clean` flag when developing new tests
5. ✅ Test both success and failure scenarios

## Support

For issues or questions:
- Check the troubleshooting section above
- Review the test collection comments
- Check application logs: `docker logs master-nextcloud-1`
- Consult the OpenRegister documentation

## License

EUPL-1.2 - See LICENSE file for details
