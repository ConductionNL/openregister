/* eslint-disable jsdoc/require-param */
/**
 * Shared l10n helpers used by check-l10n.js, clean-l10n.js, and l10n-ai.js.
 *
 * Operates on l10n/*.js (frontend translation files). Backend .json files are
 * a separate concern and are not handled here.
 */

const fs = require('fs')
const path = require('path')
const vm = require('vm')

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
		throw new Error(`OC.L10N.register was not called with a translations object in ${file}`)
	}
	if (!app) {
		throw new Error(`OC.L10N.register was not called with an app name in ${file}`)
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
	if (quote !== '\'' && quote !== '"') return null
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
 *   calls        [{ fn, keys, index }] -- `keys` holds 1 entry for t(), and 2
 *                for n() (singular AND plural; both are real catalogue keys and
 *                both must count as "used").
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
			if (!lit) { ok = false; break }
			keys.push(lit.value)
			let j = lit.end + 1
			while (j < text.length && (text[j] === ' ' || text[j] === '\t' || text[j] === '\n')) j++
			const next = text[j]
			const isLast = a === wanted - 1
			if (next !== ',' && !(isLast && next === ')')) { ok = false; break }
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
 * Scan src/ for translation calls and return the set of literal keys
 * referenced. Shared by check-l10n.js, clean-l10n.js and l10n-ai.js so
 * "is this key still in use?" answers stay consistent across all three.
 */
function collectUsedKeys(srcDir, app) {
	const used = new Set()
	for (const file of walk(srcDir, ['.vue', '.js', '.ts'])) {
		const { calls } = extractTranslationCalls(fs.readFileSync(file, 'utf8'), app)
		for (const c of calls) for (const k of c.keys) used.add(k)
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
		let lo = 0; let hi = lineStarts.length - 1
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
	for (const file of walk(srcDir, ['.vue', '.js', '.ts'])) {
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
	return fs.readdirSync(l10nDir)
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
	'read', 'create', 'update', 'delete', 'manage',
	// ApprovalStepList step.status
	'pending', 'approved', 'rejected', 'skipped', 'cancelled',
	// DashboardIndex date presets
	'All time', 'Last 7 days', 'Last 30 days', 'Last 3 months', 'Last 12 months',
]

/**
 * Every key reached dynamically: DYNAMIC_KEYS plus each menu label in
 * src/manifest.json, which MainMenu.translate(key) passes straight to t().
 *
 * @param {string} repoRoot Absolute path to the app root.
 * @return {Set<string>} Keys that must count as used.
 */
function collectDynamicKeys(repoRoot) {
	const out = new Set(DYNAMIC_KEYS)
	const manifestPath = path.join(repoRoot, 'src/manifest.json')
	if (!fs.existsSync(manifestPath)) return out
	let manifest
	try { manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')) } catch { return out }
	;(function collect(node) {
		if (Array.isArray(node)) return node.forEach(collect)
		if (node && typeof node === 'object') {
			for (const [k, v] of Object.entries(node)) {
				if ((k === 'label' || k === 'name' || k === 'title') && typeof v === 'string') out.add(v)
				collect(v)
			}
		}
	})(manifest)
	return out
}

module.exports = {
	loadJsTranslations,
	serializeJs,
	walk,
	extractTranslationCalls,
	makeLineResolver,
	collectUsedKeys,
	findKeyReferences,
	listJsLocaleFiles,
	localeNameOf,
	DYNAMIC_KEYS,
	collectDynamicKeys,
}
