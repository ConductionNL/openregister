#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * Harvest exact-key translation candidates for one locale from Nextcloud core and
 * from the sibling Conduction apps.
 *
 *   node scripts/l10n/harvest.js <loc> [worklist.json]
 *
 * ## Output is CANDIDATES, never answers
 *
 * Hit rate is only 2–7% (`ca` 22 of 1037, `hr` 67 of 1024, `lt` 69 of 1020), and
 * the failures are not typos — they are correct translations of a DIFFERENT sense,
 * which pass every automated check. Every hit must be verified at its call site.
 * Confirmed offenders, all shipped by core:
 *
 *   Right   — "Dešinė" / "Desno" / "Rechts", i.e. right-ALIGNED. This app means an
 *             RBAC permission.
 *   Bucket  — "Amazon S3 saugykla" in lt, "Korv" (a basket) in et, "Buket" (a
 *             bouquet of flowers) in tr. This app means a histogram bin.
 *   Refresh — "Yenlle" in tr, a typo. Do not take typos.
 *
 * Sibling apps are not automatically right either: openconnector's hr bundle has
 * Mappings -> "Mappingi", a non-standard transliteration.
 *
 * ## Catalogues filed under the wrong language are dropped, loudly
 *
 * A whole catalogue can be the wrong language. openbuild ships ONE Croatian
 * catalogue under seven names — `bs cs hr mk sk sl sr` are value-identical, all
 * 586 keys — plus `da == sv` and `de == lb`. Harvesting `sk` from it offers
 * "Dodaj shemu" and "Radnje" as Slovak. Each hit reads as a plausible Slavic
 * translation of the right key, so nothing downstream would catch it.
 *
 * So every source is checked against its own app's OTHER locales, and a catalogue
 * that duplicates one of them is dropped with the duplicate named. This is a
 * measurement, not a heuristic: two real languages do not agree on hundreds of
 * prose values. Dropping only loses candidates, which were never answers.
 *
 * The locale is a REQUIRED argument. It used to be hardcoded, and a copied script
 * silently harvested the previous language for a whole pass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const {
	APP_ROOT, loadJsTranslations, isIdentical, loadLocaleConfig, coreCatalogues,
} = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: harvest.js <loc> [worklist.json]')
	process.exit(2)
}
const worklistFile = process.argv[3]

// Where core and the sibling apps live, relative to this app. Overridable so the
// script is not tied to one developer's checkout layout.
const WORKSPACE = process.env.L10N_WORKSPACE || path.resolve(APP_ROOT, '..', '..')
const SERVER = process.env.L10N_SERVER_DIR || path.join(WORKSPACE, 'server')
const CUSTOM = process.env.L10N_APPS_DIR || path.join(WORKSPACE, 'apps-custom')

// Region variants (Estonian ships as et_EE) come from lib's coreCatalogues, which
// matches the directory instead of guessing the region code. Guessing it here as
// `${loc}_${loc.toUpperCase()}` produced "et_ET" and silently skipped every
// Estonian catalogue in core.

/** The keys this locale still needs: absent ∪ unjustified-identical. */
function worklist() {
	if (worklistFile) {
		return new Map(JSON.parse(fs.readFileSync(worklistFile, 'utf8')).map(x => [x.key, x.en]))
	}
	const en = loadJsTranslations(path.join(APP_ROOT, 'l10n', 'en.js')).translations
	const cur = loadJsTranslations(path.join(APP_ROOT, 'l10n', `${loc}.js`)).translations
	const cfg = loadLocaleConfig(loc)
	const need = new Map()
	for (const [k, v] of Object.entries(en)) {
		if (!(k in cur) || (isIdentical(k, cur[k]) && !(k in cfg.cognates))) need.set(k, v)
	}
	return need
}

const need = worklist()

const sources = []
try {
	sources.push(...coreCatalogues(loc))
} catch {
	// Core absent, or this locale not shipped there. The sibling apps below may
	// still supply candidates, and the no-sources check after this block covers
	// the case where nothing at all was found.
}
if (fs.existsSync(CUSTOM)) {
	for (const a of fs.readdirSync(CUSTOM)) {
		if (a === path.basename(APP_ROOT)) continue
		for (const ext of ['js', 'json']) {
			const f = path.join(CUSTOM, a, 'l10n', `${loc}.${ext}`)
			if (fs.existsSync(f)) sources.push(f)
		}
	}
}
if (!sources.length) {
	console.error(`no ${loc} sources found. Looked under ${SERVER} and ${CUSTOM};`)
	console.error('set L10N_SERVER_DIR / L10N_APPS_DIR if your checkout differs.')
	process.exit(1)
}

/**
 * Read either a backend .json catalogue or a frontend .js bundle.
 *
 * @param {string} f Absolute path to a locale file.
 * @return {object} Its translations, or {} when unparseable.
 */
function readBundle(f) {
	const raw = fs.readFileSync(f, 'utf8')
	if (f.endsWith('.json')) {
		try {
			return JSON.parse(raw).translations || {}
		} catch {
			return {}
		}
	}
	const i = raw.indexOf('{', raw.indexOf('OC.L10N.register'))
	const j = raw.lastIndexOf('}')
	if (i < 0 || j < i) return {}
	try {
		return JSON.parse(raw.slice(i, j + 1))
	} catch {
		return {}
	}
}

/** A locale filename, so siblings of a source can be enumerated. */
const LOCALE_FILE_RE = /^([a-z]{2,3}(?:_[A-Za-z]{2,3})?)\.(js|json)$/

/**
 * Signature of a catalogue's key→value mapping, order-independent so a
 * re-serialized copy still matches its original.
 *
 * @param {object} tr Translations object.
 * @return {string} Signature, or '' when there is nothing to compare.
 */
function catalogueSig(tr) {
	const e = Object.entries(tr)
	return e.length ? JSON.stringify(e.sort((a, b) => (a[0] < b[0] ? -1 : 1))) : ''
}

/**
 * Which OTHER locale in the same app this file is a duplicate of, if any. A
 * catalogue shared with another language is not this language's translation,
 * whichever of the two names is the wrong one — see the header.
 *
 * @param {string} f Absolute path to a `<loc>.<ext>` locale file.
 * @return {string[]} Locale codes it duplicates, possibly empty.
 */
function duplicatedLocales(f) {
	const sig = catalogueSig(readBundle(f))
	if (!sig) return []
	const dir = path.dirname(f)
	// Compare the BASE language, not the locale name. Estonian and Lithuanian ship
	// as et_EE / lt_LT, and core's et_EE.js and et_EE.json really are value-
	// identical — so matching on the name alone dropped 33 of 40 sources for each
	// of those two locales, reporting the same language under two spellings as a
	// mislabelled one.
	const base = l => l.split('_')[0]
	const out = []
	for (const other of fs.readdirSync(dir).sort()) {
		const m = LOCALE_FILE_RE.exec(other)
		if (!m || base(m[1]) === base(loc)) continue
		if (catalogueSig(readBundle(path.join(dir, other))) === sig) out.push(other)
	}
	return out
}

const mislabelled = []
const usable = sources.filter(f => {
	const dup = duplicatedLocales(f)
	if (!dup.length) return true
	mislabelled.push([path.relative(WORKSPACE, f), dup])
	return false
})
if (mislabelled.length) {
	console.log(`DROPPED ${mislabelled.length} source(s) filed under ${loc} but identical to another locale:`)
	for (const [f, dup] of mislabelled) {
		console.log(`  ${f} == ${dup.join(', ')}`)
	}
}
if (!usable.length) {
	console.error(`every ${loc} source was a duplicate of another locale — nothing to harvest.`)
	process.exit(1)
}

const hits = new Map()
for (const f of usable) {
	for (const [k, v] of Object.entries(readBundle(f))) {
		if (!need.has(k)) continue
		// An untranslated source value teaches us nothing.
		if (typeof v === 'string') {
			if (!v || v === k) continue
		} else if (Array.isArray(v)) {
			if (!v.length || v.every(x => !x)) continue
		} else {
			continue
		}
		const sig = JSON.stringify(v)
		if (!hits.has(k)) hits.set(k, new Map())
		const m = hits.get(k)
		if (!m.has(sig)) m.set(sig, [])
		m.get(sig).push(path.relative(WORKSPACE, f))
	}
}

const out = [...hits.entries()]
	.map(([key, variants]) => ({
		key,
		en: need.get(key),
		candidates: [...variants.entries()]
			.sort((a, b) => b[1].length - a[1].length)
			.map(([v, srcs]) => ({ value: JSON.parse(v), n: srcs.length, where: srcs.slice(0, 3) })),
	}))
	.sort((a, b) => String(a.key).localeCompare(String(b.key)))

const dest = path.join(__dirname, `harvest-${loc}.json`)
fs.writeFileSync(dest, JSON.stringify(out, null, 1) + '\n')

console.log(`sources scanned: ${usable.length}`
	+ (mislabelled.length ? ` (${mislabelled.length} dropped as another locale)` : ''))
console.log(`harvest hits: ${out.length} of ${need.size} needed (${(100 * out.length / (need.size || 1)).toFixed(1)}%)`)
console.log(`  unanimous:   ${out.filter(o => o.candidates.length === 1).length}`)
console.log(`  conflicting: ${out.filter(o => o.candidates.length > 1).length}`)
console.log(`written to ${path.relative(APP_ROOT, dest)} — VERIFY EVERY HIT AT ITS CALL SITE`)
