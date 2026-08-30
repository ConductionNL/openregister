#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * l10n extraction / drift check — FRONTEND catalogue.
 *
 * Scans the frontend source for translation calls — t('<app>', '...'),
 * n('<app>', '...', '...', n) and the $t/$n template variants — and asserts
 * every literal source string is present as a key in l10n/en.js.
 *
 * ## Why en.js and not en.json
 *
 * The two translation sets are separate catalogues with separate consumers:
 *
 *   l10n/*.js    FRONTEND   OC.L10N.register -> t() / n()   (loaded by the browser)
 *   l10n/*.json  BACKEND    PHP IL10N -> $l->t()
 *
 * They are not two renderings of one source. This check previously asserted
 * frontend t() literals against l10n/en.json, which no frontend code path ever
 * reads — so it demanded bookkeeping in a backend file while the catalogue the
 * browser actually loads went unaudited (en.js was ~700 keys behind src/, and
 * nothing noticed because a missing key makes OC.L10N fall back to the English
 * source string, which renders correctly).
 *
 * The backend .json set is a separate concern. There is no scanner for it yet:
 * it would need to walk lib/ for PHP $l->t() calls, not src/.
 *
 * ## Plurals
 *
 * An n() call has TWO source strings but only ONE catalogue key, and that key is
 * NEITHER of them: it is the identifier `_<singular>_::_<plural>_`, which is the
 * only thing @nextcloud/l10n's translatePlural looks up (see pluralIdentifier in
 * scripts/l10n/lib.js). Its value is an array of the locale's nplurals forms, so
 * --write emits `[singular, plural]` rather than a bare string — a string value on
 * a plural key renders blank at runtime.
 *
 * This gate previously required the bare singular instead. That shape renders
 * correctly for count === 1, because translate() takes element 0 of an array, and
 * falls back to English for every other count — so it passed the gate while every
 * "3 objects" in all 36 locales rendered English.
 *
 * Extraction is delegated to scripts/l10n/lib.js, which reads real string
 * literals — decoding \u/\x escapes and rejecting concatenations and template
 * interpolations — instead of pattern-matching.
 * The same lib backs scripts/check-l10n.js, clean-l10n.js and l10n-ai.js, so
 * every tool agrees on what "used" means.
 *
 * Division of labour with scripts/check-l10n.js: that one is the richer
 * developer audit (missing + unused + unwrapped, no write mode); this one is the
 * CI gate (missing only, plus --write extraction).
 *
 * Modes:
 *   (default)  check only — exit non-zero if any used key is missing.
 *   --write    extraction — merge every missing used key into l10n/en.js
 *              (value === the English source, which is correct for `en`:
 *              en IS the source language) and re-serialize in the on-disk
 *              Nextcloud/Transifex layout.
 *   --parity   after a clean check, also run the all-locales parity gate.
 *
 * Exit codes:
 *   0  every used key is present in en.js (or --write made it so)
 *   1  one or more used keys are missing from en.js (hard failure)
 *
 * Env:
 *   L10N_APP_ID   override the app id (default: the id en.js registers)
 *   L10N_SRC_DIR  override the source dir to scan (default: src)
 *   L10N_FILE     override the en.js path (default: l10n/en.js)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')

const {
	loadJsTranslations,
	serializeJs,
	walk,
	extractTranslationCalls,
	makeLineResolver,
	SRC_EXTS,
	pluralIdentifier,
	MORPHOLOGY_PLACEHOLDER,
	MORPHOLOGY_TERNARY,
} = require('../../scripts/l10n/lib.js')

const ROOT = process.cwd()
const WRITE = process.argv.includes('--write')

const srcDir = path.join(ROOT, process.env.L10N_SRC_DIR || 'src')
const enFile = path.join(ROOT, process.env.L10N_FILE || 'l10n/en.js')

if (!fs.existsSync(srcDir)) {
	console.error(`l10n-check: source dir not found: ${srcDir}`)
	process.exit(2)
}
if (!fs.existsSync(enFile)) {
	console.error(`l10n-check: en.js not found: ${enFile} — every t() call would be a miss`)
	process.exit(1)
}

const { app: registeredApp, translations, pluralForm } = loadJsTranslations(enFile)
// The id en.js registers is the source of truth: it is what OC.L10N keys the
// catalogue under at runtime, so a mismatch with package.json "name" would make
// every t() call in src/ invisible to this scan.
const appId = process.env.L10N_APP_ID || registeredApp

// Extensions worth scanning, from the shared lib so this gate and
// clean-l10n.js --apply cannot disagree about which files count as source — a
// file type only the gate scanned would have its keys deleted from all 37
// locales while CI stayed green. See SRC_EXTS there.
const files = walk(srcDir, SRC_EXTS)

// key -> Set of "file:line". Only REAL catalogue keys land here: a t() key, or
// an n() call's plural identifier.
const used = new Map()
// plural identifier -> [singular, plural], for --write's forms array.
const formsOf = new Map()
// [{ where, snippet, why }] — English morphology assembled in JS. See the ban
// below; this is collected in the same walk so no second pass over src is needed.
const morphology = []

for (const file of files) {
	const text = fs.readFileSync(file, 'utf8')
	const posToLine = makeLineResolver(text)
	const { calls } = extractTranslationCalls(text, appId)
	for (const c of calls) {
		const where = `${path.relative(ROOT, file)}:${posToLine(c.index)}`

		// BAN: hardcoded English morphology inside a translation call.
		//
		// Two spellings of one defect. A key like `object{plural}` paired with
		// `{ plural: count !== 1 ? 's' : '' }` makes the RUNTIME glue an English
		// "s" onto the locale's value, so a language whose plural is not a
		// suffixed -s cannot render it, and a three- or four-form language cannot
		// render it at all. Five such keys shipped for the life of this app and
		// every locale had to invent a workaround for them.
		//
		// Checked here rather than only against en.js because the defect is in the
		// CALL, not in the catalogue: adding `object{plural}` to en.js would
		// otherwise make it "present" and this gate would pass. That is why the
		// ban runs before the missing-key comparison and reports independently.
		//
		// The ternary form is bounded by the call's own parens (c.end), so the
		// identical expression in an ordinary template literal —
		// `${years} year${years !== 1 ? 's' : ''}` — does NOT fail here. That is an
		// unwrapped string, a different and separately tracked problem.
		if (c.keys.some((k) => MORPHOLOGY_PLACEHOLDER.test(k))) {
			morphology.push({ where, snippet: c.keys.find((k) => MORPHOLOGY_PLACEHOLDER.test(k)), why: '{plural} in the key' })
		} else if (c.end > c.index) {
			const span = text.slice(c.index, c.end + 1)
			if (MORPHOLOGY_TERNARY.test(span)) {
				morphology.push({ where, snippet: span.replace(/\s+/g, ' ').slice(0, 90), why: "English \"s\" built with ? 's' : ''" })
			}
		}
		// c.keys is [key] for t(), [singular, plural] for n(). For n() the
		// catalogue key is neither one: it is the identifier they combine into.
		const isPlural = c.fn === 'n' && c.keys.length === 2
		const key = isPlural ? pluralIdentifier(c.keys[0], c.keys[1]) : c.keys[0]
		if (isPlural) {
			formsOf.set(key, [c.keys[0], c.keys[1]])
		}
		if (!used.has(key)) {
			used.set(key, new Set())
		}
		used.get(key).add(where)
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
	+ `${Object.keys(translations).length} keys in en.js`)

// The morphology ban is reported BEFORE the missing-key comparison and exits on
// its own. Two reasons it cannot be folded into `missing`: the offending key may
// well be present in en.js (adding it is the wrong fix, not the right one), and
// --write must refuse to extract it rather than helpfully making it permanent.
if (morphology.length) {
	console.error(`\nl10n-check: FAIL — ${morphology.length} translation call(s) build English `
		+ 'morphology in JS:')
	for (const { where, snippet, why } of morphology) {
		console.error(`  • ${where}  (${why})`)
		console.error(`      ${snippet}`)
	}
	console.error('\nA placeholder cannot carry a plural. The runtime would glue an English "s" onto')
	console.error('the translated value, which is wrong in every language whose plural is not +s and')
	console.error('impossible in one with three or four forms. Use a real plural call instead:')
	console.error("\n    n('openregister', 'object', 'objects', count)")
	console.error("    n('openregister', '{count} object', '{count} objects', count, { count })")
	console.error('\nIts catalogue key is NEITHER source string — it is "_object_::_objects_".')
	console.error('See docs/l10n-workflow.md §7.1.')
	process.exit(1)
}

if (missing.length === 0) {
	console.log('l10n-check: OK — every used translation key is present in l10n/en.js')
	// English source is complete. Full multi-locale parity (every required locale
	// complete) is an OPT-IN check, run via `npm run test:l10n:parity` (--parity),
	// so the coverage gate stays green while the stale locales are a separately
	// tracked backlog and don't block every PR.
	if (process.argv.includes('--parity')) {
		require('./check-l10n-parity.js')
	}
	process.exit(0)
}

if (WRITE) {
	// Extraction mode. Value === the English source: for `en` that is correct,
	// because en IS the source language (for any OTHER locale a value equal to
	// its key is the one thing never to write — it is indistinguishable from a
	// finished translation and so never gets revisited).
	const merged = { ...translations }
	for (const { key } of missing) {
		// A plural key's value must be an array of this locale's nplurals forms;
		// a bare string there renders blank at runtime. The English forms are the
		// two source strings, which is why the identifier carries them.
		merged[key] = formsOf.get(key) ?? key
	}
	fs.writeFileSync(enFile, serializeJs({ app: appId, translations: merged, pluralForm }))
	console.log(`l10n-check: WROTE ${missing.length} missing key(s) into `
		+ `${path.relative(ROOT, enFile)} (value === English source). `
		+ 'Review the diff, then translate the other locales via l10n tooling.')
	process.exit(0)
}

console.error(`\nl10n-check: FAIL — ${missing.length} translation key(s) used in source `
	+ 'but MISSING from l10n/en.js:')
for (const { key, locations } of missing.sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : 0))) {
	console.error(`  • ${JSON.stringify(key)}`)
	for (const loc of locations.slice(0, 5)) {
		console.error(`      ${loc}`)
	}
	if (locations.length > 5) {
		console.error(`      … +${locations.length - 5} more`)
	}
}
console.error('\nAdd the missing source strings to l10n/en.js, '
	+ 'or run `node tests/l10n/check-l10n.js --write` to extract them automatically.')
process.exit(1)
