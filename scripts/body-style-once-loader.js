/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Webpack loader for @nextcloud/vue's NcSelect, NcSelectUsers and
 * NcProgressBar chunks: their reads of the page's body custom properties
 * happen once per page instead of twice per component.
 *
 * NcSelect's setup() (and NcSelectUsers') computes an avatar size from
 * `window.getComputedStyle(document.body).getPropertyValue('--default-clickable-area')`
 * and `--default-grid-baseline`, every time a select is created. A
 * getComputedStyle() read while the page is still being built forces the
 * browser to recalculate the styles of the whole document first, so every
 * select on a page costs two full style recalculations during the first
 * render: ~73 ms on the OpenRegister Registers page (five selects, measured
 * by CPU profile on the reference stack). The two properties are the theme's
 * size tokens: they do not change while a page is open, so reading each once
 * per page renders the same avatar size.
 *
 * The loader rewrites exactly that expression to a per-page memo; every other
 * line of the chunk is left as it is. A chunk without the expression passes
 * through unchanged.
 *
 * @spec exclude Build tooling: patches a third-party library's style reads; no OpenSpec requirement covers it.
 */

/**
 * `window.getComputedStyle(document.body).getPropertyValue("--name")`, quoted either way.
 *
 * @type {RegExp}
 */
const BODY_READ = /window\.getComputedStyle\(document\.body\)\.getPropertyValue\((["'])(--[\w-]+)\1\)/g

/**
 * The memo the rewritten reads call. One map per page, shared by every chunk,
 * on `globalThis` so the separately bundled chunks see the same values.
 *
 * @type {string}
 */
const MEMO = `
function __cnBodyStyleValue(name) {
	const memo = globalThis.__cnBodyStyleValues || (globalThis.__cnBodyStyleValues = new Map())
	if (!memo.has(name)) {
		memo.set(name, window.getComputedStyle(document.body).getPropertyValue(name))
	}
	return memo.get(name)
}
`

/**
 * The chunk with its body style reads going through the per-page memo.
 *
 * @param {string} source The module source.
 * @return {string} The source, rewritten when it reads body custom properties.
 */
function readBodyStyleOnce(source) {
	if (!BODY_READ.test(source)) {
		return source
	}
	BODY_READ.lastIndex = 0
	return source.replace(BODY_READ, (_read, quote, name) => `__cnBodyStyleValue(${quote}${name}${quote})`) + MEMO
}

module.exports = readBodyStyleOnce
module.exports.readBodyStyleOnce = readBodyStyleOnce
