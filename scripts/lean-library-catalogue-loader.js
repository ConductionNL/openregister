/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Webpack loader for @conduction/nextcloud-vue's English catalogue
 * (`l10n/en.json`), as the global integration script fetches it.
 *
 * The global script runs on every Nextcloud page and loads the English
 * catalogue for every reader: English readers for its strings, everyone else
 * for the English plural msgids that complete the plural lookup keys
 * (src/services/libraryTranslations.js). Of its ~2,950 translations all but a
 * handful map a string to itself, which `t()` does without a catalogue, so
 * shipping them only costs every page ~160 KB of script to download, parse and
 * register. This loader drops those identity translations and keeps every
 * other translation and all plurals: what `t()` and `n()` render is unchanged.
 *
 * @spec exclude Build tooling: shrinks a third-party string catalogue; no OpenSpec requirement covers UI string catalogues.
 */

/**
 * The catalogue without its identity translations.
 *
 * @param {object} catalogue A `{ translations, plurals }` catalogue.
 * @return {object} The same catalogue, translations whose value equals their key left out.
 */
function withoutIdentityTranslations(catalogue) {
	const translations = {}
	for (const [source, value] of Object.entries(catalogue?.translations ?? {})) {
		if (value !== source) {
			translations[source] = value
		}
	}
	return { ...catalogue, translations }
}

/**
 * The loader: JSON in, JSON out.
 *
 * @param {string} source The catalogue's JSON text.
 * @return {string} The lean catalogue's JSON text.
 */
function leanLibraryCatalogueLoader(source) {
	return JSON.stringify(withoutIdentityTranslations(JSON.parse(source)))
}

module.exports = leanLibraryCatalogueLoader
module.exports.withoutIdentityTranslations = withoutIdentityTranslations
