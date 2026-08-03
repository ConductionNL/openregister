#!/usr/bin/env node
/* eslint-disable jsdoc/require-param */
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * l10n/i18n consistency checker.
 *
 * Scans src/ (*.vue, *.js, *.ts) and compares against l10n/en.js.
 *
 * Reports:
 *   1. MISSING   — strings used via t('<app>', '...') but absent from en.js
 *   2. UNUSED    — keys defined in en.js with no matching t() call
 *   3. UNWRAPPED — string literals in .vue files that match an en.js key but
 *                  are not wrapped in t() (likely missing translation)
 *
 * Exits non-zero if any issues are found.
 */

const fs = require('fs')
const path = require('path')

const {
	loadJsTranslations,
	walk,
	extractTranslationCalls,
	makeLineResolver,
	collectDynamicKeys,
} = require('./lib/l10n.js')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')
const L10N_FILE = path.join(ROOT, 'l10n', 'en.js')

const RED = '\x1b[31m'
const YELLOW = '\x1b[33m'
const GREEN = '\x1b[32m'
const CYAN = '\x1b[36m'
const DIM = '\x1b[2m'
const BOLD = '\x1b[1m'
const RESET = '\x1b[0m'

function rel(p) {
	return path.relative(ROOT, p)
}

/**
 * Extract t('<app>', '...') and n('<app>', '...', '...', n) calls, plus the
 * $t/$n template variants, via the shared extractor in lib/l10n.js so this
 * script, clean-l10n.js and l10n-ai.js always agree on what "used" means.
 *
 * Both of an n() call's key arguments are recorded: the singular and the plural
 * are each a real catalogue key.
 *
 * Returns { found: Map<key, [{file,line}]>, unanalyzable: [{file,line,snippet}] }.
 */
function extractTCalls(files, app) {
	const found = new Map()
	const unanalyzable = []
	// plural source string -> the singular key that owns it. An n() call has two
	// source strings but only ONE catalogue key (the singular); the plural lives
	// in that key's value array. Without this, every plural source reads as a
	// missing key that can never be satisfied.
	const pluralOf = new Map()

	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		const posToLine = makeLineResolver(text)
		const { calls, unanalyzable: bad } = extractTranslationCalls(text, app)

		for (const c of calls) {
			const line = posToLine(c.index)
			if (c.fn === 'n' && c.keys.length === 2) pluralOf.set(c.keys[1], c.keys[0])
			for (const key of c.keys) {
				if (!found.has(key)) found.set(key, [])
				found.get(key).push({ file, line })
			}
		}
		for (const b of bad) {
			unanalyzable.push({
				file,
				line: posToLine(b.index),
				snippet: text.slice(b.index, Math.min(b.index + 80, text.length)).replace(/\n/g, ' '),
			})
		}
	}

	return { found, unanalyzable, pluralOf }
}

/**
 * Find unwrapped static string literals in .vue files that match an l10n key.
 *
 * Scope: only .vue <template> blocks. We look for:
 *   - text between tags:  >Some text<
 *   - quoted attribute values on non-bound attrs:  title="Some text"
 * and skip anything inside {{ ... }} or on :bound / v-on attributes.
 *
 * This is heuristic and can produce false positives; each hit is reported with
 * file:line so humans can audit.
 */
// Attributes whose value is an internal identifier (route name, slot key, etc.)
// rather than user-visible prose. Values on these attrs may coincidentally
// match an l10n key but must NOT be wrapped in t().
const NON_DISPLAY_ATTRS = new Set([
	'back-route', // Vue Router route name passed to $router.push({ name })
])

function findUnwrapped(vueFiles, keys) {
	const hits = []
	for (const file of vueFiles) {
		const text = fs.readFileSync(file, 'utf8')
		const tplMatch = text.match(/<template[^>]*>([\s\S]*?)<\/template>/)
		if (!tplMatch) continue
		const tpl = tplMatch[1]
		const tplOffset = tplMatch.index + tplMatch[0].indexOf(tpl)

		const lineStarts = [0]
		for (let i = 0; i < text.length; i++) {
			if (text.charCodeAt(i) === 10) lineStarts.push(i + 1)
		}
		const posToLine = (pos) => {
			let lo = 0; let hi = lineStarts.length - 1
			while (lo < hi) {
				const mid = (lo + hi + 1) >> 1
				if (lineStarts[mid] <= pos) lo = mid
				else hi = mid - 1
			}
			return lo + 1
		}

		const textRe = />([^<>{}]+)</g
		let tm
		while ((tm = textRe.exec(tpl)) !== null) {
			const raw = tm[1]
			const trimmed = raw.trim()
			if (!trimmed) continue
			if (keys.has(trimmed)) {
				const absPos = tplOffset + tm.index + 1 + raw.indexOf(trimmed)
				hits.push({ file, line: posToLine(absPos), key: trimmed, context: 'text' })
			}
		}

		const tagRe = /<[a-zA-Z][^>]*>/g
		let tag
		while ((tag = tagRe.exec(tpl)) !== null) {
			const tagText = tag[0]
			const tagAbs = tplOffset + tag.index
			const attrRe = /(\s)([:@]?[a-zA-Z_][\w-]*|v-[\w:.-]+)\s*=\s*("([^"]*)"|'([^']*)')/g
			let am
			while ((am = attrRe.exec(tagText)) !== null) {
				const name = am[2]
				if (name.startsWith(':') || name.startsWith('@') || name.startsWith('v-')) continue
				if (NON_DISPLAY_ATTRS.has(name.toLowerCase())) continue
				const value = am[4] !== undefined ? am[4] : am[5]
				const trimmed = value.trim()
				if (!trimmed) continue
				if (keys.has(trimmed)) {
					const valueOffsetInTag = am.index + am[0].indexOf(am[3]) + 1
					const absPos = tagAbs + valueOffsetInTag
					hits.push({ file, line: posToLine(absPos), key: trimmed, context: `attr ${name}` })
				}
			}
		}
	}
	return hits
}

function printSection(title, color, body) {
	console.log(`${color}${BOLD}${title}${RESET}`)
	console.log(body)
	console.log('')
}

function main() {
	const { app, translations } = loadJsTranslations(L10N_FILE)
	const keys = new Set(Object.keys(translations))
	const files = walk(SRC_DIR, ['.vue', '.js', '.ts'])
	const vueFiles = files.filter(f => f.endsWith('.vue'))

	const { found, unanalyzable, pluralOf } = extractTCalls(files, app)
	const usedKeys = new Set(found.keys())

	// A plural source is satisfied by its singular key holding a forms array.
	const satisfiedByPlural = (k) => {
		const singular = pluralOf.get(k)
		return singular !== undefined && Array.isArray(translations[singular])
	}
	const missing = [...usedKeys].filter(k => !keys.has(k) && !satisfiedByPlural(k)).sort()
	// Variable-keyed t() calls are invisible to the scan; those keys are live.
	const dynamic = collectDynamicKeys(ROOT)
	const unused = [...keys].filter(k => !usedKeys.has(k) && !dynamic.has(k)).sort()
	const unwrapped = findUnwrapped(vueFiles, keys)

	console.log(`${BOLD}${CYAN}${app} l10n check${RESET}`)
	console.log(`${DIM}Scanned ${files.length} files (${vueFiles.length} .vue), ${keys.size} keys in en.js${RESET}`)
	console.log('')

	if (missing.length) {
		const body = missing.map(k => {
			const locs = found.get(k).map(l => `${DIM}${rel(l.file)}:${l.line}${RESET}`).join(', ')
			return `  ${RED}•${RESET} ${JSON.stringify(k)}\n    ${locs}`
		}).join('\n')
		printSection(`MISSING from l10n/en.js (${missing.length})`, RED, body)
	} else {
		printSection('MISSING from l10n/en.js (0)', GREEN, '  ✓ none')
	}

	if (unused.length) {
		const body = unused.map(k => `  ${YELLOW}•${RESET} ${JSON.stringify(k)}`).join('\n')
		printSection(`UNUSED keys in l10n/en.js (${unused.length})`, YELLOW, body)
	} else {
		printSection('UNUSED keys in l10n/en.js (0)', GREEN, '  ✓ none')
	}

	if (unwrapped.length) {
		const body = unwrapped.map(h =>
			`  ${YELLOW}•${RESET} ${JSON.stringify(h.key)} ${DIM}[${h.context}]${RESET}\n    ${DIM}${rel(h.file)}:${h.line}${RESET}`,
		).join('\n')
		printSection(`UNWRAPPED literals matching an l10n key (${unwrapped.length})`, YELLOW, body)
	} else {
		printSection('UNWRAPPED literals matching an l10n key (0)', GREEN, '  ✓ none')
	}

	if (unanalyzable.length) {
		const body = unanalyzable.map(u =>
			`  ${DIM}•${RESET} ${rel(u.file)}:${u.line}\n    ${DIM}${u.snippet}...${RESET}`,
		).join('\n')
		printSection(`Unanalyzable t() calls — dynamic args, skipped (${unanalyzable.length})`, DIM, body)
	}

	const total = missing.length + unused.length + unwrapped.length
	if (total > 0) {
		console.log(`${RED}${BOLD}✗ ${total} issue(s) found${RESET}`)
		process.exit(1)
	} else {
		console.log(`${GREEN}${BOLD}✓ all clean${RESET}`)
	}
}

main()
