#!/usr/bin/env node
 
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
 *   4. it contains every key present in the English source (no MISSING keys).
 *
 * All four are FATAL, for every required locale:
 *   • (2) and (3) are RUNTIME faults — the string renders blank.
 *   • (4) breaks the parity invariant. There is no per-locale exemption and no env
 *     override for it, because either would be the knob someone reaches for to
 *     turn a red build green.
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
 *
 * Exit codes:
 *   0  every locale is at full parity, and no locale has an empty value
 *      or a wrong-arity plural array
 *   1  a locale is missing a key or file, or any locale has an empty or
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

// Per-locale config from scripts/l10n/locales/<loc>.json: the measured register,
// the JUSTIFIED cognates, and audited corrections. Required so this gate can tell
// a deliberate cognate apart from placeholder-shaped filler — without it, "19
// English-identical" is a number nobody can act on. Still dependency-free pure
// Node; it is a sibling file in this repo, not an npm package.
const {
	loadLocaleConfig, configuredLocales, hasIdenticalForm, MORPHOLOGY_PLACEHOLDER,
} = require('../../scripts/l10n/lib.js')

// Locales whose identical values are held to a recorded justification. This is
// OPT-IN per locale, keyed on the existence of scripts/l10n/locales/<loc>.json,
// because fifteen locales predate the cognate rule and carry ~375 identical values
// nobody has reviewed. Failing CI on those would say
// "regression" about history, and some of them are legitimate — `nl` genuinely
// renders `Bewaartermijn` and `AVG / Verwerkingsregister` unchanged, because those
// are Dutch words in a Dutch bundle.
//
// The gap is REPORTED below rather than hidden. Add a locales/<loc>.json as each
// old locale gets reviewed, and it becomes enforced from that moment.
const COGNATES_ENFORCED = new Set(configuredLocales())

function readJson(p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

const REQUIRED = (process.env.L10N_REQUIRED_LOCALES || EUROPEAN)
	.split(',').map((s) => s.trim()).filter(Boolean)

// Full key-for-key parity with en.js is a HARD requirement: one missing key fails
// the build. That is what stops a locale from drifting the moment someone adds an
// English string, which is how en.js came to sit ~700 keys ahead of the locales
// before any of this was gated.
//
// EVERY required locale is held to full parity, unconditionally. There is no
// per-locale exemption list and no env override, because either would be the knob
// someone reaches for to turn a red build green. Adding an English string means
// translating it or unwrapping it — docs/l10n-workflow.md §6.15.

// A value byte-identical to the English source. Tolerated by default: a deliberate
// cognate ("CSV", "PDF", "RBAC", or "Flows" in Dutch, German and Danish) genuinely
// IS the correct value in the target language, and the project writes those out so
// every locale stays key-for-key identical to en.js.
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
	if ((v === null || v === undefined)) {
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
// locale -> keys, for the backend `.json` set only. Reported, never fatal — see
// the scope note at the pluralHack check.
const backendPluralResidue = new Map()
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
		const missing = enKeys.filter((k) => !Object.hasOwn(locObj, k))
		const empty = enKeys.filter((k) => Object.hasOwn(locObj, k) && isEmpty(locObj[k]))

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
			Object.hasOwn(locObj, k)
			&& !isEmpty(locObj[k])
			&& JSON.stringify(locObj[k]) === JSON.stringify(enObj[k]),
		)

		// BAN: `{plural}` anywhere in a key or a value.
		//
		// The catalogue arm of the ban whose source arm lives in check-l10n.js.
		// Both are needed and neither implies the other: src/ can be clean while a
		// bundle still carries `bestand(en)`-style residue from the era when five
		// keys interpolated an English "s", and a hand-edit or a stale Transifex
		// pull can reintroduce it into a bundle without touching src/ at all.
		//
		// Fatal for EVERY locale, including the fourteen that predate the cognate
		// rule — unlike the identical-value check, this one is not scoped by
		// `enforced`, because a literal "{plural}" reaching a user is a runtime
		// defect rather than an unreviewed judgement call.
		//
		// It IS scoped to the frontend set, and that is a scope limit rather than a
		// judgement that the backend is clean: `l10n/*.json` still carries the five
		// dead keys, which no PHP references and which are residue of the frontend
		// hack rather than backend strings in their own right. Nothing in this
		// tooling writes the `.json` set (see scripts/l10n/README.md and
		// l10n-ai.js), so failing CI on a file no sanctioned tool can fix would
		// leave the build red with no green path. They are reported instead, once,
		// after the per-locale loop.
		const hits = Object.entries(locObj)
			.filter(([k, v]) => MORPHOLOGY_PLACEHOLDER.test(k)
				|| (Array.isArray(v) ? v : [v]).some((f) => MORPHOLOGY_PLACEHOLDER.test(String(f))))
			.map(([k]) => k)
		const frontend = set.kind === 'frontend (.js)'
		const pluralHack = frontend ? hits : []
		if (!frontend && hits.length) {
			backendPluralResidue.set(loc, hits)
		}

		// A plural form that is ALSO a key in the same bundle.
		//
		// translatePlural picks the form and then hands it BACK to translate():
		//
		//   return translate(app, translation[plural], vars, number, options)
		//
		// and translate() resolves `bundle.translations[text] || text`. So a form
		// whose text happens to be another key renders that OTHER key's value. The
		// array is right, the file reads right, and the user sees something the
		// array never said. Found when `rm`'s form 0 "schema(s)" — chosen because
		// the library reaches no other form for Romansh — collided with the
		// bundle's own `schema(s)` key and rendered "Schema(s)".
		//
		// Not caught by anything else: arity is right, no value is empty, nothing
		// equals English, and runtime-check only asserts the result is non-empty
		// and translated, which the substituted value also is. Forms that resolve
		// to themselves are skipped — that re-translation is a no-op.
		const pluralCollision = frontend
			? Object.entries(locObj).flatMap(([k, v]) => (Array.isArray(v) ? v : [])
				.map((form, i) => ({ k, i, form }))
				.filter(({ form }) => Object.hasOwn(locObj, form)
					&& JSON.stringify(locObj[form]) !== JSON.stringify(form)))
			: []

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

		// Which identical values are JUSTIFIED. A genuine cognate ("CSV", "PDF",
		// "RBAC", "Schema" in Lithuanian, "Flows" in nl/de/da) is real finished work
		// and must keep parity, so it is written out — but only against a recorded
		// reason in scripts/l10n/locales/<loc>.json. Anything identical WITHOUT one is
		// indistinguishable from filler, which is the state this whole gate exists to
		// prevent, so it is fatal.
		//
		// The .json (backend) set has no per-locale config, so it is exempt.
		const enforced = set.kind === 'frontend (.js)' && COGNATES_ENFORCED.has(loc)
		const cognates = enforced ? loadLocaleConfig(loc).cognates : {}
		const unjustified = enforced
			? identical.filter((k) => !Object.hasOwn(cognates, k))
			: []
		// A reason recorded for a key that is no longer identical is a stale
		// permission slip: it would silently license the next value written there.
		//
		// "No longer identical" has to mean NOTHING is left to excuse, not "the whole
		// value differs". A plural key can legitimately have ONE form that renders the
		// English source while the others differ — Romanian's email array is
		// ["{count} email", "{count} emailuri", "{count} de emailuri"], because Romanian
		// borrows 'email' unchanged and inserts 'de' from 20 up. runtime-check.mjs needs
		// that record to excuse the singular, so calling it stale here would make one
		// record simultaneously required and forbidden. See hasIdenticalForm in lib.js.
		const staleCognates = enforced
			? Object.keys(cognates).filter((k) => !identical.includes(k)
				&& !(Object.hasOwn(locObj, k)
					&& hasIdenticalForm(k, locObj[k])))
			: []

		// Everything here is fatal, for every locale. Empty values and wrong plural
		// arity are RUNTIME faults — the string renders blank — and a missing key
		// breaks the parity invariant. Unjustified identical values and stale cognate
		// records need no extra guard: both arrays are scoped by `enforced`, which
		// keys on locales/<loc>.json existing, so both are empty for a locale that
		// predates the cognate rule.
		const fatal = empty.length > 0
			|| badArity.length > 0
			|| missing.length > 0
			|| unjustified.length > 0
			|| staleCognates.length > 0
			|| pluralHack.length > 0
			|| pluralCollision.length > 0
			|| (strictIdentical && identical.length > 0)
		const entry = {
			set: set.kind,
			loc,
			kind: 'INCOMPLETE',
			missing,
			empty,
			identical,
			unjustified,
			staleCognates,
			badArity,
			pluralHack,
			pluralCollision,
			total: enKeys.length,
		}
		if (fatal) {
			failures.push(entry)
		}
	}
}

const label = appId ? `[${appId}]` : ''
console.log(`l10n-parity ${label}: ${REQUIRED.length} required locales; checked ${checkedSets} translation set(s)`)

if (checkedSets === 0) {
	console.log('l10n-parity: no en.js / en.json source set found — nothing to check')
	process.exit(0)
}

const allLocales = [...REQUIRED].sort()
console.log(`l10n-parity: all ${allLocales.length} required locale(s) are held at key-for-key `
	+ `parity, unconditionally: ${allLocales.join(' ')}`)

// Say plainly which locales have their identical values under justification, and
// which do not. Silence here would let the unreviewed ~375 pass as verified.
// The backend residue, printed on every run whether or not anything failed. It is
// the one place `{plural}` still exists in this repo, so silence here would read
// as "the ban covers everything" when it covers the frontend only.
if (backendPluralResidue.size) {
	const keys = [...new Set([...backendPluralResidue.values()].flat())].sort()
	console.log(`l10n-parity: NOTE — ${backendPluralResidue.size} backend (.json) bundle(s) still carry `
		+ `${keys.length} {plural} key(s): ${keys.join(' ')}`)
	console.log('             Residue of the removed frontend hack — no PHP references them. Not fatal:')
	console.log('             nothing in scripts/l10n/ writes the .json set, so this gate would have no')
	console.log('             green path. Removing them is a separate, deliberate change.')
}

const enforcedList = allLocales.filter((l) => COGNATES_ENFORCED.has(l))
const unreviewed = allLocales.filter((l) => !COGNATES_ENFORCED.has(l))
console.log(`l10n-parity: ${enforcedList.length} of those also hold every English-identical value to a `
	+ `recorded justification: ${enforcedList.join(' ') || '(none)'}`)
if (unreviewed.length) {
	console.log(`l10n-parity: ${unreviewed.length} locale(s) predate the cognate rule and are NOT yet `
		+ `held to it: ${unreviewed.join(' ')}`)
	console.log('             Their identical values are unreviewed — add scripts/l10n/locales/<loc>.json '
		+ 'as each is checked.')
}

if (failures.length === 0) {
	console.log('\nl10n-parity: OK — every locale is at full parity '
		+ '(no missing keys, no empty values, no bad plural arity)')
	process.exit(0)
}

console.error('\nl10n-parity: FAIL — a locale lost parity, or a value would render blank:')
for (const f of failures) {
	if (f.kind === 'MISSING FILE') {
		console.error(`  • ${f.set} ${f.loc}: locale file missing (${f.detail})`)
	} else if (f.kind === 'UNPARSEABLE') {
		console.error(`  • ${f.set} ${f.loc}: cannot parse (${f.detail})`)
	} else {
		console.error(`  • ${f.set} ${f.loc}: ${f.missing.length} missing key(s), `
			+ `${f.empty.length} empty value(s), ${f.identical.length} English-identical `
			+ `(${f.unjustified.length} of them unjustified), `
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
		for (const k of f.unjustified.slice(0, 6)) {
			console.error(`      identical, no recorded reason: ${JSON.stringify(k)}`)
		}
		if (f.unjustified.length > 6) {
			console.error(`      … +${f.unjustified.length - 6} more identical without a reason`)
		}
		for (const k of f.staleCognates.slice(0, 6)) {
			console.error(`      stale cognate record (value is no longer identical): ${JSON.stringify(k)}`)
		}
		for (const b of f.badArity.slice(0, 6)) {
			console.error(`      arity:     ${JSON.stringify(b.key)} has ${b.got} form(s), `
				+ `locale declares nplurals=${b.want}`)
		}
		for (const k of (f.pluralHack || []).slice(0, 6)) {
			console.error(`      {plural}:  ${JSON.stringify(k)} — banned, use a real n() plural key`)
		}
		for (const c of (f.pluralCollision || []).slice(0, 6)) {
			console.error(`      collision: ${JSON.stringify(c.k)} form ${c.i} is ${JSON.stringify(c.form)}, `
				+ 'which is also a key in this bundle — it will render that key\'s value instead')
		}
	}
}
console.error('\nEvery locale must carry every English source key.')
console.error('  missing   -> add the translation. If a new English string landed without translations,')
console.error('               that is the failure: translate it. Do not exempt the locale to get a')
console.error('               green build — see docs/l10n-workflow.md §6.15 for the procedure.')
console.error('               If the string is not translatable prose at all — an input placeholder or')
console.error('               example value — unwrap it from t() in src/ and delete the key from ALL')
console.error('               bundles including en.js, so no locale owes a value for it.')
console.error('  empty     -> renders blank.')
console.error('  arity     -> the plural array must have exactly as many forms as the locale\'s own')
console.error('               nplurals declares, or the runtime renders blank for some counts.')
console.error('  identical -> tolerated by default: a deliberate cognate is the correct value and is')
console.error('               written out so the locale stays key-for-key identical to en.js. Run with')
console.error('               --strict-identical to audit a locale for placeholder-shaped filler.')
console.error('  collision -> translatePlural re-translates the form it selects, so a form that is')
console.error('               also a key in this bundle renders that key\'s value instead of its own.')
console.error('               Reword the form so it is not a catalogue key.')
console.error('  {plural}  -> banned everywhere. A placeholder cannot carry a plural: the runtime')
console.error('               would glue an English "s" onto the value. Convert the call site to')
console.error("               n('openregister', 'object', 'objects', count) and give this locale a")
console.error('               real form array — docs/l10n-workflow.md §7.1.')
process.exit(1)
