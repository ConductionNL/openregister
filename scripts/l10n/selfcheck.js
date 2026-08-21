#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * Full verification for one locale pass. Every assertion is measured against the
 * files; nothing is assumed.
 *
 *   node scripts/l10n/selfcheck.js <loc>
 *
 * Run this before committing a locale. It is deliberately stricter than
 * test:l10n:parity, which is the CI gate and has to stay fast and dependency-free:
 * this one also diffs against HEAD to prove no pre-existing translation was lost
 * or silently altered, and round-trips the serializer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const vm = require('vm')
const {
	loadJsTranslations, serializeJs, APP_ROOT, isIdentical, hasIdenticalForm, placeholders, npluralsOf,
	MORPHOLOGY_PLACEHOLDER, loadLocaleConfig, loadDetector, bundleAtHead,
} = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: selfcheck.js <loc>')
	process.exit(2)
}

const locFile = path.join(APP_ROOT, 'l10n', `${loc}.js`)
const en = loadJsTranslations(path.join(APP_ROOT, 'l10n', 'en.js'))
const cur = loadJsTranslations(locFile)
const cfg = loadLocaleConfig(loc)
const detector = loadDetector(loc)
const head = bundleAtHead(loc)

const NP = npluralsOf(cur.pluralForm)
const enKeys = new Set(Object.keys(en.translations))
const keys = Object.keys(cur.translations)

let fails = 0
function check(name, ok, detail) {
	console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`)
	if (!ok) {
		fails++
	}
}

// Has this locale been through the recorded-justification workflow at all? Sixteen
// locales were finished before it existed. For those, "no cognate record" and "no
// detector" are the KNOWN GAP, not a regression in the bundle — reporting them as
// FAIL would say the locale is broken when it is merely unreviewed. Everything that
// does not depend on a record is still checked in full.
const recorded = Boolean(cfg.register) || Object.keys(cfg.cognates).length > 0
function checkRecorded(name, ok, detail) {
	if (recorded) {
		check(name, ok, detail)
	} else {
		console.log(`SKIP  ${name} — ${loc} predates the cognate rule and has no `
			+ `scripts/l10n/locales/${loc}.json${detail ? ' (' + detail + ')' : ''}`)
	}
}

// 1. key-for-key parity, both directions
const absent = [...enKeys].filter(k => !(k in cur.translations))
const extra = keys.filter(k => !enKeys.has(k))
check('key-for-key parity with en.js', absent.length === 0 && extra.length === 0,
	`${absent.length} absent, ${extra.length} extra`)

// 2. no empty values, no edge or doubled HORIZONTAL whitespace ("\n\n" is a
// legitimate paragraph break the English source carries too)
const empties = []
const ws = []
for (const [k, v] of Object.entries(cur.translations)) {
	for (const x of Array.isArray(v) ? v : [v]) {
		if (!String(x).trim()) {
			empties.push(k)
		}
		if (/^\s|\s$|[ \t]{2,}/.test(String(x))) {
			ws.push([k, x])
		}
	}
}
check('no empty values', empties.length === 0, `${empties.length}`)
check('no edge/doubled horizontal whitespace', ws.length === 0, ws.length ? JSON.stringify(ws.slice(0, 3)) : '')

// 3. plural arity against this locale's OWN declared nplurals
const arity = Object.entries(cur.translations).filter(([, v]) => Array.isArray(v) && v.length !== NP)
check(`every plural array has ${NP} forms`, arity.length === 0, `${arity.length} wrong`)

// 4. value===key only for recorded cognates, and no cognate recorded for a key
// that is not actually identical (that would be a stale permission slip)
const identical = keys.filter(k => isIdentical(k, cur.translations[k]))
const undocumented = identical.filter(k => !(k in cfg.cognates))
checkRecorded('no undocumented value === key', undocumented.length === 0,
	undocumented.length ? JSON.stringify(undocumented.slice(0, 5)) : `${identical.length} recorded cognates`)
// A record is stale only when NOTHING is left for it to excuse. For a plural key
// that means no form renders the English source — requiring every form to match
// (isIdentical) would call Romanian's email array stale while runtime-check.mjs
// still needs its record to excuse the singular. See hasIdenticalForm in lib.js.
const staleCognates = Object.keys(cfg.cognates)
	.filter(k => !identical.includes(k)
		&& !(k in cur.translations && hasIdenticalForm(k, cur.translations[k])))
checkRecorded('no stale cognate record', staleCognates.length === 0,
	staleCognates.length ? JSON.stringify(staleCognates.slice(0, 5)) : `${Object.keys(cfg.cognates).length} all in use`)
const thin = Object.entries(cfg.cognates).filter(([, r]) => String(r).trim().length < 15).map(([k]) => k)
check('every cognate reason is a real justification', thin.length === 0,
	thin.length ? JSON.stringify(thin) : '')

// 5. placeholders preserved in BOTH directions; only the {plural} source hack may drop
const drift = []
for (const k of keys) {
	const enPh = new Set()
	for (const f of Array.isArray(en.translations[k]) ? en.translations[k] : [en.translations[k]]) {
		for (const p of placeholders(f)) {
			enPh.add(p)
		}
	}
	const lPh = new Set()
	for (const f of Array.isArray(cur.translations[k]) ? cur.translations[k] : [cur.translations[k]]) {
		for (const p of placeholders(f)) {
			lPh.add(p)
		}
	}
	const dropped = [...enPh].filter(x => !lPh.has(x))
	const added = [...lPh].filter(x => !enPh.has(x))
	if (dropped.length || added.length) {
		drift.push([k, [...enPh], [...lPh]])
	}
}
check('placeholders preserved both ways', drift.length === 0,
	drift.length ? JSON.stringify(drift.slice(0, 3)) : '')

// `{plural}` is BANNED outright — it is English morphology assembled from a
// placeholder, and the five source keys that forced locales to carry it are real
// n() calls now. This is the fast local mirror of the same ban in
// tests/l10n/check-l10n-parity.js; the drift check above would also catch a
// `{plural}` that en.js does not have, but only as an anonymous "added
// placeholder", which does not tell the reader what to do about it.
const strayPlural = keys.filter(k => [].concat(cur.translations[k])
	.some(x => MORPHOLOGY_PLACEHOLDER.test(String(x))))
check('no {plural} in any value (use a real n() plural key)', strayPlural.length === 0,
	strayPlural.length ? JSON.stringify(strayPlural) : '')

// 6. register: gate on the DEVIATION from the measured register, never on a
// carried-over assumption. Five consecutive locales measured differently.
if (!cfg.register && String(cfg.registerNotMeasured || '').trim().length >= 15) {
	// A config that exists for a narrower reason than a full pass — see
	// registerNotMeasured in lib.js. SKIP, never PASS: nothing has been verified.
	console.log(`SKIP  register not measured for ${loc} — ${cfg.registerNotMeasured}`)
} else if (!detector || !cfg.register) {
	checkRecorded('register detector present and register measured', false,
		`detector=${detector ? 'yes' : 'MISSING'}, register=${cfg.register || 'NOT MEASURED'}`)
} else {
	const ctl = detector.runControls()
	check('register detector controls', ctl.fail === 0, `${ctl.total - ctl.fail}/${ctl.total}`)
	const deviation = cfg.register === 'formal' ? 'informal' : 'formal'
	const dev = []
	for (const [k, v] of Object.entries(cur.translations)) {
		for (const x of Array.isArray(v) ? v : [v]) {
			const s = detector.score(String(x))
			if (deviation === 'informal' ? s.i > 0 : s.f > 0) {
				dev.push([k, x])
			}
		}
	}
	check(`zero ${deviation} forms — ${loc} prose is ${cfg.register}`, dev.length === 0,
		dev.length ? JSON.stringify(dev.slice(0, 5)) : '')
}

// 7. no pre-existing translation lost or silently altered. A value that was
// value===key at HEAD was a placeholder, so replacing it is the point of the pass.
if (head === null) {
	check('HEAD baseline available', false, `git show HEAD:l10n/${loc}.js failed`)
} else {
	const lost = []
	const altered = []
	for (const [k, v] of Object.entries(head)) {
		const wasPlaceholder = typeof v === 'string' && v === k
		if (!(k in cur.translations)) {
			// A key en.js no longer carries was DELETED from the catalogue, not lost
			// from this bundle. Without this the check fires on every locale at once
			// for a single deliberate removal — which is exactly what the `{plural}`
			// conversion did to five keys across all 37 files — and 36 identical
			// failures read as a broken pass rather than one intended change. A key
			// still in en.js and missing here is the real defect and still fires.
			if (k in en.translations) lost.push(k)
			continue
		}
		const now = cur.translations[k]
		if (JSON.stringify(now) !== JSON.stringify(v) && !wasPlaceholder && !(k in cfg.corrections)) {
			altered.push([k, v, now])
		}
	}
	check('no pre-existing translation lost', lost.length === 0, lost.length ? JSON.stringify(lost.slice(0, 5)) : '')
	check('no pre-existing real value altered without a recorded reason', altered.length === 0,
		altered.length ? JSON.stringify(altered.slice(0, 3)) : `${Object.keys(cfg.corrections).length} audited correction(s)`)
}

// 8. loads under OC.L10N.register
let registered = null
const ctx = { OC: { L10N: { register: (app, t, p) => { registered = { app, t, p } } } } }
vm.createContext(ctx)
try {
	vm.runInContext(fs.readFileSync(locFile, 'utf8'), ctx)
	check('loads under OC.L10N.register',
		registered !== null && Object.keys(registered.t).length === keys.length,
		`app=${registered && registered.app}, ${registered ? Object.keys(registered.t).length : 0} keys`)
} catch (e) {
	check('loads under OC.L10N.register', false, e.message)
}

// 9. serializer round-trip byte-exact, so a Transifex regeneration is a no-op
const round = serializeJs({ app: cur.app, translations: cur.translations, pluralForm: cur.pluralForm })
check('serializer round-trip byte-exact', round === fs.readFileSync(locFile, 'utf8'))

// 10. plural arrays must not be N copies of one form where the language
// distinguishes them. Legitimate exceptions exist — Hungarian does not pluralise
// after a numeral, Swedish "objekt" is invariant, Italian "email" is invariant, and
// a key with no {count} reads the same for several counts — so this reports rather
// than fails, and a human confirms.
const plurals = Object.entries(cur.translations).filter(([, v]) => Array.isArray(v))
const flat = plurals.filter(([, v]) => new Set(v).size < 2)
console.log(`${flat.length === 0 ? 'PASS' : 'NOTE'}  plural arrays with every form identical — confirm each is genuine`
	+ ` — ${flat.length} of ${plurals.length}`)
for (const [k, v] of flat.slice(0, 5)) {
	console.log(`        ${JSON.stringify(k).slice(0, 70)} -> ${JSON.stringify(v[0])}`)
}

console.log(`\n${loc}: ${keys.length} keys (en ${enKeys.size}), ${identical.length} recorded cognates,`
	+ ` register ${cfg.register || '?'}, nplurals=${NP}`)
console.log(fails === 0 ? '\nALL CHECKS PASS' : `\n${fails} CHECK(S) FAILED`)
process.exitCode = fails ? 1 : 0
