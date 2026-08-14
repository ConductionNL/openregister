#!/usr/bin/env node
/* eslint-disable jsdoc/require-param */
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * l10n translation-PARITY gate.
 *
 * Guards that every REQUIRED locale carries a real translation for every
 * English source key. Without this, a new English string ships and the other
 * languages silently fall back to English with a green pipeline — the app
 * slowly stops "fully supporting" those languages.
 *
 * The required set is the official language of every European country plus
 * Russian and Turkish (ISO 639-1). Override with L10N_REQUIRED_LOCALES.
 *
 * For BOTH translation sets that exist in the app:
 *   • frontend  l10n/en.js   (OC.L10N.register)  -> l10n/<locale>.js
 *   • backend   l10n/en.json ({ translations })  -> l10n/<locale>.json
 * it asserts, for every required locale:
 *   1. the locale file exists and parses,
 *   2. no value is empty / whitespace-only; for plural arrays, no element may be
 *      empty,
 *   3. every plural array has exactly as many forms as the locale's OWN declared
 *      nplurals,
 * and additionally, for every locale in the FINISHED set:
 *   4. it contains every key present in the English source (no MISSING keys).
 *
 * What is FATAL and what is not:
 *   • (2) and (3) are RUNTIME faults — the string renders blank — so they fail
 *     for every locale, finished or not.
 *   • (4) fails only for a locale declared finished. Elsewhere a missing key is
 *     simply work not yet done, and is reported as a backlog so CI stays green
 *     while translation continues.
 *   • A value byte-identical to the English source is TOLERATED by default. The
 *     project writes deliberate cognates out ("CSV", "PDF", "RBAC", or "Flows" in
 *     Dutch, German and Danish) precisely so a finished locale stays key-for-key
 *     identical to en.js. Pass --strict-identical to fail on them instead, which
 *     is the right mode when auditing one locale for placeholder-shaped filler
 *     but the wrong mode for CI, where it would flag every legitimate cognate.
 *
 * A key that is genuinely untranslatable — an input placeholder or example value
 * — does not belong in t() at all: unwrap it in src/ and delete it from all 37
 * bundles including en.js, so no locale owes a value for it.
 *
 * Sparse override locales (en, en_US and any other regional en_*) are skipped.
 *
 * Dependency-free pure Node so CI can run it in a bare node container:
 *   node tests/l10n/check-l10n-parity.js [--strict-identical]
 *
 * Env:
 *   L10N_REQUIRED_LOCALES  override the required set
 *   L10N_FINISHED_LOCALES  override the finished set
 *
 * Exit codes:
 *   0  every finished locale is at full parity, and no locale has an empty value
 *      or a wrong-arity plural array
 *   1  a finished locale is missing a key or file, or any locale has an empty or
 *      wrong-arity value
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const ROOT = process.cwd()
const L10N_DIR = path.join(ROOT, 'l10n')

// Official language of every European country (ISO 639-1) + Russian + Turkish.
// nl/de/fr/es/it lead (the original supported set); then the EU-24 remainder,
// wider-Europe national languages, and micro-state / co-official nationals.
const EUROPEAN = [
	'nl', 'de', 'fr', 'es', 'it',
	'bg', 'hr', 'cs', 'da', 'et', 'fi', 'el', 'hu', 'ga', 'lv', 'lt', 'mt',
	'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
	'sq', 'is', 'nb', 'sr', 'bs', 'mk', 'uk', 'be', 'ru', 'tr',
	'ca', 'lb', 'rm',
].join(',')

function readJson(p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

const REQUIRED = (process.env.L10N_REQUIRED_LOCALES || EUROPEAN)
	.split(',').map((s) => s.trim()).filter(Boolean)

// Locales declared COMPLETE. For these, full key-for-key parity with en.js is a
// HARD requirement: one missing key fails the build. That is the whole point of
// the list — it is what stops a finished locale from drifting the moment someone
// adds an English string, which is how en.js came to sit ~700 keys ahead of the
// locales before any of this was gated.
//
// Every other required locale is a tracked backlog: its missing keys are reported
// as progress rather than failure, so CI can be green while translation continues.
// Move a locale here the moment it reaches parity — and never move one out to make
// a build pass.
const FINISHED_DEFAULT = [
	'nl', 'de', 'fr', 'es', 'it', 'pt', 'sv', 'da', 'nb',
	'pl', 'cs', 'ru', 'uk', 'el', 'fi', 'hu', 'tr', 'ca', 'et',
	'hr', 'lt',
].join(',')
const FINISHED = new Set((process.env.L10N_FINISHED_LOCALES || FINISHED_DEFAULT)
	.split(',').map((s) => s.trim()).filter(Boolean))

// A value byte-identical to the English source. Tolerated by default: a deliberate
// cognate ("CSV", "PDF", "RBAC", or "Flows" in Dutch, German and Danish) genuinely
// IS the correct value in the target language, and the project writes those out so
// every finished locale stays key-for-key identical to en.js.
//
// Pass --strict-identical to fail on them instead. That is the right mode when
// auditing a locale for placeholder-shaped filler, because an identical value that
// is NOT a cognate is indistinguishable from finished work and so never gets
// revisited. It is not the right mode for CI, where it would flag every cognate.
const strictIdentical = process.argv.includes('--strict-identical')

if (!fs.existsSync(L10N_DIR)) {
	console.error(`l10n-parity: no l10n/ directory at ${L10N_DIR}`)
	process.exit(2)
}

/** Load an OC.L10N.register(...) .js file into its translations object. */
function loadJs(file) {
	const code = fs.readFileSync(file, 'utf8')
	let captured = null
	const sandbox = { OC: { L10N: { register: (id, obj) => { captured = obj } } } }
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox, { filename: file, timeout: 5000 })
	return captured || {}
}

/**
 * Read the nplurals count declared in a .js locale file's plural-forms header,
 * i.e. the third argument of OC.L10N.register. Returns null when absent.
 *
 * Needed because a plural array whose length disagrees with the locale's own
 * declared nplurals is a RUNTIME fault, not a style issue: OC.L10N indexes the
 * array with the plural expression's result, so a short array yields undefined
 * and the string renders blank. This is the one l10n defect that cannot be seen
 * by reading the file — Russian, Polish and Czech each declare nplurals=3 with
 * three MUTUALLY INCOMPATIBLE expressions, so arrays cannot be copied between
 * them even though the arity matches.
 */
function declaredNplurals(file) {
	const m = fs.readFileSync(file, 'utf8').match(/nplurals\s*=\s*(\d+)/)
	return m ? Number(m[1]) : null
}

/** Load an l10n .json file into its translations object. */
function loadJsonSet(file) {
	return readJson(file).translations || {}
}

/** True when a translation value is empty (string) or has an empty plural. */
function isEmpty(v) {
	if (v == null) {
		return true
	}
	if (Array.isArray(v)) {
		return v.length === 0 || v.some((e) => typeof e !== 'string' || e.trim() === '')
	}
	return typeof v !== 'string' || v.trim() === ''
}

const sets = [
	{
		kind: 'frontend (.js)',
		enFile: path.join(L10N_DIR, 'en.js'),
		file: (loc) => path.join(L10N_DIR, `${loc}.js`),
		load: loadJs,
	},
	{
		kind: 'backend (.json)',
		enFile: path.join(L10N_DIR, 'en.json'),
		file: (loc) => path.join(L10N_DIR, `${loc}.json`),
		load: loadJsonSet,
	},
]

const failures = []
// Locales not yet declared finished: reported, but they do not fail the build.
const backlog = []
let checkedSets = 0

for (const set of sets) {
	if (!fs.existsSync(set.enFile)) {
		continue // this app does not ship this translation set
	}
	checkedSets++
	const enObj = set.load(set.enFile)
	const enKeys = Object.keys(enObj)
	for (const loc of REQUIRED) {
		const locFile = set.file(loc)
		if (!fs.existsSync(locFile)) {
			failures.push({ set: set.kind, loc, kind: 'MISSING FILE', detail: path.relative(ROOT, locFile) })
			continue
		}
		let locObj
		try {
			locObj = set.load(locFile)
		} catch (e) {
			failures.push({ set: set.kind, loc, kind: 'UNPARSEABLE', detail: e.message })
			continue
		}
		const missing = enKeys.filter((k) => !Object.prototype.hasOwnProperty.call(locObj, k))
		const empty = enKeys.filter((k) => Object.prototype.hasOwnProperty.call(locObj, k) && isEmpty(locObj[k]))

		// An entry whose value is byte-identical to the English source. Reported
		// separately from `missing` because the two are OPPOSITES, not degrees of
		// the same problem:
		//
		//   absent    -> OC.L10N falls back to the English source. The UI renders
		//                correct text, and every tool can still see the key is
		//                untranslated, so it stays on the work list.
		//   identical -> renders the same characters, but is indistinguishable
		//                from a finished translation to both tooling and the next
		//                maintainer. It is therefore never revisited: a permanent,
		//                invisible hole.
		//
		// So identical is the WORSE of the two and is the one worth gating on. The
		// fix is to DELETE the key and let it fall back, not to invent a value.
		// Genuine cognates (ID, URL, CSV, PDF, Webhook, Avatar in many languages)
		// are handled the same way — omitted, not written as value===key.
		//
		// Always measured; whether it is fatal depends on --strict-identical.
		const identical = enKeys.filter((k) =>
			Object.prototype.hasOwnProperty.call(locObj, k)
			&& !isEmpty(locObj[k])
			&& JSON.stringify(locObj[k]) === JSON.stringify(enObj[k]),
		)

		// Plural arity, frontend set only (.json plurals use a keyed object shape).
		const badArity = []
		if (set.kind === 'frontend (.js)') {
			const n = declaredNplurals(locFile)
			if (n) {
				for (const [k, v] of Object.entries(locObj)) {
					if (Array.isArray(v) && v.length !== n) {
						badArity.push({ key: k, got: v.length, want: n })
					}
				}
			}
		}

		const finished = FINISHED.has(loc)
		// Empty values and wrong plural arity are RUNTIME faults — the string renders
		// blank — so they fail for every locale regardless of completion status.
		// A missing key only fails for a locale declared finished; elsewhere it is
		// simply work not yet done.
		const fatal = empty.length > 0
			|| badArity.length > 0
			|| (finished && missing.length > 0)
			|| (strictIdentical && identical.length > 0)
		const entry = {
			set: set.kind,
			loc,
			finished,
			kind: 'INCOMPLETE',
			missing,
			empty,
			identical,
			badArity,
			total: enKeys.length,
		}
		if (fatal) {
			failures.push(entry)
		} else if (missing.length) {
			backlog.push(entry)
		}
	}
}

const label = appId ? `[${appId}]` : ''
console.log(`l10n-parity ${label}: ${REQUIRED.length} required locales; checked ${checkedSets} translation set(s)`)

if (checkedSets === 0) {
	console.log('l10n-parity: no en.js / en.json source set found — nothing to check')
	process.exit(0)
}

const finishedList = [...FINISHED].sort()
console.log(`l10n-parity: ${finishedList.length} locale(s) declared finished and held at `
	+ `key-for-key parity: ${finishedList.join(' ')}`)

// Always print the backlog, on pass as well as fail. A gate that goes quiet about
// the 19 unfinished locales would read as "everything is translated".
if (backlog.length) {
	console.log(`\nl10n-parity: ${backlog.length} locale(s) still in progress (not gated):`)
	for (const b of backlog.sort((x, y) => x.missing.length - y.missing.length)) {
		console.log(`  · ${b.set} ${b.loc}: ${b.missing.length} of ${b.total} key(s) to go`
			+ `${b.identical.length ? `, ${b.identical.length} English-identical` : ''}`)
	}
}

if (failures.length === 0) {
	console.log('\nl10n-parity: OK — every finished locale is at full parity '
		+ '(no missing keys, no empty values, no bad plural arity)')
	process.exit(0)
}

console.error('\nl10n-parity: FAIL — a finished locale lost parity, or a value would render blank:')
for (const f of failures) {
	if (f.kind === 'MISSING FILE') {
		console.error(`  • ${f.set} ${f.loc}: locale file missing (${f.detail})`)
	} else if (f.kind === 'UNPARSEABLE') {
		console.error(`  • ${f.set} ${f.loc}: cannot parse (${f.detail})`)
	} else {
		console.error(`  • ${f.set} ${f.loc}${f.finished ? ' (declared FINISHED)' : ''}: ${f.missing.length} missing key(s), `
			+ `${f.empty.length} empty value(s), ${f.identical.length} English-identical, `
			+ `${f.badArity.length} bad plural arity — of ${f.total}`)
		for (const k of f.missing.slice(0, 8)) {
			console.error(`      missing:   ${JSON.stringify(k)}`)
		}
		if (f.missing.length > 8) {
			console.error(`      … +${f.missing.length - 8} more missing`)
		}
		for (const k of f.empty.slice(0, 4)) {
			console.error(`      empty:     ${JSON.stringify(k)}`)
		}
		for (const k of f.identical.slice(0, 6)) {
			console.error(`      identical: ${JSON.stringify(k)}`)
		}
		if (f.identical.length > 6) {
			console.error(`      … +${f.identical.length - 6} more identical to English`)
		}
		for (const b of f.badArity.slice(0, 6)) {
			console.error(`      arity:     ${JSON.stringify(b.key)} has ${b.got} form(s), `
				+ `locale declares nplurals=${b.want}`)
		}
	}
}
console.error('\nA locale in the finished set must carry every English source key.')
console.error('  missing   -> add the translation. If a new English string landed without translations,')
console.error('               that is the failure: translate it, do not remove the locale from the')
console.error('               finished set to get a green build.')
console.error('               If the string is not translatable prose at all — an input placeholder or')
console.error('               example value — unwrap it from t() in src/ and delete the key from ALL')
console.error('               bundles including en.js, so no locale owes a value for it.')
console.error('  empty     -> renders blank. Always fatal, finished or not.')
console.error('  arity     -> the plural array must have exactly as many forms as the locale\'s own')
console.error('               nplurals declares, or the runtime renders blank for some counts.')
console.error('               Always fatal, finished or not.')
console.error('  identical -> tolerated by default: a deliberate cognate is the correct value and is')
console.error('               written out so the locale stays key-for-key identical to en.js. Run with')
console.error('               --strict-identical to audit a locale for placeholder-shaped filler.')
process.exit(1)
