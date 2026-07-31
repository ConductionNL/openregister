#!/usr/bin/env node
/* eslint-disable jsdoc/require-param */
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * AI-focused l10n CRUD tool. Designed to be invoked one subcommand at a time
 * by Claude (or other automation) so individual operations stay cheap in
 * tokens and tool-calls.
 *
 * Operates ONLY on l10n/*.js (frontend). Backend l10n/*.json files are never
 * read or written by this tool.
 *
 * Usage:
 *   node scripts/l10n-ai.js <subcommand> [args...]
 *
 * Subcommands:
 *   has <key> [--ignore-case]
 *   get <key>
 *   find <substring>
 *   add <key> --value <lang>=<text> [--value <lang>=<text> ...] [--locales=a,b] [--force]
 *   set <key> --locale=<lang> --value=<text>
 *   rm <key> [--force]
 *   rename <old> <new> [--force]
 *   list-locales
 *   --help | -h
 *
 * Output is line-oriented and machine-readable. Exit 0 on success, non-zero
 * on failure (with a one-line stderr reason).
 */

const fs = require('fs')
const path = require('path')

const {
	loadJsTranslations,
	serializeJs,
	findKeyReferences,
	listJsLocaleFiles,
	localeNameOf,
} = require('./lib/l10n.js')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')
const L10N_DIR = path.join(ROOT, 'l10n')

// ---------- arg parsing ----------

/**
 * Minimal flag parser. Recognizes:
 *   --flag                  → flags.flag = true
 *   --key=value             → opts.key = "value"
 *   --key value             → opts.key = "value" (next token consumed)
 *   --value <lang>=<text>   → repeatable; collected into opts.value (array of "lang=text")
 * Anything not starting with -- is a positional argument.
 *
 * The `repeatable` set names options that should accumulate into an array
 * instead of being overwritten on each occurrence.
 */
function parseArgs(argv, { repeatable = new Set() } = {}) {
	const positionals = []
	const opts = {}
	const flags = {}
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i]
		if (a === '--') {
			positionals.push(...argv.slice(i + 1))
			break
		}
		if (a.startsWith('--')) {
			const eq = a.indexOf('=')
			let key, value
			if (eq !== -1) {
				key = a.slice(2, eq)
				value = a.slice(eq + 1)
			} else {
				key = a.slice(2)
				const next = argv[i + 1]
				if (next !== undefined && !next.startsWith('--')) {
					value = next
					i++
				} else {
					flags[key] = true
					continue
				}
			}
			if (repeatable.has(key)) {
				if (!opts[key]) opts[key] = []
				opts[key].push(value)
			} else {
				opts[key] = value
			}
		} else {
			positionals.push(a)
		}
	}
	return { positionals, opts, flags }
}

/** Parse a list of "lang=text" strings into a { lang: text } map. */
function parseValuePairs(pairs) {
	const out = {}
	for (const p of pairs) {
		const eq = p.indexOf('=')
		if (eq === -1) {
			throw new Error(`--value expects 'lang=text', got '${p}'`)
		}
		const lang = p.slice(0, eq)
		const text = p.slice(eq + 1)
		if (!lang) throw new Error(`--value missing locale: '${p}'`)
		out[lang] = text
	}
	return out
}

// ---------- file helpers ----------

function loadAll() {
	const files = listJsLocaleFiles(L10N_DIR)
	if (!files.length) {
		throw new Error(`No l10n/*.js files found in ${L10N_DIR}`)
	}
	return files.map((file) => ({
		file,
		locale: localeNameOf(file),
		...loadJsTranslations(file),
	}))
}

/**
 * Write each entry back to its locale file.
 *
 * Deliberately does NOT run eslint on the result. serializeJs already emits the
 * exact on-disk Nextcloud/Transifex layout, and `eslint --fix` actively destroys
 * it: it rewrites the file to tabs and SINGLE quotes with no space before the
 * colon, which reformats all ~4400 lines and diverges from what Transifex
 * regenerates. The eslint rules are for source code, not for generated
 * translation data.
 */
function writeAll(entries) {
	for (const e of entries) {
		fs.writeFileSync(e.file, serializeJs({
			app: e.app,
			translations: e.translations,
			pluralForm: e.pluralForm,
		}))
	}
}

function fail(msg, code = 1) {
	console.error(msg)
	process.exit(code)
}

function rel(p) {
	return path.relative(ROOT, p)
}

// ---------- subcommands ----------

function cmdHas(args) {
	const { positionals, flags } = parseArgs(args)
	const [key] = positionals
	if (!key) fail('usage: has <key> [--ignore-case]')

	const ignoreCase = !!flags['ignore-case']
	const target = ignoreCase ? key.toLowerCase() : key
	const entries = loadAll()
	const matches = []
	for (const e of entries) {
		for (const k of Object.keys(e.translations)) {
			const cmp = ignoreCase ? k.toLowerCase() : k
			if (cmp === target) {
				matches.push({ locale: e.locale, key: k })
				break
			}
		}
	}
	if (!matches.length) {
		console.log('none')
		process.exit(1)
	}
	for (const m of matches) {
		// when ignore-case finds a different-cased key, surface the actual key
		if (m.key !== key) console.log(`${m.locale}.js\t${m.key}`)
		else console.log(`${m.locale}.js`)
	}
}

function cmdGet(args) {
	const { positionals } = parseArgs(args)
	const [key] = positionals
	if (!key) fail('usage: get <key>')

	const entries = loadAll()
	let any = false
	for (const e of entries) {
		if (Object.prototype.hasOwnProperty.call(e.translations, key)) {
			any = true
			const v = e.translations[key]
			const out = Array.isArray(v) ? JSON.stringify(v) : v
			console.log(`${e.locale}.js\t${out}`)
		}
	}
	if (!any) {
		console.log('none')
		process.exit(1)
	}
}

function cmdFind(args) {
	const { positionals } = parseArgs(args)
	const [substring] = positionals
	if (!substring) fail('usage: find <substring>')

	const needle = substring.toLowerCase()
	const entries = loadAll()
	const seen = new Set()
	for (const e of entries) {
		for (const k of Object.keys(e.translations)) {
			if (k.toLowerCase().includes(needle)) seen.add(k)
		}
	}
	if (!seen.size) {
		console.log('none')
		process.exit(1)
	}
	for (const k of [...seen].sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()))) {
		console.log(k)
	}
}

function cmdAdd(args) {
	const { positionals, opts, flags } = parseArgs(args, { repeatable: new Set(['value']) })
	const [key] = positionals
	if (!key) fail('usage: add <key> --value <lang>=<text> [--value <lang>=<text> ...] [--locales=a,b] [--force]')

	const valuePairs = opts.value || []
	if (!valuePairs.length) fail('add: at least one --value <lang>=<text> is required')

	let valueMap
	try {
		valueMap = parseValuePairs(valuePairs)
	} catch (err) {
		fail(`add: ${err.message}`)
	}

	const entries = loadAll()
	const allLocales = new Set(entries.map((e) => e.locale))
	const targetLocales = opts.locales
		? new Set(opts.locales.split(',').map((s) => s.trim()).filter(Boolean))
		: allLocales

	for (const lang of targetLocales) {
		if (!allLocales.has(lang)) {
			fail(`add: locale '${lang}' has no l10n/${lang}.js (known: ${[...allLocales].join(', ')})`)
		}
	}

	// Surface unknown locales (typos like --value xx=...) before missing-locale errors.
	const unknown = Object.keys(valueMap).filter((l) => !allLocales.has(l))
	if (unknown.length) {
		fail(`add: --value uses unknown locale(s): ${unknown.join(', ')} (known: ${[...allLocales].join(', ')})`)
	}
	const extras = Object.keys(valueMap).filter((l) => !targetLocales.has(l))
	if (extras.length) {
		fail(`add: --value provided for locale(s) not in target set: ${extras.join(', ')}`)
	}
	// Every targeted locale must have a value supplied. No silent English-as-Dutch fallback.
	const missing = [...targetLocales].filter((l) => !(l in valueMap))
	if (missing.length) {
		fail(`add: missing --value for locale(s): ${missing.join(', ')}. Provide a translation for each, or narrow with --locales.`)
	}

	// Collision check (unless --force).
	const existing = []
	for (const e of entries) {
		if (!targetLocales.has(e.locale)) continue
		if (Object.prototype.hasOwnProperty.call(e.translations, key)) {
			existing.push(e.locale)
		}
	}
	if (existing.length && !flags.force) {
		fail(`add: key already exists in ${existing.map((l) => l + '.js').join(', ')}. Use 'set' to update or pass --force to overwrite.`)
	}

	// Build mutated entries in memory; only write if every step succeeds.
	const toWrite = []
	for (const e of entries) {
		if (!targetLocales.has(e.locale)) continue
		const next = { ...e.translations, [key]: valueMap[e.locale] }
		toWrite.push({ ...e, translations: next })
	}
	writeAll(toWrite)
	for (const e of toWrite) console.log(`${e.locale}.js\t${valueMap[e.locale]}`)
}

function cmdSet(args) {
	const { positionals, opts } = parseArgs(args)
	const [key] = positionals
	if (!key) fail('usage: set <key> --locale=<lang> --value=<text>')
	if (!opts.locale) fail('set: --locale is required')
	if (opts.value === undefined) fail('set: --value is required')

	const entries = loadAll()
	const target = entries.find((e) => e.locale === opts.locale)
	if (!target) {
		fail(`set: locale '${opts.locale}' has no l10n/${opts.locale}.js (known: ${entries.map((e) => e.locale).join(', ')})`)
	}
	if (!Object.prototype.hasOwnProperty.call(target.translations, key)) {
		fail(`set: key '${key}' not present in ${opts.locale}.js. Use 'add' first.`)
	}
	if (Array.isArray(target.translations[key])) {
		fail(`set: key '${key}' is pluralized (array value); edit ${opts.locale}.js by hand.`)
	}

	const next = { ...target.translations, [key]: opts.value }
	writeAll([{ ...target, translations: next }])
	console.log(`${target.locale}.js\t${opts.value}`)
}

function cmdRm(args) {
	const { positionals, flags } = parseArgs(args)
	const [key] = positionals
	if (!key) fail('usage: rm <key> [--force]')

	const entries = loadAll()
	const present = entries.filter((e) => Object.prototype.hasOwnProperty.call(e.translations, key))
	if (!present.length) {
		fail(`rm: key '${key}' not found in any locale .js file`)
	}

	if (!flags.force) {
		const app = entries[0].app
		const refs = findKeyReferences(SRC_DIR, app, key)
		if (refs.length) {
			const sample = refs.slice(0, 3).map((r) => `${rel(r.file)}:${r.line}`).join(', ')
			const more = refs.length > 3 ? ` (+${refs.length - 3} more)` : ''
			fail(`rm: key '${key}' is still referenced from ${sample}${more}. Pass --force to remove anyway.`)
		}
	}

	const toWrite = []
	for (const e of present) {
		const next = { ...e.translations }
		delete next[key]
		toWrite.push({ ...e, translations: next })
	}
	writeAll(toWrite)
	for (const e of toWrite) console.log(`${e.locale}.js\tremoved`)
}

function cmdRename(args) {
	const { positionals, flags } = parseArgs(args)
	const [oldKey, newKey] = positionals
	if (!oldKey || !newKey) fail('usage: rename <old> <new> [--force]')
	if (oldKey === newKey) fail('rename: old and new keys are identical')

	const entries = loadAll()
	const present = entries.filter((e) => Object.prototype.hasOwnProperty.call(e.translations, oldKey))
	if (!present.length) {
		fail(`rename: key '${oldKey}' not found in any locale .js file`)
	}
	const collisions = entries.filter((e) => Object.prototype.hasOwnProperty.call(e.translations, newKey))
	if (collisions.length && !flags.force) {
		fail(`rename: target key '${newKey}' already exists in ${collisions.map((e) => e.locale + '.js').join(', ')}. Pass --force to overwrite.`)
	}

	const toWrite = []
	for (const e of entries) {
		if (!Object.prototype.hasOwnProperty.call(e.translations, oldKey)) continue
		const next = { ...e.translations }
		next[newKey] = next[oldKey]
		delete next[oldKey]
		toWrite.push({ ...e, translations: next })
	}
	writeAll(toWrite)
	for (const e of toWrite) console.log(`${e.locale}.js\trenamed`)
}

function cmdListLocales() {
	const files = listJsLocaleFiles(L10N_DIR)
	if (!files.length) fail('list-locales: no l10n/*.js files found')
	for (const f of files) console.log(localeNameOf(f))
}

function cmdHelp() {
	const text = [
		'Usage: node scripts/l10n-ai.js <subcommand> [args...]',
		'',
		'Subcommands:',
		'  has <key> [--ignore-case]                check whether a key exists',
		'  get <key>                                print value per locale',
		'  find <substring>                         list keys containing substring (case-insensitive)',
		'  add <key> --value <lang>=<text> ...      add a key (one --value per locale)',
		'      [--locales=a,b] [--force]',
		'  set <key> --locale=<lang> --value=<text> update a single locale',
		'  rm <key> [--force]                       remove a key everywhere',
		'  rename <old> <new> [--force]             rename a key everywhere',
		'  list-locales                             list shipped locales',
		'  --help, -h                               show this help',
		'',
		'Operates on l10n/*.js only. Backend l10n/*.json is never touched.',
	]
	for (const line of text) console.log(line)
}

// ---------- main ----------

function main() {
	const [, , sub, ...rest] = process.argv
	if (!sub || sub === '--help' || sub === '-h') {
		cmdHelp()
		return
	}
	try {
		switch (sub) {
		case 'has': return cmdHas(rest)
		case 'get': return cmdGet(rest)
		case 'find': return cmdFind(rest)
		case 'add': return cmdAdd(rest)
		case 'set': return cmdSet(rest)
		case 'rm': return cmdRm(rest)
		case 'rename': return cmdRename(rest)
		case 'list-locales': return cmdListLocales()
		default:
			fail(`unknown subcommand: ${sub}. Run --help for usage.`)
		}
	} catch (err) {
		fail(err.message || String(err))
	}
}

main()
