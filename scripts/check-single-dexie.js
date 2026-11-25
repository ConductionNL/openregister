#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-single-dexie.js: the Dexie singleton guard.
//
// WHY THIS EXISTS
//
//   Dexie refuses to initialise twice in one page: when a second copy loads
//   at a different version it throws "Two different versions of Dexie loaded
//   in the same app" at module init, before the SPA mounts. Nextcloud loads
//   this app's integration-global script on EVERY page of the instance, next
//   to whichever app's own bundles, so a version drift between any two built
//   chunks blanks every app SPA on the instance. That is not hypothetical:
//   the 2026-09-01 acceptance run on a clean rig found integration-global at
//   dexie 4.4.5 beside vendor chunks at 4.4.4, and every app rendered as
//   bare chrome. Two things have to hold, and this script checks both:
//
//     1. every built chunk that embeds a Dexie copy embeds the SAME version;
//     2. that version is the one package-lock.json resolves, so a stale
//        chunk left over from an earlier build (or a dependency that vendors
//        its own copy, as @conduction/nextcloud-vue did before its build
//        externalised dexie) cannot ship unnoticed.
//
// HOW IT DETECTS A COPY
//
//   Dexie's own duplicate check ships in every copy of the library, so a
//   built chunk embeds Dexie exactly when it contains the error string
//   "Two different versions of Dexie". Inside such a chunk the version
//   literal survives minification as `semVer:"x.y.z"` (Dexie.semVer). From
//   dexie 4.4.7 on the library hoists it into a constant (DEXIE_VERSION), so
//   minified it reads `semVer:Jt` with `Jt="4.4.7"` elsewhere in the chunk;
//   chunkVersions() follows that identifier to its string literal. These
//   markers were verified against the released production bundles.
//
// WHEN IT RUNS
//
//   As `postbuild`, so it runs wherever `npm run build` runs: locally, in
//   code quality CI, and in the release build that packages js/ into the
//   App Store tarball. Standalone via `npm run check:dexie`. When js/ does
//   not exist yet it skips loudly instead of failing, matching the
//   check-integration-parity.sh convention.
//
// Exit codes:
//   0: zero or one Dexie version across js/, matching the lockfile
//   1: two or more versions, or a version the lockfile does not resolve

const fs = require('fs')
const path = require('path')

const repoRoot = path.join(__dirname, '..')
const jsDir = path.join(repoRoot, 'js')

const SENTINEL = 'Two different versions of Dexie'
const SEMVER_RE = /semVer\s*[:=]\s*["']([0-9][0-9A-Za-z.+-]*)["']/g
const SEMVER_IDENT_RE = /semVer\s*:\s*([A-Za-z_$][\w$]*)\s*[,}]/g

/**
 * The Dexie versions a built chunk carries: every `semVer:"x.y.z"` literal,
 * and for every `semVer:<identifier>` the string literal that identifier is
 * assigned in the same chunk.
 *
 * @param {string} text The chunk source
 * @return {Set<string>} The versions found
 */
function chunkVersions(text) {
	const versions = new Set()
	for (const m of text.matchAll(SEMVER_RE)) {
		versions.add(m[1])
	}
	for (const m of text.matchAll(SEMVER_IDENT_RE)) {
		const ident = m[1].replace(/\$/g, '\\$')
		const assign = new RegExp(`(?:^|[^\\w$.])${ident}\\s*=\\s*["']([0-9][0-9A-Za-z.+-]*)["']`, 'g')
		for (const a of text.matchAll(assign)) {
			versions.add(a[1])
		}
	}
	return versions
}

module.exports = { chunkVersions }

/**
 * Check js/ and exit 0 or 1 (see the header).
 *
 * @return {void}
 */
function main() {
	if (!fs.existsSync(jsDir)) {
		console.log(
			'i dexie singleton: js/ not built yet, skipping (run npm run build first)',
		)
		process.exit(0)
	}

	let expected = null
	try {
		const lock = JSON.parse(
			fs.readFileSync(path.join(repoRoot, 'package-lock.json'), 'utf8'),
		)
		expected =
			(lock.packages
				&& lock.packages['node_modules/dexie']
				&& lock.packages['node_modules/dexie'].version)
			|| null
	} catch (e) {
		console.log(
			`i dexie singleton: could not read package-lock.json (${e.message}); checking chunk agreement only`,
		)
	}

	const findings = []
	for (const name of fs.readdirSync(jsDir).sort()) {
		if (!name.endsWith('.js')) {
			continue
		}
		const text = fs.readFileSync(path.join(jsDir, name), 'utf8')
		if (!text.includes(SENTINEL)) {
			continue
		}
		const versions = chunkVersions(text)
		if (versions.size === 0) {
			// A chunk carries Dexie's error string but no recognisable version
			// literal. The marker contract changed, so the guard can no longer
			// see, and a guard that cannot see must say so rather than pass.
			console.error(
				`x dexie singleton: js/${name} embeds Dexie (sentinel found) but no semVer literal matched; update SEMVER_RE in ${path.basename(__filename)}`,
			)
			process.exit(1)
		}
		for (const v of versions) {
			findings.push({ file: name, version: v })
		}
	}

	if (findings.length === 0) {
		console.log('+ dexie singleton: no built chunk embeds Dexie')
		process.exit(0)
	}

	const distinct = [...new Set(findings.map((f) => f.version))].sort()

	for (const f of findings) {
		console.log(`  js/${f.file}: dexie ${f.version}`)
	}

	if (distinct.length > 1) {
		console.error(
			`x dexie singleton: ${distinct.length} different Dexie versions in one chunk set (${distinct.join(', ')}). Loading any two of these chunks in one page throws at module init and the SPA never mounts. Rebuild from a clean js/ with a single resolved dexie.`,
		)
		process.exit(1)
	}

	if (expected && distinct[0] !== expected) {
		console.error(
			`x dexie singleton: built chunks carry dexie ${distinct[0]} but package-lock.json resolves ${expected}. A chunk is stale, or a dependency vendors its own copy. Rebuild from a clean js/.`,
		)
		process.exit(1)
	}

	console.log(
		`+ dexie singleton: one Dexie version (${distinct[0]}) across ${findings.length} chunk(s)${expected ? ', matching the lockfile' : ''}`,
	)
}

if (require.main === module) {
	main()
}
