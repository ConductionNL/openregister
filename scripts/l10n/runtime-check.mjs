#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * Drive the REAL @nextcloud/l10n against a real locale bundle.
 *
 *   node scripts/l10n/runtime-check.mjs <loc>
 *
 * ## Why this exists on top of the static gates
 *
 * Every assertion here has caught a defect that the file looked fine with:
 *
 *   - Plurals were stored under the bare singular in all 37 bundles. That renders
 *     correctly for count === 1 and falls back to ENGLISH for every other count,
 *     and it passed every static gate for months.
 *   - Catalan `schema{plural}` rendered "esquemas": masculine nouns in -a
 *     pluralise in -es, so the hardcoded English "s" produced a non-word.
 *   - A two-form array in a three-form language renders BLANK at the counts that
 *     select the missing form.
 *
 * ## The one thing to know about the runtime
 *
 * `register(app, bundle)` IGNORES a plural function passed as a third argument and
 * installs the library's own per-language `getPlural`, which reads `getLanguage()`.
 * So the file's `plural=` expression governs the arity gate, while the LIBRARY
 * governs which element renders. A harness that assumes otherwise silently reads
 * the wrong form — which happened, and briefly made three correct Slavic arrays
 * look wrong. Hence `unregister()` + `setLanguage(loc)` before every check.
 *
 * Assertions are structural and locale-agnostic on purpose, so this runs for any
 * locale without per-language expectations to maintain.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { readFileSync } from 'fs'
import path from 'path'
import vm from 'vm'
import { createRequire } from 'module'

const require = createRequire(import.meta.url)
const {
	APP_ROOT, npluralsOf, PLURAL_HACK_KEYS, loadLocaleConfig, configuredLocales,
} = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: runtime-check.mjs <loc>')
	process.exit(2)
}

const l10n = await import(path.join(APP_ROOT, 'node_modules/@nextcloud/l10n/dist/index.mjs'))
const { register, unregister, translate: t, translatePlural: n, setLanguage, getPlural } = l10n

const locFile = path.join(APP_ROOT, 'l10n', `${loc}.js`)
let translations = null
let pluralForm = null
const sandbox = { OC: { L10N: { register: (app, tr, pf) => { translations = tr; pluralForm = pf } } } }
vm.createContext(sandbox)
vm.runInContext(readFileSync(locFile, 'utf8'), sandbox, { filename: locFile })

let fails = 0
const ok = (cond, label, got) => {
	console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}${got !== undefined ? '  -> ' + JSON.stringify(got) : ''}`)
	if (!cond) fails++
}

// "This rendered exactly the English source" is the symptom the plural-key bug
// produced in all 37 bundles — and it is ALSO what a legitimate cognate looks like:
// Dutch `register`/`registers` really is the English word, and Italian `email` is
// invariable, so `1 email` is correct Italian.
//
// Nothing at runtime can tell those apart, so the justification record decides. A
// locale with scripts/l10n/locales/<loc>.json is held to it and an unrecorded
// English rendering FAILS; a locale that predates the cognate rule gets a NOTE for
// a human instead of a false accusation.
const cognates = loadLocaleConfig(loc).cognates
const enforced = configuredLocales().includes(loc)
const notEnglish = (cond, key, label, got) => {
	if (cond || Object.prototype.hasOwnProperty.call(cognates, key)) {
		ok(true, label, got)
		return
	}
	if (enforced) {
		ok(false, `${label} (and no cognate is recorded for it)`, got)
	} else {
		console.log(`NOTE  ${label} — ${loc} has no justification record, so this needs a human`
			+ `${got !== undefined ? ': ' + JSON.stringify(got) : ''}`)
	}
}

ok(translations !== null, `${loc}.js calls OC.L10N.register`, translations && Object.keys(translations).length)
if (!translations) {
	process.exit(1)
}

unregister()
setLanguage(loc)
register('openregister', translations)

const declared = npluralsOf(pluralForm)

// 1. The library must never index past any plural array. This is the direction
// that renders blank, and the only l10n defect invisible to a human reader.
const usedForms = new Set()
for (let i = 0; i <= 1000; i++) {
	usedForms.add(getPlural(i, loc))
}
const libForms = Math.max(...usedForms) + 1
const arrays = Object.entries(translations).filter(([, v]) => Array.isArray(v))
const short = arrays.filter(([, v]) => v.length < libForms)
ok(short.length === 0, `no plural array shorter than the ${libForms} form(s) the library uses`,
	short.length ? short.map(([k]) => k).slice(0, 3) : undefined)
console.log(`      declared nplurals=${declared}, library uses ${libForms}`
	+ (libForms !== declared ? '  (extra declared forms are never selected — harmless)' : ''))

// 1b. The library and the file's own `plural=` header must agree on WHICH index
// each count selects — not merely on how many forms there are.
//
// This is the defect that ordering an array "correctly" produces. Latvian ships
// the legacy gettext order in its header ([one, other, zero]) while the library
// partitions it as [zero, one, other]. Both say nplurals=3, every array has 3
// forms, arity passes, nothing renders blank, nothing renders English — and every
// single count renders the WRONG form. Assertion 1 cannot see it and neither can a
// reader, because the file's own header is the misleading part.
//
// A mismatch is only tolerated where the library uses FEWER forms than declared
// (tr and rm select 1, ga selects 3 of 5): those extra forms are unreachable, so
// no ordering of them is wrong.
//
// There are TWO kinds of mismatch and they need opposite remedies, so the check
// classifies rather than assuming Latvian's shape:
//
//   • PERMUTATION — the two partition the counts into the same groups and merely
//     label them differently. Reordering the arrays makes the locale fully
//     correct, and `pluralOrder: "library"` records that it was done. This is lv.
//
//   • BOUNDARY — the two draw the lines in different PLACES, so no permutation
//     of the arrays can agree with the header everywhere. `is` and `mk` are both
//     this, in opposite directions: the library sends 21/31/41… to Icelandic's
//     plural where the language takes the singular, and sends 11/111 to
//     Macedonian's singular where the language takes the plural. Telling someone
//     to "reorder the arrays" here is wrong advice — the only real choice is
//     which counts to be correct for, and the acknowledgement (`pluralBoundary`)
//     asserts that the residue is known and written down rather than reordered
//     away.
const headerExpr = /plural\s*=\s*([^;]+)/.exec(String(pluralForm))
if (!headerExpr) {
	ok(false, 'the bundle header declares a plural= expression', String(pluralForm).slice(0, 60))
} else {
	let headerIdx = null
	try {
		headerIdx = new Function('n', `return Number(${headerExpr[1]})`)
	} catch {
		ok(false, `the header plural= expression parses: ${headerExpr[1]}`)
	}
	if (headerIdx) {
		const disagree = []
		for (let i = 0; i <= 200; i++) {
			if (getPlural(i, loc) !== headerIdx(i)) disagree.push(i)
		}
		// Is the disagreement a pure relabelling? It is exactly when each library
		// index maps to one and only one header index across every count, and the
		// mapping is injective — that is precisely the condition under which
		// permuting the arrays can satisfy the header everywhere.
		const idxMap = new Map()
		let isPermutation = true
		for (let i = 0; i <= 200 && isPermutation; i++) {
			const l = getPlural(i, loc)
			const h = headerIdx(i)
			if (!idxMap.has(l)) idxMap.set(l, h)
			else if (idxMap.get(l) !== h) isPermutation = false
		}
		if (isPermutation && new Set(idxMap.values()).size !== idxMap.size) isPermutation = false

		const cfg = loadLocaleConfig(loc)
		const detail = disagree.slice(0, 6)
			.map(i => `n=${i}: lib ${getPlural(i, loc)} vs header ${headerIdx(i)}`)

		if (libForms < declared) {
			console.log(`NOTE  library selects only ${libForms} of the ${declared} declared form(s) for ${loc},`
				+ ' so the unreachable ones cannot be mis-ordered'
				+ (disagree.length ? ` (${disagree.length} count(s) map differently)` : ''))
		} else if (disagree.length === 0) {
			ok(true, 'library and header agree on which index each count selects')
		} else if (isPermutation) {
			// The mismatch is a property of the locale, not a defect to fix here: the
			// header comes from Transifex. Acknowledging it in locales/<loc>.json is
			// what keeps it from being silently "corrected" back to the header order.
			ok(cfg.pluralOrder === 'library',
				`${loc} header and library disagree on form ORDER at ${disagree.length} count(s) — a pure `
				+ 'relabelling, so order the ARRAYS by the library (it renders) and record '
				+ 'pluralOrder:"library" in locales/' + loc + '.json',
				cfg.pluralOrder === 'library' ? undefined : detail)
			if (cfg.pluralOrder === 'library') {
				console.log(`      ${disagree.length} count(s) relabelled; arrays are ordered by the library. See its pluralNote.`)
			}
		} else {
			// No permutation can fix this one, so the acknowledgement is a different
			// claim: that the residual wrong counts are known and recorded.
			ok(cfg.pluralBoundary === 'library',
				`${loc} header and library disagree on where the plural BOUNDARIES fall, at `
				+ `${disagree.length} count(s) — NOT a relabelling, so reordering the arrays cannot fix it. `
				+ 'Write each form to be correct across the counts the library actually routes to it, say '
				+ 'which counts stay wrong in pluralNote, and record pluralBoundary:"library" in '
				+ 'locales/' + loc + '.json',
				cfg.pluralBoundary === 'library' ? undefined : detail)
			if (cfg.pluralBoundary === 'library') {
				console.log(`      ${disagree.length} count(s) render a form the header would not choose: `
					+ `${detail.join(', ')}${disagree.length > 6 ? ', …' : ''}. Acknowledged; see its pluralNote.`)
			}
		}
	}
}

// 2. Every form the library can select must actually be reachable and render
// something that is not the English source.
for (const [key] of arrays) {
	const m = /^_([\s\S]*)_::_([\s\S]*)_$/.exec(key)
	if (!m) {
		ok(false, `plural key is stored under the identifier the runtime looks up: ${JSON.stringify(key)}`)
		continue
	}
	const [, singular, plural] = m
	// one representative count per form index the library can produce
	const perForm = new Map()
	for (let i = 0; i <= 200 && perForm.size < libForms; i++) {
		const f = getPlural(i, loc)
		if (!perForm.has(f)) perForm.set(f, i)
	}
	for (const [form, count] of [...perForm].sort((a, b) => a[0] - b[0])) {
		const got = n('openregister', singular, plural, count, { count })
		ok(!!String(got).trim(), `n(${JSON.stringify(singular.slice(0, 40))}, ${count}) renders form ${form} non-empty`, got)
		const asEnglish = [singular, plural].some(s => got === s.replace(/\{count\}/g, count).replace(/%n/g, count))
		notEnglish(!asEnglish, key,
			`n(${JSON.stringify(singular.slice(0, 40))}, ${count}) renders form ${form} translated`, got)
	}
}

// 3. The {plural} source hack. The call sites interpolate a literal "s" or "", so
// a locale has exactly two correct options, and which one it took is readable from
// whether the value still carries the placeholder:
//
//   KEEPS it   — only valid where the plural genuinely is +s. `es` does this for
//                all five, `ca` for four ("fitxer" -> "fitxers"). Then the value
//                MUST vary with the count, and asserting count-stability would be
//                exactly backwards.
//   DROPS it   — every other language, because no suffix can be right. Then the
//                value cannot vary, and a residual "{plural}" would reach the user.
//
// Either way the rendered value must not still be the English source. Catalan is
// the reason this is split: `schema{plural}` is "esquema/esquemes", which ends in
// an "s" legitimately (the slash device), so a blanket no-trailing-s rule is wrong.
for (const key of PLURAL_HACK_KEYS) {
	if (!(key in translations)) continue
	const keepsPlaceholder = String(translations[key]).includes('{plural}')
	const one = t('openregister', key, { plural: '' })
	const many = t('openregister', key, { plural: 's' })

	if (keepsPlaceholder) {
		ok(many === one + 's', `t(${JSON.stringify(key)}) keeps {plural} and substitutes it`, [one, many])
	} else {
		ok(one === many, `t(${JSON.stringify(key)}) dropped {plural}, so it cannot vary with count`, one)
	}
	ok(!one.includes('{plural}') && !many.includes('{plural}'),
		`t(${JSON.stringify(key)}) leaves no {plural} residue`, one)
	// Still the untranslated English, e.g. "object" / "objects"? Dutch `register` /
	// `registers` trips this legitimately, hence notEnglish() rather than ok().
	const asEnglish = one === key.replace('{plural}', '') && many === key.replace('{plural}', 's')
	notEnglish(!asEnglish, key, `t(${JSON.stringify(key)}) is not left as the English source`, [one, many])
}

console.log(fails === 0 ? '\nALL RUNTIME CHECKS PASS' : `\n${fails} RUNTIME CHECK(S) FAILED`)
process.exitCode = fails ? 1 : 0
