# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in any Conduction Nextcloud app, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email us at: **security@conduction.nl**

Include the following in your report:

- Description of the vulnerability
- Steps to reproduce the issue
- Potential impact
- Suggested fix (if any)

## Response Timeline

- **Acknowledgement:** Within 48 hours of receiving your report
- **Initial assessment:** Within 1 week
- **Fix and disclosure:** We aim to resolve critical vulnerabilities within 30 days

## Supported Versions

We provide security updates for the latest stable release of each app. Older versions may not receive security patches.

## Scope

This security policy applies to all repositories under the [ConductionNL](https://github.com/ConductionNL) organization.

## Recognition

We appreciate responsible disclosure and will credit reporters (with permission) in our release notes.

## Software Bill of Materials (SBOM)

We publish a [CycloneDX](https://cyclonedx.org/) 1.5 JSON SBOM for every release of every Conduction Nextcloud app. The SBOM lists every production dependency (Composer + npm, merged, dev-dependencies excluded) with name, version, license, and PURL. Each SBOM is CVE-scanned with [Grype](https://github.com/anchore/grype) at build time and the release fails if any **critical** vulnerability is detected.

### Stable URLs

For every app `<app>` under [ConductionNL](https://github.com/ConductionNL), two URLs always work:

| Use case                                                           | URL pattern                                                                    |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| **Always-latest released SBOM** (auto-redirects to newest release) | `https://github.com/ConductionNL/<app>/releases/latest/download/sbom.cdx.json` |
| **Specific release SBOM** (pinned, for compliance archives)        | `https://github.com/ConductionNL/<app>/releases/download/<tag>/sbom.cdx.json`  |

Example — fetch the latest mydash SBOM:

```bash
curl -sL https://github.com/ConductionNL/mydash/releases/latest/download/sbom.cdx.json | jq .
```

Example — fetch the SBOM for a specific historical release:

```bash
curl -sL https://github.com/ConductionNL/mydash/releases/download/v1.0.0/sbom.cdx.json | jq .
```

### Update cadence

A new SBOM is generated and attached on every release tag. We do not commit SBOMs into the repository tree — they are published exclusively as release assets to keep main-branch history clean and to guarantee every SBOM corresponds to an immutable release artifact.

### Format

- **Specification:** CycloneDX 1.5
- **Encoding:** JSON
- **Filename:** `sbom.cdx.json` (consistent across all apps)
- **Scope:** Production dependencies only — `--omit=dev` for both Composer (`composer CycloneDX:make-sbom`) and npm (`@cyclonedx/cyclonedx-npm`). Composer plugins are also omitted.

### Verification before publication

Each release SBOM passes through these gates before it ships:

1. **Grype CVE scan** — `--fail-on critical` against the SBOM itself.
2. **`composer audit`** — informational, captured in CI logs.
3. **`npm audit --audit-level=critical`** — informational, captured in CI logs.

If any of these block, the release is held until the underlying issue is patched.

### Workflow artifact (CI-only)

A 90-day workflow artifact named `sbom-<app>` is also produced on every successful CI run on `main` / `beta` / `development`. This is for internal audit / replay only — external consumers should always use the release-asset URLs above for stable, version-pinned access.

### Reporting SBOM-related issues

If you spot a missing dependency, an incorrect version, or a CVE we should be alerted to, email `security@conduction.nl` per the disclosure process at the top of this document.
