#!/usr/bin/env node
/* eslint-disable jsdoc/require-param */
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * l10n unused-key remover.
 *
 * Reuses the extraction logic from check-l10n.js to detect keys that exist in
 * l10n/en.js but are not referenced by any t('<app>', '...') call in src/.
 * Those keys are removed from EVERY l10n/*.js file (English and all
 * translations).
 *
 * This script intentionally does NOT add missing keys. Adding a key without a
 * human-written translation would leave non-English files with English values
 * that t() then returns *as if* they were translated — overriding the normal
 * fallback to the source string. Missing keys should be added through the
 * regular l10n workflow (Transifex, hand-edits, or scripts/l10n-ai.js add).
 *
 * Usage:
 *   node scripts/clean-l10n.js           # dry-run: prints what would be removed
 *   node scripts/clean-l10n.js --apply   # actually remove the unused keys
 *
 * Safety: UNUSED detection is based purely on JS/Vue/TS usage. This is safe
 * because l10n/*.js is frontend-only (PHP reads from l10n/*.json, a separate
 * file set). Do NOT run --apply on a project where .js and .json are kept in
 * sync with each other.
 */

const fs = require('fs')
const path = require('path')

const {
	loadJsTranslations,
	serializeJs,
	collectUsedKeys,
	listJsLocaleFiles,
	collectDynamicKeys,
} = require('./lib/l10n.js')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')
const L10N_DIR = path.join(ROOT, 'l10n')
const ENGLISH_FILE = path.join(L10N_DIR, 'en.js')

// ---------- CLI ----------

const args = new Set(process.argv.slice(2))
if (args.has('--help') || args.has('-h')) {
	console.log(fs.readFileSync(__filename, 'utf8').split('\n').slice(2, 27).join('\n'))
	process.exit(0)
}
const apply = args.has('--apply')

// ---------- Main ----------

function main() {
	if (!fs.existsSync(ENGLISH_FILE)) {
		console.error(`English source file not found: ${ENGLISH_FILE}`)
		process.exit(1)
	}

	const { app, translations: english } = loadJsTranslations(ENGLISH_FILE)
	const existingKeys = new Set(Object.keys(english))
	const usedKeys = collectUsedKeys(SRC_DIR, app)
	// Keys reached through a variable cannot be found by scanning. They are live,
	// and deleting them silently un-translates real UI, so they are never
	// candidates for removal. See DYNAMIC_KEYS in lib/l10n.js.
	const dynamicKeys = collectDynamicKeys(ROOT)
	const unused = [...existingKeys]
		.filter(k => !usedKeys.has(k) && !dynamicKeys.has(k))
		.sort()

	console.log(`${app} l10n unused-key remover`)
	console.log(`  Used keys in src/:  ${usedKeys.size}`)
	console.log(`  Keys in en.js:      ${existingKeys.size}`)
	console.log(`  Protected (dynamic):${dynamicKeys.size}`)
	console.log(`  Unused keys:        ${unused.length}`)
	console.log('')

	if (!unused.length) {
		console.log('Nothing to remove.')
		return
	}

	if (!apply) {
		console.log('Dry-run. Pass --apply to remove these keys from all l10n/*.js files.\n')
		for (const k of unused) console.log(`  - ${JSON.stringify(k)}`)
		return
	}

	const files = listJsLocaleFiles(L10N_DIR)

	const written = []
	for (const file of files) {
		const { app: fileApp, translations, pluralForm } = loadJsTranslations(file)
		const before = Object.keys(translations).length
		for (const k of unused) delete translations[k]
		const after = Object.keys(translations).length
		fs.writeFileSync(file, serializeJs({ app: fileApp, translations, pluralForm }))
		written.push(file)
		console.log(`${path.basename(file)}: ${before} → ${after} keys`)
	}

	// No eslint pass: serializeJs already emits the canonical on-disk layout, and
	// `eslint --fix` would rewrite every locale file to tabs and single quotes.
	console.log(`\nRewrote ${written.length} locale file(s).`)
	console.log('Done. Run `npm run check:l10n` to verify.')
}

main()
