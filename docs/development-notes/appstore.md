# Nextcloud App Store Caching

When apps are published to the Nextcloud App Store, they appear immediately in the store's web UI at apps.nextcloud.com. However, Nextcloud instances may take up to 1 hour to see the new or updated app. This document explains why this happens and how to work around it.

## Why the Delay?

The delay is caused by **client-side caching** on each Nextcloud instance, not by the app store itself.

When a Nextcloud instance fetches the app list from the store, it caches the response locally. Subsequent requests to Settings > Apps will use this cached data until it expires.

## Cache Duration (TTL)

The caching behavior is defined in `lib/private/App/AppStore/Fetcher/Fetcher.php`:

| Data Type | TTL | Description |
|-----------|-----|-------------|
| App list (stable) | 3600 seconds (1 hour) | Main app catalog |
| App list (unstable) | 900 seconds (15 minutes) | Beta/unstable releases |
| Discover section | 86400 seconds (24 hours) | Featured apps on the discover page |
| Retry after failure | 300 seconds (5 minutes) | Delay before retrying failed requests |

```php
public const INVALIDATE_AFTER_SECONDS = 3600;           // 1 hour
public const INVALIDATE_AFTER_SECONDS_UNSTABLE = 900;   // 15 minutes
```

## How the Caching Works

1. When a user opens **Settings > Apps**, Nextcloud checks the cached `apps.json` file
2. If the cache timestamp is less than 3600 seconds old, the cached data is returned immediately
3. If the cache has expired, a new request is made to `https://apps.nextcloud.com/api/v1/apps.json`
4. Nextcloud uses **ETag headers** for conditional requests - if the server returns 304 Not Modified, the cached data is refreshed without re-downloading
5. The cache files are stored in `data/appdata_<instance-id>/appstore/`

## Cache Invalidation

The cache is automatically invalidated when:

- The TTL expires (1 hour for stable apps)
- The Nextcloud version changes (version-aware caching)
- The cache files are manually deleted

## Force Refresh the App Store Cache

There is **no built-in OCC command** to clear the app store cache. The `occ app:update --showonly` command still respects the cache TTL.

To immediately see newly published apps without waiting for the cache to expire:

### Option 1: Use the OpenRegister Settings UI

In OpenRegister's settings page, click the **"Clear App Store Cache"** button in the Version Information section. This invalidates the cache and forces a fresh fetch on the next visit to Settings > Apps.

### Option 2: Use the OpenRegister API

OpenRegister provides an API endpoint to invalidate the app store cache:

```bash
# Invalidate the main apps.json cache (default)
curl -X DELETE "https://your-nextcloud/apps/openregister/api/settings/cache/appstore"

# Invalidate a specific cache type
curl -X DELETE "https://your-nextcloud/apps/openregister/api/settings/cache/appstore" \
  -H "Content-Type: application/json" \
  -d '{"type": "apps"}'

# Invalidate all app store caches (apps, categories, discover)
curl -X DELETE "https://your-nextcloud/apps/openregister/api/settings/cache/appstore" \
  -H "Content-Type: application/json" \
  -d '{"type": "all"}'
```

Available cache types:
- `apps` - Main app catalog (default)
- `categories` - App categories
- `discover` - Featured/discover section
- `all` - All app store cache files

**How it works:** Instead of deleting the cache files (which can cause permission issues), this sets the cached timestamp to 0, making the cache appear expired. Nextcloud's Fetcher will then fetch fresh data on the next request.

### Option 3: Delete the cache files manually (not recommended)

```bash
# Find and delete the cached apps.json
rm -rf data/appdata_*/appstore/apps.json
```

**Warning:** Deleting cache files can cause permission errors if the web server cannot recreate them in the apps directory.

### Option 4: Wait for TTL expiration

Simply wait up to 1 hour for the cache to naturally expire.

## Background Update Checks

In addition to on-demand fetching, Nextcloud has a background job that checks for app updates:

- **File:** `apps/updatenotification/lib/BackgroundJob/UpdateAvailableNotifications.php`
- **Interval:** Once per day (86400 seconds)
- **Function:** Checks both core updates and app updates

This background job uses the same cached data and TTL as the Settings UI.

## Configuration Options

These `config.php` settings control app store behavior:

| Setting | Default | Description |
|---------|---------|-------------|
| `appstoreenabled` | `true` | Enable/disable the app store entirely |
| `appstoreurl` | `https://apps.nextcloud.com/api/v1` | App store API URL |
| `appstore-timeout` | `120` | HTTP request timeout in seconds |

## Summary

- New apps appear immediately on apps.nextcloud.com (direct database query)
- Nextcloud instances cache the app list for **1 hour** by default
- You can force a refresh by deleting `data/appdata_*/appstore/apps.json`
- This is normal behavior to reduce load on both the app store and your server
