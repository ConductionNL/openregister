# LLPhant Installation Guide

**Status:** Repository added to composer.json, requires GitHub authentication

## Current Status

✅ **Completed:**
- Added LLPhant GitHub repository to `composer.json`
- Added `theodo-group/llphant: dev-main` to requirements

❌ **Blocked:**
- Installation requires GitHub authentication in Composer

## Option 1: Configure GitHub Authentication (Recommended)

### Step 1: Generate GitHub Personal Access Token
1. Go to https://github.com/settings/tokens
2. Click "Generate new token (classic)"
3. Give it a name: "Composer LLPhant Access"
4. Select scopes: `repo` (Full control of private repositories)
5. Click "Generate token"
6. **Copy the token** (you won't see it again!)

### Step 2: Configure Composer Authentication
```bash
# In WSL/terminal
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
composer config github-oauth.github.com YOUR_GITHUB_TOKEN_HERE
```

### Step 3: Install LLPhant
```bash
composer update theodo-group/llphant
```

## Option 2: Use Environment Variable
```bash
export COMPOSER_AUTH='{"github-oauth": {"github.com": "YOUR_GITHUB_TOKEN_HERE"}}'
composer update theodo-group/llphant
```

## Option 3: Manual Installation (Alternative)

If GitHub authentication is problematic, you can:

1. **Clone LLPhant manually:**
```bash
cd /tmp
git clone https://github.com/theodo-group/LLPhant.git
cd LLPhant
composer install
```

2. **Copy to vendor directory:**
```bash
mkdir -p /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/vendor/theodo-group
cp -r /tmp/LLPhant /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/vendor/theodo-group/llphant
```

3. **Update autoloader:**
```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
composer dump-autoload
```

## Option 4: Use Alternative Library (Future)

If LLPhant proves difficult, we can use direct OpenAI API integration:
- Install `openai-php/client` instead
- Implement custom document loaders
- Use native PHP chunking logic

## Verification

After installation, verify LLPhant is available:

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
composer show theodo-group/llphant
```

Expected output should show version and path.

## Next Steps After Installation

1. Run database migration for vectors table
2. Create `VectorEmbeddingService`
3. Configure embedding providers (OpenAI, Ollama)
4. Start Phase 4: File processing

## Current composer.json Configuration

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/theodo-group/LLPhant"
        }
    ],
    "require": {
        "theodo-group/llphant": "dev-main"
    }
}
```

## Troubleshooting

### Error: "Could not authenticate against github.com"
- You need to set up GitHub authentication (see Option 1)
- Or use alternative installation methods

### Error: "Package not found"
- Check internet connection
- Verify GitHub repository URL is correct
- Try `composer clear-cache` first

### Error: "Your requirements could not be resolved"
- Check PHP version compatibility (requires PHP 8.1+)
- Check for conflicting dependencies
- Try `composer update --with-all-dependencies`

## References

- [LLPhant GitHub](https://github.com/theodo-group/LLPhant)
- [LLPhant Documentation](https://llphant.io/docs/get-started/)
- [Composer Authentication](https://getcomposer.org/doc/articles/authentication-for-private-packages.md)

