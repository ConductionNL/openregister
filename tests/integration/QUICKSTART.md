# Quick Start: Newman Tests

Get up and running with OpenRegister integration tests in 2 minutes! ⚡

## Prerequisites

- Newman installed: `npm install -g newman`
- Docker running (with `master-nextcloud-1` container)
- Or Nextcloud accessible at `http://localhost`

## Run Tests

```bash
cd tests/integration
./run-tests.sh --clean
```

That's it! 🎉

## Common Commands

```bash
# Run with defaults:
./run-tests.sh

# Force clean start (recommended for CI/CD):
./run-tests.sh --clean

# Run with custom URL:
./run-tests.sh --url http://nextcloud.local

# Run in CI mode:
./run-tests.sh --mode ci --clean

# Show help:
./run-tests.sh --help
```

## Using Make

```bash
# From openregister root directory:
make -f Makefile.newman test-clean
make -f Makefile.newman test-ci
make -f Makefile.newman test-verbose
```

## Expected Results

✅ **~176 tests passing** (89.8%)  
⚠️ **~20 tests may fail** on first run due to Newman variable persistence

**Solution**: Run twice or use `--clean` flag

## Troubleshooting

### Tests fail with "Schema not found"

```bash
# Use the clean flag:
./run-tests.sh --clean
```

### Container not found

```bash
# Check your container name:
docker ps | grep nextcloud

# Use the correct name:
./run-tests.sh --container your-container-name
```

### Newman not installed

```bash
npm install -g newman
newman --version
```

## CI/CD Integration

For GitHub Actions, tests run automatically. See `.github/workflows/newman-tests.yml`.

For other CI systems:

```bash
#!/bin/bash
# In your CI script:
cd tests/integration
npm install -g newman
./run-tests.sh --mode ci --clean
```

## Need More Help?

📖 Full documentation: [README.md](README.md)  
🐛 Issues: Check container logs with `docker logs master-nextcloud-1`  
💬 Questions: Refer to the OpenRegister documentation

---

**Pro Tip**: Always use `--clean` flag when developing new tests to ensure fresh state! 🚀



