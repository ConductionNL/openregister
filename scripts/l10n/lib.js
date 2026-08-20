/* eslint-disable jsdoc/require-param */
/**
 * The l10n catalogue library — the single shared module for frontend translation
 * tooling. Used by scripts/check-l10n.js, scripts/clean-l10n.js,
 * scripts/l10n-ai.js, scripts/find-unwrapped.js, tests/l10n/check-l10n.js,
 * tests/l10n/check-l10n-parity.js and everything else in scripts/l10n/.
 *
 * Operates on l10n/*.js (frontend translation files). Backend .json files are
 * a separate concern and are not handled here.
 *
 * This file was `scripts/lib/l10n.js` until 2026-08-14. It moved into the l10n
 * folder because a second helper module had appeared at `scripts/l10n/lib.js`, and
 * two near-mirror names one directory apart is exactly the sort of pair you grab
 * the wrong one of. The two are now merged: there is ONE l10n folder, and one
 * library in it. `scripts/lib/` no longer exists.
 *
 * This is the ORIGIN copy. openconnector carries a VENDORED copy — the two apps
 * ship separate npm packages, so there is no import path between them. Keep the
 * two in sync when either changes; the only intended divergence is DYNAMIC_KEYS
 * below, which is app-specific data. **openconnector still has it at the old
 * `scripts/lib/l10n.js` path**; move it when you next sync.
 *
 * Two layers live here, deliberately in one file rather than two near-identically
 * named ones:
 *
 *   1. THE CATALOGUE — parsing, serializing and key extraction. Locale-agnostic,
 *      and what the audit and CI gates are built on.
 *   2. A LOCALE PASS — the helpers a single-language translation pass needs:
 *      identical-value detection, placeholder sets, per-locale config and
 *      detectors. These resolve paths under scripts/l10n/ and degrade to empty
 *      results when a locale has not been started, so callers can treat
 *      "not started" and "in progress" uniformly.
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')
const { execFileSync } = require('child_process')

/**
 * Load a single l10n/*.js file and return its app name, translations object,
 * and plural-form string. Throws if the file does not call OC.L10N.register.
 */
function loadJsTranslations(file) {
	const code = fs.readFileSync(file, 'utf8')
	let captured = null
	let plural = null
	let app = null
	const sandbox = {
		OC: {
			L10N: {
				register: (registeredApp, translations, pluralForm) => {
					app = registeredApp
					captured = translations
					plural = pluralForm
				},
			},
		},
	}
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox, { filename: file })
	if (!captured || typeof captured !== 'object') {
		throw new Error(
			`OC.L10N.register was not called with a translations object in ${file}`,
		)
	}
	if (!app) {
		throw new Error(
			`OC.L10N.register was not called with an app name in ${file}`,
		)
	}
	return {
		app,
		translations: captured,
		pluralForm: plural || 'nplurals=2; plural=(n != 1);',
	}
}

/**
 * Case-insensitive alphabetical order so "apple" sorts next to "Apple" instead
 * of after "Zebra" the way a raw code-unit sort puts it, tie-broken by code
 * unit so the result is stable. Deliberately NOT localeCompare: that varies
 * with the Node/ICU version, which would make the sort order — and therefore
 * every locale file's diff — depend on who ran the tool.
 */
function compareKeys(a, b) {
	const x = a.toLowerCase()
	const y = b.toLowerCase()
	if (x < y) return -1
	if (x > y) return 1
	return a < b ? -1 : a > b ? 1 : 0
}

/**
 * Serialize an l10n/*.js file byte-for-byte in the layout the shipped files
 * already use, so a one-key edit produces a one-line diff.
 *
 * That layout is the Nextcloud/Transifex one, and it differs from plain
 * JSON.stringify in four ways that all matter for diff noise:
 *   - four-space indent (NOT tabs) for the app id, the brace and every entry,
 *     with entries at the SAME depth as the opening brace;
 *   - a space before the colon:  "key" : "value"
 *   - no trailing comma after the final entry;
 *   - a `);` terminator rather than `)`.
 *
 * Getting any of these wrong rewrites every line of all 37 locale files on the
 * next write. The previous implementation emitted tabs, `"key": "value"`, a
 * trailing comma and `)`, and leaned on `eslint --fix` to renormalize — which
 * silently no-ops when node_modules is absent, and never covered l10n/ anyway.
 *
 * Pluralized values (arrays) are emitted as compact JSON arrays, as on disk.
 *
 * Keys are sorted with compareKeys (case-insensitive CODE-UNIT order), which is
 * the order 36 of the 37 shipped locale files are actually in; localeCompare
 * matches none of them, because the two disagree on where punctuation sorts.
 * The lone exception is l10n/en.js, still in the order the original extraction
 * tool emitted, so the first write that touches en.js will re-sort it once.
 */
function serializeJs({ app, translations, pluralForm }) {
	const keys = Object.keys(translations).sort(compareKeys)
	const lines = keys.map((k, i) => {
		const value = translations[k]
		const comma = i === keys.length - 1 ? '' : ','
		return `    ${JSON.stringify(k)} : ${JSON.stringify(value)}${comma}`
	})
	return `OC.L10N.register(\n    ${JSON.stringify(app)},\n    {\n${lines.join('\n')}\n},\n${JSON.stringify(pluralForm)}\n);\n`
}

/**
 * Recursively walk a directory, collecting files whose extension is in `exts`.
 * Skips node_modules and dotfile directories.
 */
function walk(dir, exts, out = []) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue
			walk(full, exts, out)
		} else if (exts.includes(path.extname(entry.name))) {
			out.push(full)
		}
	}
	return out
}

/**
 * Matches the opening of a translation call for `app`, capturing which function
 * it was so the caller knows how many key arguments to read.
 *
 * Covers t(), n() and the $t/$n template variants, plus member forms like
 * this.t(...). The negative lookbehind rejects identifiers that merely END in
 * t or n -- format(, fn(, min( -- which a bare \b would let through for `n`.
 *
 * n() is the reason this exists. The previous extractor matched only `t(`, so
 * every n() plural key was invisible to it. That made check-l10n report all
 * plural keys as UNUSED, and armed clean-l10n.js: because it deletes
 * en.js-minus-used from EVERY locale file, adding the plural source keys to
 * en.js (which is correct and expected) would make the next --apply erase them
 * from all 37 locales. They are only safe today by accident of being absent.
 */
function translationCallRe(app) {
	return new RegExp(
		`(?<![\\w$])\\$?([tn])\\s*\\(\\s*(['"])${escapeRegex(app)}\\2\\s*,\\s*`,
		'g',
	)
}

/**
 * Read a single/double-quoted JS string literal starting at `start` (which must
 * be the opening quote). Returns { value, end } where `end` is the index of the
 * closing quote, or null when the literal is unterminated or spans a newline
 * (i.e. is not a simple static literal we can trust).
 */
function readStringLiteral(text, start) {
	const quote = text[start]
	if (quote !== "'" && quote !== '"') return null
	let i = start + 1
	let value = ''
	while (i < text.length) {
		const c = text[i]
		if (c === '\\' && i + 1 < text.length) {
			const n = text[i + 1]
			// \uXXXX, \u{XXXXX} and \xXX must be DECODED, not stripped. The
			// runtime key is whatever JS produces, so an extractor that turns
			// '⚠' into the literal 'u26A0' reports a key that can never
			// match at runtime -- the translation would silently never apply.
			if (n === 'u' && text[i + 2] === '{') {
				const close = text.indexOf('}', i + 3)
				const hex = close === -1 ? null : text.slice(i + 3, close)
				if (hex && /^[0-9a-fA-F]{1,6}$/.test(hex)) {
					value += String.fromCodePoint(parseInt(hex, 16))
					i = close + 1
					continue
				}
			}
			if (n === 'u' && /^[0-9a-fA-F]{4}$/.test(text.slice(i + 2, i + 6))) {
				value += String.fromCharCode(parseInt(text.slice(i + 2, i + 6), 16))
				i += 6
				continue
			}
			if (n === 'x' && /^[0-9a-fA-F]{2}$/.test(text.slice(i + 2, i + 4))) {
				value += String.fromCharCode(parseInt(text.slice(i + 2, i + 4), 16))
				i += 4
				continue
			}
			if (n === 'n') value += '\n'
			else if (n === 't') value += '\t'
			else if (n === 'r') value += '\r'
			else if (n === 'b') value += '\b'
			else if (n === 'f') value += '\f'
			else if (n === 'v') value += '\v'
			else if (n === '0' && !/[0-9]/.test(text[i + 2] || '')) value += '\0'
			else value += n
			i += 2
			continue
		}
		if (c === quote) return { value, end: i }
		if (c === '\n') return null
		value += c
		i++
	}
	return null
}

/**
 * Extract every static translation call for `app` from one file's text.
 *
 * Returns { calls, unanalyzable }:
 *   calls        [{ fn, keys, index }] -- `keys` holds the call's SOURCE STRINGS:
 *                1 for t(), and 2 for n() (singular then plural).
 *
 * Neither n() argument is a catalogue key on its own. The key is the identifier
 * the two combine into -- see pluralIdentifier() -- so a caller deciding "is this
 * key present?" must build it rather than reading keys[0]. Callers deciding "may I
 * delete this?" should treat all three as used; collectUsedKeys() does both.
 *
 *   unanalyzable [{ index }] -- calls whose key argument is not a static string
 *                literal (template literal, concatenation, variable).
 *
 * A t() call is accepted only when the literal is followed by `,` or `)`, which
 * rejects concatenations like t('app', 'a' + b). For n() the singular must be
 * followed by `,`; the plural by `,` or `)`.
 */
function extractTranslationCalls(text, app) {
	const re = translationCallRe(app)
	const calls = []
	const unanalyzable = []
	let m
	re.lastIndex = 0
	while ((m = re.exec(text)) !== null) {
		const fn = m[1]
		const wanted = fn === 'n' ? 2 : 1
		const keys = []
		let pos = re.lastIndex
		let ok = true
		for (let a = 0; a < wanted; a++) {
			const lit = readStringLiteral(text, pos)
			if (!lit) {
				ok = false
				break
			}
			keys.push(lit.value)
			let j = lit.end + 1
			while (
				j < text.length
				&& (text[j] === ' ' || text[j] === '\t' || text[j] === '\n')
			)
				j++
			const next = text[j]
			const isLast = a === wanted - 1
			if (next !== ',' && !(isLast && next === ')')) {
				ok = false
				break
			}
			if (next !== ',') break
			pos = j + 1
			while (pos < text.length && /\s/.test(text[pos])) pos++
		}
		if (ok && keys.length === wanted) calls.push({ fn, keys, index: m.index })
		else unanalyzable.push({ index: m.index })
	}
	return { calls, unanalyzable }
}

/**
 * The catalogue key an n() call looks up.
 *
 * The nextcloud/l10n package builds this identifier from the two source strings
 * and looks up nothing else (translatePlural, dist/chunks/translation-*.mjs).
 * Storing the forms under the bare singular instead renders correctly for
 * count === 1 — translate() takes element 0 of an array — and falls back to
 * English for every other count, because the fallback then looks up the
 * untranslated plural source string, which no locale bundle contains.
 */
function pluralIdentifier(singular, plural) {
	return `_${singular}_::_${plural}_`
}

/**
 * Source extensions every "is this key used?" scan walks.
 *
 * ONE list, exported, because the two directions this feeds are not symmetric in
 * consequence. The CI gate asserts en.js COVERS what src/ uses; clean-l10n.js
 * --apply DELETES from all 37 locales what src/ does not. A file type the gate
 * scans but the cleaner does not is a silent data-loss path: the gate stays green
 * while --apply drops that file's keys from every locale. The gate's list was the
 * broader of the two, so this takes it wholesale rather than narrowing to the
 * intersection.
 *
 * `.mjs`/`.jsx`/`.tsx` match nothing in src/ today. They are here for the day one
 * appears, which is exactly the day the divergence would have cost something.
 */
const SRC_EXTS = ['.vue', '.js', '.ts', '.mjs', '.jsx', '.tsx']

/**
 * Scan src/ for translation calls and return the set of keys that count as
 * USED. Shared by clean-l10n.js and l10n-ai.js — the paths that DELETE or refuse
 * to overwrite — so they cannot disagree about what is live.
 *
 * An n() call contributes THREE entries: the plural identifier, which is the key
 * the runtime actually looks up, plus both source strings. The identifier is the
 * one that matters; the two source strings are over-counted on purpose, because
 * this set gates deletion from 37 files at once. Over-counting leaves a dead key
 * in place, which someone notices later; under-counting deletes a live
 * translation, which nobody notices at all.
 *
 * That makes this set deliberately WIDER than the audit set in
 * scripts/check-l10n.js, which reports the bare singular of a converted plural as
 * unused precisely so a human is told about it. Audit informs; cleaner destroys —
 * so they err in opposite directions.
 */
function collectUsedKeys(srcDir, app) {
	const used = new Set()
	for (const file of walk(srcDir, SRC_EXTS)) {
		const { calls } = extractTranslationCalls(fs.readFileSync(file, 'utf8'), app)
		for (const c of calls) {
			if (c.fn === 'n' && c.keys.length === 2)
				used.add(pluralIdentifier(c.keys[0], c.keys[1]))
			for (const k of c.keys) used.add(k)
		}
	}
	return used
}

/** Build a char-offset -> 1-based line resolver for one file's text. */
function makeLineResolver(text) {
	const lineStarts = [0]
	for (let i = 0; i < text.length; i++) {
		if (text.charCodeAt(i) === 10) lineStarts.push(i + 1)
	}
	return (pos) => {
		let lo = 0
		let hi = lineStarts.length - 1
		while (lo < hi) {
			const mid = (lo + hi + 1) >> 1
			if (lineStarts[mid] <= pos) lo = mid
			else hi = mid - 1
		}
		return lo + 1
	}
}

/**
 * Find the file:line of every static translation reference to `key`. Used by
 * l10n-ai.js rm to explain *why* a removal is refused. Matches n() plural
 * arguments too, so removing a plural key is correctly blocked.
 */
function findKeyReferences(srcDir, app, key) {
	const hits = []
	for (const file of walk(srcDir, SRC_EXTS)) {
		const text = fs.readFileSync(file, 'utf8')
		const { calls } = extractTranslationCalls(text, app)
		if (!calls.length) continue
		const posToLine = makeLineResolver(text)
		for (const c of calls) {
			if (c.keys.includes(key)) hits.push({ file, line: posToLine(c.index) })
		}
	}
	return hits
}

function escapeRegex(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * List l10n/*.js files in an app's l10n/ directory. Sorted for deterministic
 * output. Returns absolute paths.
 */
function listJsLocaleFiles(l10nDir) {
	if (!fs.existsSync(l10nDir)) return []
	return fs
		.readdirSync(l10nDir)
		.filter((f) => f.endsWith('.js'))
		.sort()
		.map((f) => path.join(l10nDir, f))
}

/** Strip the `.js` extension from a locale file basename ("en.js" → "en"). */
function localeNameOf(file) {
	return path.basename(file, '.js')
}

/**
 * Keys passed to t() through a VARIABLE, so no static scan can find them.
 * They are real, live keys: without them the Permission Matrix headers, the
 * approval status badges and the dashboard date presets render English.
 *
 *   t('openregister', action)        PermissionMatrix.vue:41
 *                                    actions: ['read','create',...] (frontend)
 *   t('openregister', step.status)   ApprovalStepList.vue:17
 *                                    raw DB enum from the approval-steps API;
 *                                    the backend never localises it
 *   t('openregister', preset.label)  DashboardIndex.vue:91,120,360
 *                                    DATE_PRESETS (frontend)
 *
 * Anything listed here must be treated as USED: never reported unused, never
 * removed by clean-l10n. Add to this list when a new variable-keyed t() call
 * is introduced, or the key silently stops being translated.
 */
const DYNAMIC_KEYS = [
	// PermissionMatrix actions
	'read',
	'create',
	'update',
	'delete',
	'manage',
	// ApprovalStepList step.status
	'pending',
	'approved',
	'rejected',
	'skipped',
	'cancelled',
	// DashboardIndex date presets
	'All time',
	'Last 7 days',
	'Last 30 days',
	'Last 3 months',
	'Last 12 months',
]

/**
 * Every key reached dynamically: DYNAMIC_KEYS plus the src/manifest.json fields
 * that MainMenu.translate(key) passes straight to t().
 *
 * Only the fields CnAppNav actually resolves through its `translate` prop count:
 * `menu[].label` (recursively through `children`) and the two nav label
 * overrides. Anything else in the manifest is data, not UI copy — notably
 * `observability.metrics[].name` (Prometheus metric identifiers) and
 * `pages[].title`, which CnPageRenderer forwards to the page component as a raw
 * prop without translating it. Harvesting those made metric names look like
 * catalogue keys and would have put them in front of translators.
 *
 * @param {string} repoRoot Absolute path to the app root.
 * @return {Set<string>} Keys that must count as used.
 */
function collectDynamicKeys(repoRoot) {
	const out = new Set(DYNAMIC_KEYS)
	const manifestPath = path.join(repoRoot, 'src/manifest.json')
	if (!fs.existsSync(manifestPath)) return out
	let manifest
	try {
		manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
	} catch {
		return out
	}
	;(function collectMenu(items) {
		if (!Array.isArray(items)) return
		for (const item of items) {
			if (!item || typeof item !== 'object') continue
			if (typeof item.label === 'string') out.add(item.label)
			collectMenu(item.children)
		}
	})(manifest.menu)
	for (const field of ['roadmapLabel', 'documentationLabel']) {
		const v = manifest.nav?.[field]
		if (typeof v === 'string') out.add(v)
	}
	return out
}

/* ------------------------------------------------------------------------- *
 * Layer 2: a single-locale translation pass.
 *
 * Everything below serves the per-locale workflow in scripts/l10n/ and the
 * parity gate. Kept in this file rather than a second `lib.js` next door — see
 * the header.
 * ------------------------------------------------------------------------- */

/** Absolute path to the app root, derived from this file's location. */
const APP_ROOT = path.resolve(__dirname, '..', '..')
/** Per-locale config: measured register, justified cognates, audited corrections. */
const LOCALES_DIR = path.join(__dirname, 'locales')
/** Per-locale register detectors. */
const DETECTORS_DIR = path.join(__dirname, 'detectors')

/**
 * Is this value indistinguishable from its own key?
 *
 * This is the single most important predicate in the whole workflow, because an
 * ABSENT key falls back to the English source and stays visibly untranslated to
 * every tool, whereas a value equal to its key renders the same characters while
 * being indistinguishable from finished work — so nobody ever revisits it.
 *
 * For a plural key the comparison is per form against the two source strings
 * encoded in the `_singular_::_plural_` identifier, so a locale that translated
 * only one of the forms is NOT counted as identical.
 *
 * @param {string} key Catalogue key.
 * @param {string|string[]} value Its value in some locale.
 * @return {boolean} True when the value carries no translation at all.
 */
function isIdentical(key, value) {
	if (typeof value === 'string') return value === key
	const m = /^_([\s\S]*)_::_([\s\S]*)_$/.exec(key)
	if (!m) return false
	return value.every((x, i) => x === (i === 0 ? m[1] : m[2]))
}

/**
 * Whether ANY single form of a value renders the English source — the weaker
 * condition a cognate record for a plural key has to answer for.
 *
 * `isIdentical` requires EVERY form to match, which no partially-cognate plural
 * ever does, while runtime-check.mjs flags a single English-rendering form and
 * consults the same cognate record to excuse it. So the two gates disagreed about
 * what one record means: Romanian's email array is
 * ["{count} email", "{count} emailuri", "{count} de emailuri"] — the singular IS
 * the English string, because Romanian borrows 'email' unchanged, while the other
 * two forms differ. runtime-check needed the record; selfcheck then called the
 * same record stale. A record is stale only when NOTHING is left to excuse.
 *
 * @param {string} key Catalogue key, possibly a plural identifier.
 * @param {string|string[]} value Its value.
 * @return {boolean} True if the whole value, or one plural form, is the source.
 */
function hasIdenticalForm(key, value) {
	if (isIdentical(key, value)) return true
	if (typeof value === 'string' || !Array.isArray(value)) return false
	const m = /^_([\s\S]*)_::_([\s\S]*)_$/.exec(key)
	if (!m) return false
	return value.some((x, i) => x === (i === 0 ? m[1] : m[2]))
}

/**
 * Every placeholder token a translated value must carry over from the English
 * source. Checked in BOTH directions: a dropped `{count}` renders a sentence with
 * a hole in it, and an invented one renders a literal brace to the user.
 *
 * @param {string} s A source string or translated value.
 * @return {Set<string>} The placeholder tokens it contains.
 */
function placeholders(s) {
	const out = new Set()
	for (const m of String(s).matchAll(/\{[a-zA-Z_][\w.]*\}|%[nsd]|%\d+\$[sd]/g)) out.add(m[0])
	return out
}

/**
 * nplurals from a locale file's OWN plural-forms header.
 *
 * Anchored on `^` or `;` so it cannot match the `nplurals` inside the word
 * itself when the expression is scanned, and never inferred from the language:
 * an array shorter than the form index the runtime asks for renders BLANK, and
 * that is the one l10n defect invisible to a human reading the file.
 *
 * @param {string} pluralForm The header string passed to OC.L10N.register.
 * @return {number} Declared form count, defaulting to 2.
 */
function npluralsOf(pluralForm) {
	const m = /(?:^|;)\s*nplurals\s*=\s*(\d+)/.exec(pluralForm || '')
	return m ? Number(m[1]) : 2
}

/**
 * The `{plural}` keys, which are a SOURCE defect rather than a translation
 * problem: the call sites interpolate a literal "s" or "", i.e. hardcoded English
 * morphology, so no language whose plural is not a suffixed -s can render them
 * correctly. Croatian and Lithuanian cannot render them correctly at ALL, having
 * three numeral cases each.
 *
 * Dropping `{plural}` is therefore the ONE permitted placeholder loss. Every
 * other drift stays refused. See docs/l10n-ui-translation.md for what each
 * language family does instead.
 */
const PLURAL_HACK_KEYS = new Set([
	'file{plural}', 'log{plural}', 'object{plural}', 'register{plural}', 'schema{plural}',
])

/** Locale codes that have a committed config, i.e. a started or finished pass. */
function configuredLocales() {
	if (!fs.existsSync(LOCALES_DIR)) return []
	return fs.readdirSync(LOCALES_DIR)
		.filter((f) => f.endsWith('.json'))
		.map((f) => f.replace(/\.json$/, ''))
		.sort()
}

/**
 * Per-locale configuration. Returns empty structures for a locale that has none
 * yet, so callers can treat "not started" and "in progress" uniformly.
 *
 * @param {string} loc Locale code.
 * @return {{register: string|null, pluralOrder: string|null, pluralBoundary: string|null,
 *   cognates: object, corrections: object}} Config.
 */
function loadLocaleConfig(loc) {
	const f = path.join(LOCALES_DIR, `${loc}.json`)
	if (!fs.existsSync(f)) {
		return { register: null, pluralOrder: null, pluralBoundary: null, cognates: {}, corrections: {} }
	}
	const raw = JSON.parse(fs.readFileSync(f, 'utf8'))
	return {
		register: raw.register || null,
		// "library" acknowledges that this locale's plural= header and the runtime
		// library disagree on which index each count selects, and that the arrays are
		// deliberately ordered by the library. Read by runtime-check.mjs; without it a
		// mismatch is a hard failure, so the ordering cannot be silently reverted.
		//
		// pluralOrder is for a PERMUTATION disagreement, where the two partition the
		// counts identically and only label the parts differently, so reordering the
		// arrays makes the locale fully correct (lv). pluralBoundary is for a
		// disagreement no reordering can fix, because the two draw the boundaries in
		// different PLACES: `is` routes 21/31/41… to the plural where Icelandic takes
		// the singular, and `mk` routes 11/111 to the singular where Macedonian takes
		// the plural. There the acknowledgement is that some counts are knowingly
		// wrong and pluralNote says which — a different claim from "the arrays are
		// reordered", which is why it is a separate field rather than a second
		// meaning for the same one.
		pluralOrder: raw.pluralOrder || null,
		pluralBoundary: raw.pluralBoundary || null,
		cognates: raw.cognates || {},
		corrections: raw.corrections || {},
		// Read by spell.js: words legitimately absent from a general hunspell
		// dictionary (product names, code tokens, domain coinages), so a report shows
		// only what still needs triaging.
		spellAllow: raw.spellAllow || [],
	}
}

/**
 * The register detector for a locale, or null if none has been built yet.
 *
 * @param {string} loc Locale code.
 * @return {object|null} Module exporting score/runControls/CONTROLS.
 */
function loadDetector(loc) {
	const f = path.join(DETECTORS_DIR, `${loc}.js`)
	return fs.existsSync(f) ? require(f) : null
}

/**
 * Nextcloud core's own catalogues for one locale — the evidence a register is
 * measured from.
 *
 * Resolved the same way harvest.js resolves it: relative to this app, with an
 * env override. The five detectors written before this helper each hardcoded
 * one developer's absolute path, so on any other checkout they scanned ZERO
 * files and printed `verdict: MIXED` — a real-looking answer computed from no
 * data, which is precisely the failure mode this tooling exists to prevent.
 * Hence the throw below: no sources is an error, never a verdict.
 *
 * Region variants are found by MATCHING the directory, not by guessing the
 * region code. Guessing was `${loc}_${loc.toUpperCase()}`, which yields `et_ET`
 * — and Estonian ships as `et_EE`, so every Estonian catalogue in core was
 * invisible to a scan whose own comment said it handled Estonian. `@`-suffixed
 * variants (`sr@latin`) are excluded: same language, different script.
 *
 * @param {string} loc Locale code.
 * @return {string[]} Absolute paths to every core/lib/app catalogue found.
 */
/**
 * Matches `<loc>.json` and any region variant of it, e.g. `et_EE.json`.
 *
 * @param {string} loc Locale code.
 * @return {RegExp} Test against a bare filename.
 */
function localeFileRe(loc) {
	return new RegExp(`^${loc}(_[A-Za-z]{2,3})?\\.json$`)
}

function coreCatalogues(loc) {
	const workspace = process.env.L10N_WORKSPACE || path.resolve(APP_ROOT, '..', '..')
	const server = process.env.L10N_SERVER_DIR || path.join(workspace, 'server')
	const re = localeFileRe(loc)
	const files = []
	const collect = (dir) => {
		if (!fs.existsSync(dir)) return
		for (const f of fs.readdirSync(dir).sort()) {
			if (re.test(f)) files.push(path.join(dir, f))
		}
	}
	for (const p of ['core/l10n', 'lib/l10n']) collect(path.join(server, p))
	const appsDir = path.join(server, 'apps')
	if (fs.existsSync(appsDir)) {
		for (const a of fs.readdirSync(appsDir).sort()) collect(path.join(appsDir, a, 'l10n'))
	}
	if (!files.length) {
		throw new Error(
			`no ${loc} catalogues found under ${server}. `
			+ 'Set L10N_SERVER_DIR if your checkout differs — scanning nothing would '
			+ 'otherwise report a register verdict computed from zero values.',
		)
	}
	return files
}

/**
 * Run a detector's `score` over every core value for its locale and report the
 * marker totals, so a register verdict rests on counted evidence.
 *
 * @param {string} loc Locale code.
 * @param {Function} score The detector's score(s) -> {f, i}.
 * @return {object} { files, values, formal, informal, verdict, hits }
 */
function scanCoreRegister(loc, score) {
	const files = coreCatalogues(loc)
	let formal = 0
	let informal = 0
	let values = 0
	const hits = []
	for (const f of files) {
		let j
		try { j = JSON.parse(fs.readFileSync(f, 'utf8')) } catch { continue }
		for (const v of Object.values(j.translations || {})) {
			for (const x of Array.isArray(v) ? v : [v]) {
				if (typeof x !== 'string') continue
				values++
				const s = score(x)
				formal += s.f
				informal += s.i
				if (s.i > 0) hits.push([x.slice(0, 100), f])
			}
		}
	}
	const verdict = formal > informal * 3
		? 'FORMAL'
		: informal > formal * 3 ? 'INFORMAL' : 'MIXED — inspect'
	return { files, values, formal, informal, verdict, hits }
}

/**
 * Print what `scanCoreRegister` measured. Shared so each detector's `node
 * detectors/<loc>.js` output stays comparable across locales.
 *
 * @param {string} loc Locale code.
 * @param {object} r Result from scanCoreRegister.
 * @param {object} labels { formal, informal } pronoun names for this language.
 * @param {number} show How many suspected-informal values to list.
 */
function reportCoreRegister(loc, r, labels, show = 20) {
	console.log(`\nscanned ${r.files.length} ${loc} catalogue(s), ${r.values} values`)
	console.log(`formal (${labels.formal}) markers:   ${r.formal}`)
	console.log(`informal (${labels.informal}) markers: ${r.informal}`)
	console.log(`verdict: ${r.verdict}`)
	for (const [v, f] of r.hits.slice(0, show)) {
		console.log(`  informal? ${path.basename(path.dirname(path.dirname(f)))}/${path.basename(f)}: ${v}`)
	}
}

/**
 * The locale bundle as of HEAD, for "did this pass lose or silently alter an
 * existing translation?". Read through git rather than a copied file, so it
 * cannot drift out of date the way a stashed `<loc>-HEAD.js` does.
 *
 * @param {string} loc Locale code.
 * @return {object|null} Translations at HEAD, or null if the file is new.
 */
function bundleAtHead(loc) {
	let text
	try {
		text = execFileSync('git', ['show', `HEAD:l10n/${loc}.js`],
			{ cwd: APP_ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
	} catch {
		return null
	}
	let captured = null
	const sandbox = { OC: { L10N: { register: (app, tr) => { captured = tr } } } }
	vm.createContext(sandbox)
	vm.runInContext(text, sandbox, { filename: `HEAD:l10n/${loc}.js` })
	return captured
}

module.exports = {
	loadJsTranslations,
	serializeJs,
	walk,
	extractTranslationCalls,
	makeLineResolver,
	collectUsedKeys,
	pluralIdentifier,
	findKeyReferences,
	SRC_EXTS,
	listJsLocaleFiles,
	localeNameOf,
	DYNAMIC_KEYS,
	collectDynamicKeys,
	// layer 2: a single-locale pass
	APP_ROOT,
	LOCALES_DIR,
	DETECTORS_DIR,
	isIdentical,
	hasIdenticalForm,
	placeholders,
	npluralsOf,
	PLURAL_HACK_KEYS,
	configuredLocales,
	loadLocaleConfig,
	loadDetector,
	localeFileRe,
	coreCatalogues,
	scanCoreRegister,
	reportCoreRegister,
	bundleAtHead,
}
