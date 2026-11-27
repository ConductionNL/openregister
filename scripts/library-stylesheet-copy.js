/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Webpack plugin: copies @conduction/nextcloud-vue's built stylesheet to
 * css/nextcloud-vue.css after every build, so the app page links it
 * (templates/index.php) instead of the main bundle inserting it as text.
 *
 * The main entry imported '@conduction/nextcloud-vue/css/index.css', which
 * style-loader turns into ~1.4 MB of CSS text inserted on every page load:
 * the browser parses it again on each load (~60 ms of appendChild in a CPU
 * profile of the Registers page on the reference stack). A linked stylesheet
 * stays parsed in the browser's memory cache between loads. The same pattern
 * as launchpad#2270.
 *
 * @spec exclude Build tooling: where the library stylesheet is emitted; no OpenSpec requirement covers it.
 */
const fs = require('fs')
const path = require('path')

/**
 * The file '@conduction/nextcloud-vue/css/index.css' stands for: the sheet it
 * `@import`s, or the entry itself when it imports nothing.
 *
 * @param {string} entry The resolved path of css/index.css
 * @return {string} The stylesheet to copy
 */
function librarySheet(entry) {
	const imported = /@import\s+(?:url\()?['"]([^'"]+)['"]/.exec(fs.readFileSync(entry, 'utf8'))
	return imported ? path.resolve(path.dirname(entry), imported[1]) : entry
}

/**
 * Copies the library stylesheet to `<target>` after webpack emits.
 */
class LibraryStylesheetCopy {

	/**
	 * @param {object} options The options
	 * @param {string} options.target Where to write the sheet
	 * @param {function(string): string} [options.resolve] Resolves a module request to a path
	 */
	constructor({ target, resolve = require.resolve }) {
		this.target = target
		this.resolve = resolve
	}

	/**
	 * Run the copy after every emit.
	 *
	 * @param {object} compiler The webpack compiler
	 * @return {void}
	 */
	apply(compiler) {
		compiler.hooks.afterEmit.tap('LibraryStylesheetCopy', () => this.copy())
	}

	/**
	 * Copy the sheet.
	 *
	 * @return {void}
	 */
	copy() {
		const source = librarySheet(this.resolve('@conduction/nextcloud-vue/css/index.css'))
		fs.mkdirSync(path.dirname(this.target), { recursive: true })
		fs.copyFileSync(source, this.target)
	}

}

module.exports = LibraryStylesheetCopy
module.exports.librarySheet = librarySheet
