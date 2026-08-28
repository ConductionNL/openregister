# Run a local demo

This page gets a working OpenRegister running on your own machine in two commands. You end with a registry with registers and schemas you can model against.

It is a **demo**, not a development environment. Nothing is mounted from a checkout, and that is deliberate — see [What this is not](#what-this-is-not).

## What you need

Docker, with Compose v2.23 or newer. Nothing else — no PHP, no Node, no Nextcloud.

```bash
docker --version
docker compose version
```

If `docker compose version` prints v2.22 or older, upgrade first. The compose file declares its scripts inline via `configs`, and older versions ignore the `content:` field **silently** — which produces an instance with no apps installed and nothing in the logs to explain why.

## Step 1 — get the compose file

```bash
curl -fsSLO https://raw.githubusercontent.com/ConductionNL/openregister/development/openregister-compose.yaml
```

A single self-contained file. There is nothing else to fetch and nothing to edit.

## Step 2 — start it

```bash
docker compose -f openregister-compose.yaml up -d
```

The first run takes a few minutes: it pulls three images and downloads the application archives. Watch it work if you like:

```bash
docker compose -f openregister-compose.yaml logs -f app-installer
```

You are looking for:

```
==> installing openregister <version>
==> installing thematiq <version>
==> installing integriq <version>
==> apps present: integriq openregister thematiq
```

Then Nextcloud installs itself and enables the apps **in dependency order**. OpenRegister goes first: it owns the registers and schemas the others declare against, and a leaf app enabled before it finds no register to attach to.

That is done when this returns `"installed":true`:

```bash
curl -s http://localhost:8601/status.php
```

## Step 3 — open the demo

| What | Where |
| --- | --- |
| **OpenRegister** | [http://localhost:8601/apps/openregister/](http://localhost:8601/apps/openregister/) |
| Admin interface | [http://localhost:8601](http://localhost:8601) — `admin` / `admin` |

## What gets installed, and why more than one app

| App | Why |
| --- | --- |
| `openregister` | **Required.** Every Connext app declares its registers and schemas against OpenRegister. |
| `thematiq` | Optional. Government theming. Absent, the UI renders unthemed rather than wrong. |
| `integriq` | Optional. The connector, for feeding in data from systems you do not control. |
| `openregister` | The app this page is about. |

That OpenRegister dependency is **not declared** in `appinfo/info.xml` — no app in the fleet declares an `<app>` dependency — so nothing stops the App Store from installing openregister without it. It would then load, find no register to attach to, and show you an empty app rather than an error. The compose file encodes the dependency the manifest does not.

## Verifying it actually worked

A page loading is not the same as a page working. Nextcloud serves its shell before the app decides whether it has anything to render, so an app URL returns HTTP 200 even when it resolves to nothing at all. A smoke test that checks for a 200 would call that a success.

Check content instead:

```bash
# The app answers. Note the credentials: an app page requires a login, so the
# SAME request without -u returns 401, which is not a broken demo — measured
# on a booted demo while writing this page.
curl -s -o /dev/null -w '%{http_code}\n' -u admin:admin -L "http://localhost:8601/apps/openregister/"

# OpenRegister has registers — an empty list means the configuration
# was never imported, which is not the same as "nothing configured yet"
curl -s -u admin:admin "http://localhost:8601/apps/openregister/api/registers" | head -c 300
```

## Changing the defaults

The port and every version are overridable:

```bash
DEMO_PORT=9000 \
OPENREGISTER_VERSION=1.2.3 \
docker compose -f openregister-compose.yaml up -d
```

Leaving a version empty resolves the newest release for that app, pre-releases included — which is what most Connext apps still ship, so that is the default.

## Tearing it down

```bash
# Stop, keep the data
docker compose -f openregister-compose.yaml down

# Stop and delete everything, including the database
docker compose -f openregister-compose.yaml down -v
```

## What this is not

**It is not a development environment, and it cannot be turned into one by adding a bind mount.**

Nextcloud installs and updates an app by deleting the app directory and extracting a fresh archive over it. Point that at a checkout and an app-store update will delete your working tree — measured on a development machine on 27 August 2026, where `\OC\Updater::upgradeAppStoreApp` fired on a container restart and removed every top-level file from a bind-mounted checkout, including its `.git` directory. Only the subdirectories it lacked permission to unlink survived.

So this compose keeps its apps in a named volume and installs them from release archives. That also happens to be the only thing that works: a release archive is a **complete** app carrying `vendor/` and the built `js/` bundle, while a `git clone` carries neither — and a Nextcloud app with no `vendor/` does not fail loudly. It warns once and keeps loading, so the app appears installed while every service that needs a dependency is quietly absent.

To work *on* these apps rather than *with* them, use the development environment instead.

## Troubleshooting

**`app-installer` exits non-zero.** It could not download an archive. Check the log for the URL it tried; the most common cause is a pinned version with no matching release.

**It stops with `openregister missing; aborting`.** Deliberate. Every other app declares registers against OpenRegister, so a stack without it would start and then fail in a dozen confusing ways instead of one clear one.

**The UI renders unthemed.** Thematiq is not installed or not enabled. Expected, and cosmetic — the theme resolver renders unthemed rather than wrong when it is absent.

**Everything returns 404 or a maintenance page after a restart.** Nextcloud is waiting for an upgrade. Run `docker compose -f openregister-compose.yaml exec -u www-data nextcloud php occ upgrade`.
