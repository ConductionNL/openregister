#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// build-l10n-js.js — regenerate l10n/<locale>.js from l10n/<locale>.json.
//
// WHY THIS EXISTS
//
//   Nextcloud loads a locale catalogue in TWO formats and neither substitutes
//   for the other:
//
//     l10n/<locale>.json — read server-side by PHP `$l->t()`.
//     l10n/<locale>.js   — an `OC.L10N.register(<appId>, {…}, <pluralForm>)`
//                          call, the ONLY thing the browser ever sees. Raw
//                          JSON is not served from an app directory at all:
//                          `/custom_apps/humaniq/l10n/nl.json` is a 404.
//
//   The pair was hand-maintained, which is exactly the shape of drift the
//   parity guard exists to catch: a key added to the .json and forgotten in
//   the .js renders in English for every browser while every server-rendered
//   string is Dutch, and nothing throws. Deriving the .js removes the chance
//   to forget.
//
//   `npm run check:l10n` still asserts the two carry identical pairs — this
//   script makes that assertion cheap to satisfy rather than replacing it.
//
// EVERY locale in l10n/ is generated, not just en/nl. larpinq ships 37 locale
// catalogues and had .js for none of them, so 35 languages' translations were
// unreachable on top of the two this script was first written for.
//
// pluralForm: taken from the catalogue when it declares one. When it does not,
// the fallback is the two-form rule `nplurals=2; plural=(n != 1);` — which is
// what every generated catalogue in this fleet already carries, including for
// languages that genuinely have more forms (cs, pl, ru). That is a known
// simplification, not a verified per-language rule: a catalogue that starts
// using plural strings in such a language needs its real rule declared in the
// JSON, which this script will then honour.
//
// Usage:
//   node scripts/build-l10n-js.js            (npm run l10n:build)
//   node scripts/build-l10n-js.js --check    exit 1 if any .js is stale
//
// Exit codes:
//   0 — every .js written (or already current, under --check)
//   1 — a catalogue is malformed, or --check found a stale .js

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

/** Fallback when a catalogue declares no pluralForm — see the header note. */
const DEFAULT_PLURAL_FORM = 'nplurals=2; plural=(n != 1);'
const L10N_DIR = path.join(REPO_ROOT, 'l10n')

/**
 * The app id the browser catalogue must register under. Read from
 * appinfo/info.xml rather than hard-coded: this app has already been renamed
 * once (hrmq -> humaniq), and a catalogue registered under the old id is
 * silently ignored by `t()` — every string falls back to its English key with
 * no error anywhere.
 *
 * @return {string} the <id> declared in appinfo/info.xml
 */
function appId() {
	const xml = fs.readFileSync(path.join(REPO_ROOT, 'appinfo', 'info.xml'), 'utf8')
	const match = xml.match(/<id>([^<]+)<\/id>/)
	if (match === null) {
		console.error('appinfo/info.xml declares no <id>')
		process.exit(1)
	}
	return match[1].trim()
}

/**
 * Render one catalogue as the `OC.L10N.register` call the browser expects.
 *
 * @param {string} id - the app id to register under
 * @param {object} translations - key -> translation
 * @param {string} pluralForm - the catalogue's gettext plural rule
 * @return {string} the .js file body
 */
function renderJs(id, translations, pluralForm) {
	const body = Object.keys(translations)
		.map(
			(key) =>
				`        ${JSON.stringify(key)}: ${JSON.stringify(translations[key])}`,
		)
		.join(',\n')
	return [
		'OC.L10N.register(',
		`    ${JSON.stringify(id)},`,
		'    {',
		body,
		'    },',
		`    ${JSON.stringify(pluralForm)}`,
		')',
		'',
	].join('\n')
}

function main() {
	const check = process.argv.includes('--check')
	const id = appId()
	const stale = []

	const locales = fs
		.readdirSync(L10N_DIR)
		// Dotfiles are never locale catalogues. `l10n/.schema-l10n-baseline.json`
		// sits here so prettier ignores it, and without this guard it was read as
		// a locale named `.schema-l10n-baseline` and failed for having no
		// `translations` key.
		.filter((f) => f.endsWith('.json') && !f.startsWith('.'))
		.map((f) => f.slice(0, -5))
		.sort()
	if (locales.length === 0) {
		console.error('l10n/ holds no <locale>.json catalogue to generate from')
		process.exit(1)
	}

	for (const locale of locales) {
		const jsonFile = path.join(L10N_DIR, `${locale}.json`)
		const jsFile = path.join(L10N_DIR, `${locale}.js`)

		let doc
		try {
			doc = JSON.parse(fs.readFileSync(jsonFile, 'utf8'))
		} catch (error) {
			console.error(`l10n/${locale}.json does not parse: ${error.message}`)
			process.exit(1)
		}
		if (doc.translations === undefined) {
			console.error(`l10n/${locale}.json is missing "translations"`)
			process.exit(1)
		}

		const rendered = renderJs(
			id,
			doc.translations,
			doc.pluralForm || DEFAULT_PLURAL_FORM,
		)
		const current = fs.existsSync(jsFile)
			? fs.readFileSync(jsFile, 'utf8')
			: null

		if (current === rendered) {
			console.log(
				`  ✓ l10n/${locale}.js up to date (${Object.keys(doc.translations).length} keys)`,
			)
			continue
		}
		if (check) {
			stale.push(`l10n/${locale}.js`)
			continue
		}
		fs.writeFileSync(jsFile, rendered)
		console.log(
			`  ✎ l10n/${locale}.js written (${Object.keys(doc.translations).length} keys)`,
		)
	}

	if (stale.length > 0) {
		console.error('')
		console.error(`Stale browser catalogue: ${stale.join(', ')}`)
		console.error('Run `npm run l10n:build` and commit the result.')
		process.exit(1)
	}
}

main()
