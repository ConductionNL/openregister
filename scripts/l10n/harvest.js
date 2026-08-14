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
 * The locale is a REQUIRED argument. It used to be hardcoded, and a copied script
 * silently harvested the previous language for a whole pass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const { APP_ROOT, loadJsTranslations, isIdentical, loadLocaleConfig } = require('./lib.js')

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

// Some languages ship as xx_XX (Estonian is et_EE), so glob both spellings.
const VARIANTS = [loc, `${loc}_${loc.toUpperCase()}`]

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
if (fs.existsSync(SERVER)) {
	for (const p of ['core/l10n', 'lib/l10n']) {
		for (const c of VARIANTS) {
			const f = path.join(SERVER, p, `${c}.json`)
			if (fs.existsSync(f)) sources.push(f)
		}
	}
	const appsDir = path.join(SERVER, 'apps')
	if (fs.existsSync(appsDir)) {
		for (const a of fs.readdirSync(appsDir)) {
			for (const c of VARIANTS) {
				const f = path.join(appsDir, a, 'l10n', `${c}.json`)
				if (fs.existsSync(f)) sources.push(f)
			}
		}
	}
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

const hits = new Map()
for (const f of sources) {
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

console.log(`sources scanned: ${sources.length}`)
console.log(`harvest hits: ${out.length} of ${need.size} needed (${(100 * out.length / (need.size || 1)).toFixed(1)}%)`)
console.log(`  unanimous:   ${out.filter(o => o.candidates.length === 1).length}`)
console.log(`  conflicting: ${out.filter(o => o.candidates.length > 1).length}`)
console.log(`written to ${path.relative(APP_ROOT, dest)} — VERIFY EVERY HIT AT ITS CALL SITE`)
