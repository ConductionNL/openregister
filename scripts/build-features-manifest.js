#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * scripts/build-features-manifest.js
 *
 * Local manifest builder for the `add-features-roadmap-menu` pilot. Mirrors
 * the contract documented for the (yet-to-be-published) `@conduction/openspec-manifest`
 * package so the pilot is exercised end-to-end from this repo:
 *
 *   - discovers `openspec/specs/<slug>/spec.md` (falls back to `specs/<slug>/spec.md`)
 *   - reads YAML frontmatter; includes only `status: implemented` or `status: reviewed`
 *   - extracts first H1 → `title`; first paragraph under `## Purpose` → `summary`
 *   - resolves `docsUrl`: explicit frontmatter wins (https-only); otherwise
 *     `https://<host>/<owner>/<repo>/blob/<default-branch>/openspec/specs/<slug>/spec.md`
 *     derived from `git config remote.origin.url` + the SCM default-branch ref
 *   - sorts alphabetically by title (locale-aware, case-insensitive)
 *   - emits `docs/features.json` with shape
 *     `{ schemaVersion: 1, generatedAt: <ISO>, features: [...] }`
 *
 * Once `@conduction/openspec-manifest` ships, this script is to be replaced
 * with the `openspec-manifest build` CLI invocation. The output shape is
 * identical so the swap is transparent to downstream consumers.
 *
 * Usage:
 *   node scripts/build-features-manifest.js [--cwd <path>] [--check]
 *
 *   --check   Build + diff against the committed `docs/features.json`. Non-zero
 *             exit if drift is detected (for the CI `--exit-code` step).
 */

'use strict'

const fs = require('fs')
const path = require('path')
const { execSync } = require('child_process')

function parseArgs(argv) {
	const args = { cwd: process.cwd(), check: false }
	for (let i = 2; i < argv.length; i += 1) {
		if (argv[i] === '--cwd' && argv[i + 1]) {
			args.cwd = path.resolve(argv[i + 1])
			i += 1
		} else if (argv[i] === '--check') {
			args.check = true
		}
	}
	return args
}

function findSpecDir(cwd) {
	const candidates = [path.join(cwd, 'openspec', 'specs'), path.join(cwd, 'specs')]
	const present = candidates.filter((c) => fs.existsSync(c))
	if (present.length === 0) {
		return null
	}
	if (present.length === 2) {
		console.warn(
			`[build-features-manifest] both ./openspec/specs and ./specs exist; preferring ./openspec/specs`,
		)
	}
	return present[0]
}

function parseFrontmatter(content) {
	const match = content.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/)
	if (!match) {
		return { meta: null, body: content }
	}
	const meta = {}
	for (const line of match[1].split(/\r?\n/)) {
		const kv = line.match(/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/)
		if (kv) {
			meta[kv[1]] = kv[2].trim().replace(/^['"]|['"]$/g, '')
		}
	}
	return { meta, body: match[2] }
}

function extractTitle(body) {
	const m = body.match(/^\s*#\s+(.+?)\s*$/m)
	return m ? m[1].trim() : null
}

function extractSummary(body) {
	const idx = body.search(/^##\s+Purpose\s*$/m)
	if (idx === -1) return null
	const after = body.slice(idx).replace(/^##\s+Purpose\s*$/m, '')
	const paragraphs = after.split(/\r?\n\s*\r?\n/)
	for (const p of paragraphs) {
		const trimmed = p.trim()
		if (!trimmed) continue
		if (trimmed.startsWith('@e2e') || trimmed.startsWith('@spec')) continue
		if (trimmed.startsWith('**Source**')) continue
		if (/^#/.test(trimmed)) break
		return trimmed.replace(/\s+/g, ' ')
	}
	return null
}

function resolveGitRemote(cwd) {
	try {
		const url = execSync('git config --get remote.origin.url', {
			cwd,
			stdio: ['ignore', 'pipe', 'ignore'],
		})
			.toString()
			.trim()
		const sshMatch = url.match(/^git@([^:]+):(.+?)(?:\.git)?$/)
		const httpsMatch = url.match(/^https?:\/\/([^/]+)\/(.+?)(?:\.git)?$/)
		const m = sshMatch || httpsMatch
		if (!m) return null
		const [, host, repoPath] = m
		const parts = repoPath.split('/')
		if (parts.length < 2) return null
		const owner = parts[parts.length - 2]
		const repo = parts[parts.length - 1]
		return { host, owner, repo }
	} catch (_) {
		return null
	}
}

function resolveDefaultBranch(cwd) {
	try {
		const out = execSync('git symbolic-ref refs/remotes/origin/HEAD', {
			cwd,
			stdio: ['ignore', 'pipe', 'ignore'],
		})
			.toString()
			.trim()
		const m = out.match(/refs\/remotes\/origin\/(.+)$/)
		return m ? m[1] : null
	} catch (_) {
		return null
	}
}

function buildDocsUrl({ frontmatterUrl, slug, host, owner, repo, branch }) {
	if (frontmatterUrl) {
		try {
			const u = new URL(frontmatterUrl)
			if (u.protocol !== 'https:' || !u.hostname) {
				console.warn(
					`[build-features-manifest] spec ${slug}: docsUrl frontmatter rejected (not https or empty host) — falling back`,
				)
			} else {
				return frontmatterUrl
			}
		} catch (_) {
			console.warn(
				`[build-features-manifest] spec ${slug}: docsUrl frontmatter is not a valid URL — falling back`,
			)
		}
	}
	if (!host || !owner || !repo || !branch) {
		return null
	}
	return `https://${host}/${owner}/${repo}/blob/${branch}/openspec/specs/${slug}/spec.md`
}

function build(cwd) {
	const specsDir = findSpecDir(cwd)
	if (!specsDir) {
		return {
			schemaVersion: 1,
			generatedAt: new Date().toISOString(),
			features: [],
		}
	}
	const remote = resolveGitRemote(cwd)
	const branch = resolveDefaultBranch(cwd)
	const entries = fs
		.readdirSync(specsDir, { withFileTypes: true })
		.filter((e) => e.isDirectory())
	const features = []
	for (const entry of entries) {
		const slug = entry.name
		const specPath = path.join(specsDir, slug, 'spec.md')
		if (!fs.existsSync(specPath)) continue
		const raw = fs.readFileSync(specPath, 'utf8')
		const { meta, body } = parseFrontmatter(raw)
		if (!meta || !meta.status) {
			console.warn(
				`[build-features-manifest] spec ${slug}: missing frontmatter or status — skipping`,
			)
			continue
		}
		if (meta.status !== 'implemented' && meta.status !== 'reviewed') {
			continue
		}
		const title = extractTitle(body)
		const summary = extractSummary(body)
		if (!title || !summary) {
			console.warn(
				`[build-features-manifest] spec ${slug}: missing title or purpose — skipping`,
			)
			continue
		}
		const docsUrl = buildDocsUrl({
			frontmatterUrl: meta.docsUrl,
			slug,
			host: remote?.host,
			owner: remote?.owner,
			repo: remote?.repo,
			branch,
		})
		features.push({ slug, title, summary, ...(docsUrl ? { docsUrl } : {}) })
	}
	features.sort((a, b) =>
		a.title.localeCompare(b.title, undefined, { sensitivity: 'base' }),
	)
	return { schemaVersion: 1, generatedAt: new Date().toISOString(), features }
}

function main() {
	const args = parseArgs(process.argv)
	const manifest = build(args.cwd)
	const outPath = path.join(args.cwd, 'docs', 'features.json')
	const serialized = JSON.stringify(manifest, null, 2) + '\n'
	if (args.check) {
		const existing = fs.existsSync(outPath)
			? fs.readFileSync(outPath, 'utf8')
			: ''
		const existingNoTimestamp = existing.replace(
			/"generatedAt"\s*:\s*"[^"]+"/,
			'"generatedAt":"<ignored>"',
		)
		const newNoTimestamp = serialized.replace(
			/"generatedAt"\s*:\s*"[^"]+"/,
			'"generatedAt":"<ignored>"',
		)
		if (existingNoTimestamp === newNoTimestamp) {
			console.log(
				'[build-features-manifest] docs/features.json is up-to-date.',
			)
			process.exit(0)
		}
		console.error(
			'[build-features-manifest] DRIFT detected — run `node scripts/build-features-manifest.js` and commit the result.',
		)
		process.exit(1)
	}
	fs.mkdirSync(path.dirname(outPath), { recursive: true })
	fs.writeFileSync(outPath, serialized)
	console.log(
		`[build-features-manifest] wrote ${manifest.features.length} features to ${path.relative(args.cwd, outPath)}`,
	)
}

if (require.main === module) {
	main()
}

module.exports = {
	build,
	parseFrontmatter,
	extractTitle,
	extractSummary,
	buildDocsUrl,
}
