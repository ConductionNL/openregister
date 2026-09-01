#!/usr/bin/env node
 
/**
 * Mid-sentence capitalisation of domain terms, for one bundle, against a baseline.
 *
 *   node scripts/l10n/casing.js <loc> [--min-words=6] [--top=30] [--mine] [--all-terms]
 *
 * WHY THIS EXISTS. §8.10 says house conventions touch more values than register
 * does and no gate can see them, and casing is the largest of them: it decided a
 * third of `sr`'s values and was the whole dominant class of the `mk` pass (118
 * occurrences over 105 values). Five passes measured it by hand, and the runbook
 * accumulated three separate notes on how the hand measurement misleads. Those
 * three are encoded here so the next pass cannot repeat them.
 *
 * THE MEASUREMENT IS CONDITIONED ON THE ENGLISH KEY, and that is the whole point.
 * A naive mid-sentence scan put `sq` at 25-35% against a family rate near zero — a
 * tidy ~110-value defect class that does not exist. Restricted to prose it was
 * 0 of 177: every hit was a Title-Cased heading correctly mirroring its source.
 * So two populations are counted separately and never summed:
 *
 *   PROSE  — English key >= --min-words words AND not Title Case. A capital here
 *            has no source to mirror, so it is a candidate defect.
 *   LABEL  — everything else. A capital here is usually the source's own Title
 *            Case showing through, so the report gives the rate and makes no
 *            claim. `mk` failed this side too (65:507 against core's 7:122), so
 *            it is reported rather than suppressed.
 *
 * The conditioned figure can still come back real: on `mk` it did, 41 against 146.
 * What separates that from `sq`'s phantom is that it survives the restriction and
 * that the bundle is INTERNALLY INCONSISTENT — so every term is printed with its
 * own up:down split. A term that is 0:26 one way and 20:0 the other is two
 * decisions, not one rule. Per §8.11, a uniform lemma is one decision copied.
 *
 * THE THREE WAYS THE HAND MEASUREMENT MISLED, all met on `mk`, all handled here:
 *   1. A pattern without the `i` flag matches only the lowercase form, so every
 *      capitalised occurrence — the entire thing being measured — is invisible and
 *      each term reports a clean 0-up. Terms are indexed case-folded.
 *   2. `(?<=.)` excludes the value's first word but NOT a later sentence's, so
 *      "… pretraga. Objekti se …" scores as a mid-sentence capital. Three `mk`
 *      first-cut hits were that, and each would have been "corrected" into an
 *      error. Sentence starts are tracked across the whole value.
 *   3. An opening bracket, a leading emoji and deliberate all-caps each license a
 *      capital the same way a sentence start does — 14 of `mk`'s 132 raw hits were
 *      those.
 *
 * `--mine` splits the corpus at HEAD and measures only values this working tree
 * added or changed. Whole-bundle counts hide a pass's own drift because the
 * pre-existing majority outvotes it; that split caught 24 `mt` values, 6 on `is`
 * and 44 on `lb`. Run it on your own half before calling a pass done.
 *
 * A READING AID, NEVER A GATE. Which side of a split is right is not this tool's
 * call: review the word list before applying any fix, because a capital may be a
 * proper noun, a product name or an acronym that this cannot know about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const {
	loadJsTranslations, APP_ROOT, coreCatalogues, bundleAtHead, isIdentical,
} = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: casing.js <loc> [--min-words=6] [--top=30] [--mine] [--all-terms]')
	process.exit(2)
}
/**
 *
 * @param flag
 * @param dflt
 */
function num (flag, dflt) {
	const a = process.argv.find((x) => x.startsWith(`--${flag}=`))
	return a ? Number(a.slice(flag.length + 3)) : dflt
}
const MIN_WORDS = num('min-words', 6)
const TOP = num('top', 30)
const MINE = process.argv.includes('--mine')
const ALL_TERMS = process.argv.includes('--all-terms')

// ---------------------------------------------------------------- classification

/**
 * Title Case in the Nextcloud source means most content words capitalised, which
 * is how headings and column labels are written. A value mirroring that is doing
 * the right thing, so these keys must not be pooled with prose.
 *
 * @param {string} key English source string.
 * @return {boolean} True when the key is written as a heading.
 */
function isTitleCase(key) {
	const words = (key.match(/[A-Za-z][A-Za-z'-]*/g) || []).filter((w) => w.length > 3)
	if (words.length < 2) return true
	const capped = words.filter((w) => /^[A-Z]/.test(w)).length
	return capped / words.length >= 0.6
}

/**
 * @param {string} key English source string.
 * @return {boolean} True when the key is prose: long enough to have sentence
 *   structure, and not a heading.
 */
function isProse(key) {
	const words = key.match(/\S+/g) || []
	return words.length >= MIN_WORDS && !isTitleCase(key)
}

// ------------------------------------------------------------------- the scanner

/**
 * Capitalised words that are NOT at a licensed position, i.e. genuinely
 * mid-sentence. Traps 2 and 3 live here: sentence starts are tracked for every
 * sentence in the value, not just the first, and brackets, quotes, bullets and
 * the symbol runs the bundle uses as icons license a capital just as a full stop
 * does. All-caps tokens are skipped — those are acronyms, not a casing decision.
 *
 * @param {string} value Target-language value.
 * @return {string[]} The offending words, in order.
 */
function midSentenceCaps(value) {
	const s = String(value)
		.replace(/\{[^}]*\}/g, '  ') // placeholders carry the source's own casing
		.replace(/`[^`]*`/g, '  ')
		.replace(/https?:\/\/\S+/g, '  ')
	const out = []
	let licensed = true // the start of the value is licensed
	// Whether the previous word was unambiguously all-caps. A ONE-LETTER word is
	// never unambiguous — "O" is both a preposition and a letter of an all-caps
	// heading — so `letters.length > 1` alone cannot classify it, and every such
	// word inside an all-caps run scored as a mid-sentence capital. That is the
	// deliberate-all-caps license of trap 3 arriving one token later than expected.
	let inCaps = false
	const tokens = s.match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*|[^\p{L}\p{N}\s]+|\s+/gu) || []
	for (const tk of tokens) {
		if (/^\s+$/.test(tk)) continue
		if (/^[\p{L}\p{N}]/u.test(tk)) {
			const letters = tk.replace(/[^\p{L}]/gu, '')
			const isUpperStart = /^\p{Lu}/u.test(tk)
			const allCapsRun = letters.length > 1 && letters === letters.toUpperCase()
			// A single uppercase letter counts as all-caps only while a run is open.
			const isAllCaps = allCapsRun || (inCaps && letters.length === 1 && isUpperStart)
			if (isUpperStart && !isAllCaps && !licensed) out.push(tk)
			licensed = false
			inCaps = isAllCaps
			continue
		}
		// Punctuation. Decide whether what follows is licensed. Sentence-final marks
		// license the next word (trap 2), and so do openers, bullets, slashes and the
		// symbol runs used as icons (trap 3). A comma or a semicolon licenses
		// nothing, which is the whole point of the check.
		if (/[.!?:…]/.test(tk)) licensed = true
		else if (/[([{"'«„“‘–—•|/\\]/u.test(tk)) licensed = true
		else if (/\p{S}/u.test(tk)) licensed = true
	}
	return out
}

const fold = (s) => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()

// -------------------------------------------------------------------- the corpus

/**
 * @param {object} tr A parsed catalogue's translations map.
 * @param {Set<string>|null} only Restrict to these keys, or null for all.
 * @return {object} { tally, terms }
 */
function measure(tr, only = null) {
	// up = capitalised mid-sentence, down = lowercase mid-sentence. Both are
	// counted per term so internal inconsistency is visible (trap 1).
	const terms = new Map()
	const bump = (word, dir, pop, key) => {
		const f = fold(word)
		if (!terms.has(f)) {
			terms.set(f, { proseUp: 0, proseDown: 0, labelUp: 0, labelDown: 0, egs: [] })
		}
		const t = terms.get(f)
		t[pop + dir]++
		if (dir === 'Up' && t.egs.length < 3) t.egs.push(key)
	}
	const tally = {
		prose: { up: 0, down: 0, values: 0 },
		label: { up: 0, down: 0, values: 0 },
	}

	for (const [key, value] of Object.entries(tr)) {
		if (only && !only.has(key)) continue
		for (const v of [].concat(value)) {
			if (typeof v !== 'string' || !v.trim()) continue
			// A value byte-equal to its key is untranslated English and carries the
			// source's casing, not the locale's. Counting it measures English.
			if (isIdentical(key, v)) continue
			const pop = isProse(key) ? 'prose' : 'label'
			tally[pop].values++
			const caps = midSentenceCaps(v)
			tally[pop].up += caps.length
			for (const w of caps) bump(w, 'Up', pop, key)
			// The lowercase side, for the per-term split: mid-sentence words that
			// stayed lowercase. That split is what tells one decision from a rule.
			const lower = v.match(/(?<=[\p{L}\p{N}],?\s)\p{Ll}[\p{L}'’-]{3,}/gu) || []
			tally[pop].down += lower.length
			for (const w of lower) bump(w, 'Down', pop, key)
		}
	}
	return { tally, terms }
}

const { translations } = loadJsTranslations(path.join(APP_ROOT, 'l10n', `${loc}.js`))

let only = null
if (MINE) {
	const head = bundleAtHead(loc)
	if (!head) {
		console.error(`casing ${loc}: --mine needs l10n/${loc}.js at HEAD; not found.`)
		process.exit(2)
	}
	only = new Set()
	for (const [k, v] of Object.entries(translations)) {
		if (JSON.stringify(head[k]) !== JSON.stringify(v)) only.add(k)
	}
	console.log(`casing ${loc}: --mine — ${only.size} value(s) added or changed since HEAD\n`)
}

const mine = measure(translations, only)

// ----------------------------------------------------------------- the baselines

/** @return {object} Tally over the sibling apps' frontend bundles for this locale. */
function siblingBaseline() {
	const appsDir = path.resolve(APP_ROOT, '..')
	const merged = {}
	let apps = 0
	for (const a of fs.readdirSync(appsDir).sort()) {
		if (a === path.basename(APP_ROOT)) continue
		const f = path.join(appsDir, a, 'l10n', `${loc}.js`)
		if (!fs.existsSync(f)) continue
		try {
			// Frontend .js only — the backend .json is a separate catalogue with a
			// separate consumer (§1) and its casing would be miscredited here.
			Object.assign(merged, loadJsTranslations(f).translations || {})
			apps++
		} catch { /* a sibling with an unparseable bundle is not this pass's problem */ }
	}
	return { apps, ...measure(merged) }
}

/** @return {object|null} Tally over Nextcloud core's catalogues, or null if none. */
function coreBaseline() {
	let files
	try { files = coreCatalogues(loc) } catch { return null }
	const merged = {}
	for (const f of files) {
		try {
			Object.assign(merged, JSON.parse(fs.readFileSync(f, 'utf8')).translations || {})
		} catch { /* an unreadable core catalogue is not this pass's problem */ }
	}
	return { files: files.length, ...measure(merged) }
}

const sib = siblingBaseline()
const core = coreBaseline()

// --------------------------------------------------------------------- reporting

const ratio = (t) => `${t.up}:${t.down}`
const pct = (t) => (t.up + t.down ? `${(100 * t.up / (t.up + t.down)).toFixed(1)}%` : 'n/a')

console.log(`casing ${loc}: mid-sentence capitalisation, conditioned on the English key`)
console.log(`(prose = key >=${MIN_WORDS} words and not Title Case; label = everything else)`)
console.log()
console.log('                        prose (up:down)   label (up:down)   values')
/**
 *
 * @param name
 * @param m
 * @param extra
 */
function row (name, m, extra) {
	console.log(`  ${name.padEnd(20)}  ${ratio(m.tally.prose).padEnd(16)}  `
		+ `${ratio(m.tally.label).padEnd(16)}  ${m.tally.prose.values + m.tally.label.values}`
		+ (extra ? `   ${extra}` : ''))
}
row('this bundle', mine)
row('sibling frontends', sib, `${sib.apps} app(s)`)
if (core) row('nextcloud core', core, `${core.files} catalogue(s)`)
else console.log('  nextcloud core        (no catalogues for this locale)')
console.log()
console.log(`  prose capitalisation rate: bundle ${pct(mine.tally.prose)}, `
	+ `siblings ${pct(sib.tally.prose)}${core ? `, core ${pct(core.tally.prose)}` : ''}`)
console.log()

// Terms are ranked by prose-up, because that is the only population where a
// capital is a candidate defect at all.
const ranked = [...mine.terms]
	.filter(([, t]) => (ALL_TERMS ? t.proseUp + t.labelUp > 0 : t.proseUp > 0))
	.sort((a, b) => b[1].proseUp - a[1].proseUp || b[1].labelUp - a[1].labelUp)

if (!ranked.length) {
	console.log('No mid-sentence capitals in prose. If the label column is large, the bundle')
	console.log('is mirroring Title Case from its source — that is the sq case, not a defect.')
} else {
	console.log(`terms capitalised mid-sentence in PROSE (${ranked.length}), with each term's`)
	console.log("own up:down split. A term that is one-sided in both populations is a")
	console.log('convention; a term split against itself is two decisions (§8.11).')
	console.log()
	for (const [term, t] of ranked.slice(0, TOP)) {
		console.log(`  ${term.padEnd(22)} prose ${`${t.proseUp}:${t.proseDown}`.padEnd(9)} `
			+ `label ${`${t.labelUp}:${t.labelDown}`.padEnd(9)}`)
		for (const k of t.egs) console.log(`      ${JSON.stringify(k.slice(0, 68))}`)
	}
	if (ranked.length > TOP) console.log(`\n… +${ranked.length - TOP} more below the top ${TOP}`)
}

console.log()
console.log(`casing ${loc}: a reading aid; never fails. Review the word list before fixing —`)
console.log('a capital may be a proper noun, a product name or an acronym (§8.10).')
