#!/usr/bin/env node
 
/**
 * Read-only status and worklist for one locale.
 *
 *   node scripts/l10n/batch.js status <loc>
 *   node scripts/l10n/batch.js absent <loc>      # JSON worklist on stdout
 *
 * ## The worklist is `absent ∪ identical`, every round
 *
 * `absent` deliberately returns keys that are MISSING **and** keys whose value is
 * byte-identical to the key. Regenerating a worklist with only `!(k in loc)` finds
 * the absent ones and silently skips the remaining placeholders — that happened
 * mid-Catalan, where 26 were missed and only the status counts caught it.
 *
 * A key is excluded from the worklist when its identical value is a RECORDED
 * cognate in scripts/l10n/locales/<loc>.json, because that is finished work.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const path = require('path')
const {
	loadJsTranslations, APP_ROOT, isIdentical, npluralsOf, loadLocaleConfig,
} = require('./lib.js')

const cmd = process.argv[2]
const loc = process.argv[3]
if (!cmd || !loc) {
	console.error('usage: batch.js status|absent <loc>')
	process.exit(2)
}

const en = loadJsTranslations(path.join(APP_ROOT, 'l10n', 'en.js'))
const cur = loadJsTranslations(path.join(APP_ROOT, 'l10n', `${loc}.js`))
const cfg = loadLocaleConfig(loc)
const NP = npluralsOf(cur.pluralForm)
const enKeys = Object.keys(en.translations)

const absent = enKeys.filter(k => !(k in cur.translations))
const identical = enKeys.filter(k => k in cur.translations && isIdentical(k, cur.translations[k]))
const unjustified = identical.filter(k => !(k in cfg.cognates))

if (cmd === 'absent') {
	const out = []
	for (const k of enKeys) {
		if (!(k in cur.translations)) {
			out.push({ key: k, en: en.translations[k], why: 'absent' })
		} else if (isIdentical(k, cur.translations[k]) && !(k in cfg.cognates)) {
			out.push({ key: k, en: en.translations[k], why: 'identical' })
		}
	}
	console.log(JSON.stringify(out, null, 1))
	process.exit(0)
}

if (cmd === 'status') {
	const badArity = Object.entries(cur.translations)
		.filter(([, v]) => Array.isArray(v) && v.length !== NP)
		.map(([k, v]) => [k, v.length])
	const empty = []
	for (const [k, v] of Object.entries(cur.translations)) {
		for (const x of Array.isArray(v) ? v : [v]) {
			if (!String(x).trim()) {
				empty.push(k)
			}
		}
	}
	const extra = Object.keys(cur.translations).filter(k => !(k in en.translations))

	console.log(`${loc}: ${Object.keys(cur.translations).length} keys / en ${enKeys.length}`)
	console.log(`  plural header:      ${cur.pluralForm}`)
	console.log(`                      -> nplurals=${NP}`)
	console.log(`  register (measured): ${cfg.register || 'NOT YET MEASURED'}`)
	console.log(`  absent:              ${absent.length}`)
	console.log(`  value===key:         ${identical.length} (${Object.keys(cfg.cognates).length} recorded as cognates)`)
	console.log(`  -> still to do:      ${absent.length + unjustified.length}`)
	if (unjustified.length) {
		console.log(`  UNJUSTIFIED identical: ${unjustified.length}`)
		for (const k of unjustified.slice(0, 10)) {
			console.log(`     ${JSON.stringify(k)}`)
		}
		if (unjustified.length > 10) {
			console.log(`     … +${unjustified.length - 10} more`)
		}
	}
	console.log(`  bad arity:           ${badArity.length}${badArity.length ? ' ' + JSON.stringify(badArity) : ''}`)
	console.log(`  empty values:        ${empty.length}`)
	console.log(`  extra keys:          ${extra.length}`)
	process.exit(0)
}

console.error(`unknown command: ${cmd}`)
process.exit(2)
