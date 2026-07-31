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
 *   1. the locale file exists,
 *   2. it contains every key present in the English source (no MISSING keys),
 *   3. no value is empty / whitespace-only (no UNTRANSLATED placeholders);
 *      for plural arrays, no element may be empty,
 *   4. no value is byte-identical to the English source (see below),
 *   5. every plural array has exactly as many forms as the locale's OWN
 *      declared nplurals — the only defect here that breaks the runtime.
 *
 * On (4): a value equal to the English source used to be allowed and merely
 * counted, on the theory that cognates and acronyms are legitimately identical.
 * That polarity is backwards. An ABSENT key falls back to the English source, so
 * it renders correct text AND stays visibly untranslated to tooling. An entry
 * written as value===key renders the same characters but is indistinguishable
 * from finished work, so it is never revisited — a permanent invisible hole.
 * Cognates therefore belong ABSENT, not written out. Pass --allow-identical to
 * restore the old tolerance during a bulk migration.
 *
 * Sparse override locales (en, en_US and any other regional en_*) are skipped.
 *
 * Dependency-free pure Node so CI can run it in a bare node container:
 *   node tests/l10n/check-l10n-parity.js [--allow-identical]
 *
 * Exit codes:
 *   0  every required locale is at full parity for every existing source set
 *   1  one or more locales are missing keys/files, or have empty, English-
 *      identical, or wrong-arity values
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

// Restores the historical behaviour of tolerating values byte-identical to the
// English source. Off by default -- see the IDENTICAL block below for why that
// polarity is wrong. Kept as an escape hatch for a bulk migration where the
// identical entries are known and being worked through.
const allowIdentical = process.argv.includes('--allow-identical')

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
		// This inverts the original policy here, which allowed identical values and
		// merely counted them. That is exactly how ru shipped 24 untranslated
		// English strings behind an otherwise clean report.
		const identical = allowIdentical
			? []
			: enKeys.filter((k) =>
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

		if (missing.length || empty.length || identical.length || badArity.length) {
			failures.push({
				set: set.kind,
				loc,
				kind: 'INCOMPLETE',
				missing,
				empty,
				identical,
				badArity,
				total: enKeys.length,
			})
		}
	}
}

const label = appId ? `[${appId}]` : ''
console.log(`l10n-parity ${label}: ${REQUIRED.length} required locales; checked ${checkedSets} translation set(s)`)

if (checkedSets === 0) {
	console.log('l10n-parity: no en.js / en.json source set found — nothing to check')
	process.exit(0)
}

if (failures.length === 0) {
	console.log('l10n-parity: OK — every required locale is at full parity (no missing keys, no empty values)')
	process.exit(0)
}

console.error('\nl10n-parity: FAIL — required language support is incomplete:')
for (const f of failures) {
	if (f.kind === 'MISSING FILE') {
		console.error(`  • ${f.set} ${f.loc}: locale file missing (${f.detail})`)
	} else if (f.kind === 'UNPARSEABLE') {
		console.error(`  • ${f.set} ${f.loc}: cannot parse (${f.detail})`)
	} else {
		console.error(`  • ${f.set} ${f.loc}: ${f.missing.length} missing key(s), `
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
console.error('\nEvery required locale must translate every English source key.')
console.error('  missing   -> add the translation (or leave absent deliberately: it falls back to English).')
console.error('  identical -> DELETE the key. A value equal to the English source is indistinguishable')
console.error('               from a finished translation, so it is never revisited; omitting it renders')
console.error('               the same text while staying visibly untranslated. Pass --allow-identical')
console.error('               to tolerate these during a bulk migration.')
console.error('  arity     -> the plural array must have exactly as many forms as the locale\'s own')
console.error('               nplurals declares, or the runtime renders blank for some counts.')
process.exit(1)
