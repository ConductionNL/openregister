#!/usr/bin/env node
 
/**
 * Fetch hunspell dictionaries for the locales this app translates.
 *
 *   node scripts/l10n/fetch-dicts.js [<loc> …]      (default: all mapped)
 *
 * Dictionaries land in scripts/l10n/dicts/ (gitignored — they are third-party
 * data, tens of MB, and licensed variously). Re-running skips what is present.
 *
 * WHY NOT DISTRO PACKAGES. Arch/CachyOS official repos carry hunspell dictionaries
 * for only ~10 of the 36 locales here — `sk`, `cs`, `sl`, `hr`, `bg`, `lt`, `lv`,
 * `et`, `is`, `ga`, `mt` and the Nordics are all absent — and the package names
 * differ on every distro, which makes "install the dictionaries" unreproducible on
 * a second machine. LibreOffice's dictionary repo carries 30 of our 36, needs no
 * root, and pins identically everywhere. Prefer this over `pacman`/`apt`.
 *
 * SIX LOCALES HAVE NO DICTIONARY HERE: fi, ga, lb, mk, mt, rm. Finnish needs
 * Voikko (morphological, not hunspell); the rest are low-resource — which is
 * exactly where a spell pass would have helped most, since `ga`, `mt` and `is`
 * are where the garbled-word class concentrated. Treat their absence as a known
 * gap, not as "those locales are clean".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')
const { APP_ROOT } = require('./lib.js')

// locale -> LibreOffice dictionaries directory / basename.
const MAP = {
	be: 'be_BY/be-official',
	bg: 'bg_BG/bg_BG',
	bs: 'bs_BA/bs_BA',
	ca: 'ca/dictionaries/ca',
	cs: 'cs_CZ/cs_CZ',
	da: 'da_DK/da_DK',
	de: 'de/de_DE_frami',
	el: 'el_GR/el_GR',
	en: 'en/en_GB',
	es: 'es/es_ES',
	et: 'et_EE/et_EE',
	fr: 'fr_FR/dictionaries/fr',
	hr: 'hr_HR/hr_HR',
	hu: 'hu_HU/hu_HU',
	is: 'is/is',
	it: 'it_IT/it_IT',
	lt: 'lt_LT/lt',
	lv: 'lv_LV/lv_LV',
	nb: 'no/nb_NO',
	nl: 'nl_NL/nl_NL',
	pl: 'pl_PL/pl_PL',
	pt: 'pt_PT/pt_PT',
	ro: 'ro/ro_RO',
	ru: 'ru_RU/ru_RU',
	sk: 'sk_SK/sk_SK',
	sl: 'sl_SI/sl_SI',
	sq: 'sq_AL/sq_AL',
	sr: 'sr/sr',
	sv: 'sv_SE/dictionaries/sv_SE',
	tr: 'tr_TR/tr_TR',
	uk: 'uk_UA/uk_UA',
}
const UNAVAILABLE = ['fi', 'ga', 'lb', 'mk', 'mt', 'rm']

const BASE = 'https://raw.githubusercontent.com/LibreOffice/dictionaries/master'
const DICTS = path.join(APP_ROOT, 'scripts', 'l10n', 'dicts')

const wanted = process.argv.slice(2).filter((a) => !a.startsWith('--'))
const locales = wanted.length ? wanted : Object.keys(MAP)

fs.mkdirSync(DICTS, { recursive: true })

let ok = 0
let skipped = 0
const failed = []

for (const loc of locales) {
	if (UNAVAILABLE.includes(loc)) {
		console.log(`  --  ${loc}: no hunspell dictionary published (see header)`)
		continue
	}
	const src = MAP[loc]
	if (!src) {
		failed.push(`${loc} (not mapped)`)
		continue
	}
	const affOut = path.join(DICTS, `${loc}.aff`)
	const dicOut = path.join(DICTS, `${loc}.dic`)
	if (fs.existsSync(affOut) && fs.existsSync(dicOut)) {
		skipped++
		continue
	}
	let bad = false
	for (const [ext, out] of [['aff', affOut], ['dic', dicOut]]) {
		try {
			execFileSync('curl', ['-sfL', '--max-time', '120', '-o', out, `${BASE}/${src}.${ext}`])
			if (!fs.statSync(out).size) throw new Error('empty')
		} catch {
			bad = true
			try { fs.unlinkSync(out) } catch { /* nothing to clean */ }
		}
	}
	if (bad) {
		failed.push(loc)
		try { fs.unlinkSync(affOut) } catch { /* nothing to clean */ }
		console.log(`  FAIL ${loc}: could not fetch ${src}`)
	} else {
		ok++
		console.log(`  ok   ${loc}: ${src}`)
	}
}

console.log()
console.log(`fetch-dicts: ${ok} fetched, ${skipped} already present, ${failed.length} failed`
	+ `, ${UNAVAILABLE.length} unavailable upstream (${UNAVAILABLE.join(' ')})`)
if (failed.length) {
	console.log(`failed: ${failed.join(', ')}`)
	process.exit(1)
}
