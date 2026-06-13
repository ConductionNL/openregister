#!/usr/bin/env node
/**
 * l10n extraction / drift check.
 *
 * Scans the frontend source for translation calls — t('<app>', '...'),
 * n('<app>', '...', '...', n) and the $t/$n template variants — and asserts
 * every literal source string is present as a key in l10n/en.json.
 *
 * This is the i18n equivalent of the Nextcloud `l10n` extraction step: it
 * guarantees l10n/en.json can never silently drift from the t() calls a
 * component actually makes, so a translatable string can't ship with no
 * English source entry (and therefore no entry for translators to pick up).
 *
 * It is intentionally dependency-free (pure Node, no build, no npm install)
 * so CI can run it in a bare node container, and devs can run it with
 * `node tests/l10n/check-l10n.js` from the app root.
 *
 * Modes:
 *   (default)  check only — exit non-zero if any used key is missing.
 *   --write    extraction — merge every missing used key into l10n/en.json
 *              as `"<source>": "<source>"` (English source === key, the
 *              Nextcloud convention) and re-sort. This is the reproducible
 *              "run extraction" step; run it, review the diff, commit.
 *
 * Exit codes:
 *   0  every used key is present in en.json (or --write made it so)
 *   1  one or more used keys are missing from en.json (hard failure)
 *
 * Env:
 *   L10N_APP_ID   override the app id (default: package.json "name")
 *   L10N_SRC_DIR  override the source dir to scan (default: src)
 *   L10N_FILE     override the en.json path (default: l10n/en.json)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')

const ROOT = process.cwd()
const WRITE = process.argv.includes('--write')

function readJson (p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

if (!appId) {
	console.error('l10n-check: cannot determine app id (no L10N_APP_ID and no package.json "name")')
	process.exit(2)
}

const srcDir = path.join(ROOT, process.env.L10N_SRC_DIR || 'src')
const enFile = path.join(ROOT, process.env.L10N_FILE || 'l10n/en.json')

if (!fs.existsSync(srcDir)) {
	console.error(`l10n-check: source dir not found: ${srcDir}`)
	process.exit(2)
}
if (!fs.existsSync(enFile)) {
	console.error(`l10n-check: en.json not found: ${enFile} — every t() call would be a miss`)
	process.exit(1)
}

const translations = readJson(enFile).translations || {}

// Collect all .vue/.js/.ts/.mjs files under the source dir.
const exts = new Set(['.vue', '.js', '.ts', '.mjs', '.jsx', '.tsx'])
const files = []
;(function walk (dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (entry.name === 'node_modules' || entry.name.startsWith('.')) {
			continue
		}
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			walk(full)
		} else if (exts.has(path.extname(entry.name))) {
			files.push(full)
		}
	}
})(srcDir)

/**
 * Match t('<app>', '<key>') and n('<app>', '<singular>', '<plural>', ...),
 * plus the $t/$n template variants. Only LITERAL string arguments are
 * checkable — dynamic args (variables, concatenation, template literals
 * with ${}) are skipped, since their key isn't statically knowable.
 *
 * The app id and quote style (single, double, or back-tick without ${})
 * are matched explicitly so we don't pick up unrelated t() helpers.
 */
const esc = appId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

// t('app', 'key'  — key in group 2 (any of the three quote styles).
const tRe = new RegExp(
	'[\\$.]?\\bt\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)
// n('app', 'singular', 'plural'  — singular in 2, plural in 3.
const nRe = new RegExp(
	'[\\$.]?\\bn\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)

function unescape (s) {
	// Mirror JS string unescaping for the escapes that appear in source keys.
	return s
		.replace(/\\n/g, '\n')
		.replace(/\\t/g, '\t')
		.replace(/\\'/g, "'")
		.replace(/\\"/g, '"')
		.replace(/\\`/g, '`')
		.replace(/\\\\/g, '\\')
}

// usedKey -> Set of "file:line" where it appears (for actionable output).
const used = new Map()

function record (key, file, idx, content) {
	if (key == null) {
		return
	}
	const k = unescape(key)
	const line = content.slice(0, idx).split('\n').length
	const where = `${path.relative(ROOT, file)}:${line}`
	if (!used.has(k)) {
		used.set(k, new Set())
	}
	used.get(k).add(where)
}

for (const file of files) {
	const content = fs.readFileSync(file, 'utf8')
	let m
	while ((m = tRe.exec(content)) !== null) {
		record(m[1] ?? m[2] ?? m[3], file, m.index, content)
	}
	while ((m = nRe.exec(content)) !== null) {
		record(m[1] ?? m[2] ?? m[3], file, m.index, content)
		record(m[4] ?? m[5] ?? m[6], file, m.index, content)
	}
}

const missing = []
for (const [key, locations] of used) {
	if (!Object.prototype.hasOwnProperty.call(translations, key)) {
		missing.push({ key, locations: [...locations] })
	}
}

console.log(`l10n-check [${appId}]: scanned ${files.length} files, `
	+ `${used.size} distinct literal keys used, `
	+ `${Object.keys(translations).length} keys in en.json`)

if (missing.length === 0) {
	console.log('l10n-check: OK — every used translation key is present in l10n/en.json')
	process.exit(0)
}

if (WRITE) {
	// Extraction mode: APPEND missing keys (source === English value) after
	// the existing entries, preserving the original key order so the diff is
	// purely additive (no whole-file re-sort churn). New keys are sorted
	// among themselves for a stable, reviewable block.
	const full = readJson(enFile)
	const appended = { ...full.translations }
	for (const { key } of missing.slice().sort((a, b) => a.key.localeCompare(b.key))) {
		appended[key] = key
	}
	full.translations = appended
	fs.writeFileSync(enFile, JSON.stringify(full, null, 4) + '\n')
	console.log(`l10n-check: WROTE ${missing.length} missing key(s) into `
		+ `${path.relative(ROOT, enFile)} (source === English value). `
		+ 'Review the diff and translate the nl.json side as needed.')
	process.exit(0)
}

console.error(`\nl10n-check: FAIL — ${missing.length} translation key(s) used in source `
	+ 'but MISSING from l10n/en.json:')
for (const { key, locations } of missing.sort((a, b) => a.key.localeCompare(b.key))) {
	console.error(`  • ${JSON.stringify(key)}`)
	for (const loc of locations.slice(0, 5)) {
		console.error(`      ${loc}`)
	}
	if (locations.length > 5) {
		console.error(`      … +${locations.length - 5} more`)
	}
}
console.error('\nAdd the missing source strings to l10n/en.json (key === English source), '
	+ 'or run `node tests/l10n/check-l10n.js --write` to extract them automatically.')
process.exit(1)
