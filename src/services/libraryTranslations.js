/**
 * Register @conduction/nextcloud-vue's own translation catalogue.
 *
 * The library translates every label it draws under its own `nextcloud-vue`
 * app id, and nothing registers that catalogue unless the host bundle asks.
 * Every webpack entry that reaches the library must call this once, or the
 * library's labels stay English on that page while openregister's own strings
 * follow the reader's language. `src/tests/entry-translations.spec.js` holds
 * every entry in `webpack.config.js` to that.
 *
 * The call is cheap and idempotent: it merges the reader's language into the
 * shared `@nextcloud/l10n` registry, so a page that loads two of our entries
 * (or a leaf app's bundle beside ours) registers the same strings twice and
 * nothing more.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
// Imported from its own module, not the package barrel: the barrel keeps every
// component it re-exports (the package marks `.vue` modules as side effects),
// and this file is part of the script Nextcloud loads on every page.
import { registerTranslations } from '@conduction/nextcloud-vue/dist/esm/l10n/index.js'
import { getLanguage, register } from '@nextcloud/l10n'

/**
 * Register the library's catalogue for the current language.
 *
 * Never throws. A failed registration leaves the library's labels in their
 * English source, which is a worse page but not a broken one.
 *
 * @spec exclude UI plumbing: registers a third-party string catalogue; no OpenSpec requirement covers UI string catalogues.
 * @return {void}
 */
export function registerLibraryTranslations() {
	try {
		registerTranslations()
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			'[openregister] registerTranslations failed; library labels stay English',
			e,
		)
	}
}

/**
 * The library's locale catalogues, each fetched as its own chunk.
 *
 * One literal import per locale (the package's exports map refuses a template
 * import of the folder), so webpack emits one small chunk per language and a
 * page downloads only the reader's. `libraryTranslations.spec.js` holds this
 * list to the catalogues the installed library ships.
 */
export const LIBRARY_CATALOGUES = {
	be: () => import(/* webpackChunkName: "nextcloud-vue-l10n-be" */ '@conduction/nextcloud-vue/l10n/be.json'),
	bg: () => import(/* webpackChunkName: "nextcloud-vue-l10n-bg" */ '@conduction/nextcloud-vue/l10n/bg.json'),
	bs: () => import(/* webpackChunkName: "nextcloud-vue-l10n-bs" */ '@conduction/nextcloud-vue/l10n/bs.json'),
	ca: () => import(/* webpackChunkName: "nextcloud-vue-l10n-ca" */ '@conduction/nextcloud-vue/l10n/ca.json'),
	cs: () => import(/* webpackChunkName: "nextcloud-vue-l10n-cs" */ '@conduction/nextcloud-vue/l10n/cs.json'),
	da: () => import(/* webpackChunkName: "nextcloud-vue-l10n-da" */ '@conduction/nextcloud-vue/l10n/da.json'),
	de: () => import(/* webpackChunkName: "nextcloud-vue-l10n-de" */ '@conduction/nextcloud-vue/l10n/de.json'),
	el: () => import(/* webpackChunkName: "nextcloud-vue-l10n-el" */ '@conduction/nextcloud-vue/l10n/el.json'),
	en: () => import(/* webpackChunkName: "nextcloud-vue-l10n-en" */ '@conduction/nextcloud-vue/l10n/en.json'),
	es: () => import(/* webpackChunkName: "nextcloud-vue-l10n-es" */ '@conduction/nextcloud-vue/l10n/es.json'),
	et: () => import(/* webpackChunkName: "nextcloud-vue-l10n-et" */ '@conduction/nextcloud-vue/l10n/et.json'),
	fi: () => import(/* webpackChunkName: "nextcloud-vue-l10n-fi" */ '@conduction/nextcloud-vue/l10n/fi.json'),
	fr: () => import(/* webpackChunkName: "nextcloud-vue-l10n-fr" */ '@conduction/nextcloud-vue/l10n/fr.json'),
	ga: () => import(/* webpackChunkName: "nextcloud-vue-l10n-ga" */ '@conduction/nextcloud-vue/l10n/ga.json'),
	hr: () => import(/* webpackChunkName: "nextcloud-vue-l10n-hr" */ '@conduction/nextcloud-vue/l10n/hr.json'),
	hu: () => import(/* webpackChunkName: "nextcloud-vue-l10n-hu" */ '@conduction/nextcloud-vue/l10n/hu.json'),
	is: () => import(/* webpackChunkName: "nextcloud-vue-l10n-is" */ '@conduction/nextcloud-vue/l10n/is.json'),
	it: () => import(/* webpackChunkName: "nextcloud-vue-l10n-it" */ '@conduction/nextcloud-vue/l10n/it.json'),
	lb: () => import(/* webpackChunkName: "nextcloud-vue-l10n-lb" */ '@conduction/nextcloud-vue/l10n/lb.json'),
	lt: () => import(/* webpackChunkName: "nextcloud-vue-l10n-lt" */ '@conduction/nextcloud-vue/l10n/lt.json'),
	lv: () => import(/* webpackChunkName: "nextcloud-vue-l10n-lv" */ '@conduction/nextcloud-vue/l10n/lv.json'),
	mk: () => import(/* webpackChunkName: "nextcloud-vue-l10n-mk" */ '@conduction/nextcloud-vue/l10n/mk.json'),
	mt: () => import(/* webpackChunkName: "nextcloud-vue-l10n-mt" */ '@conduction/nextcloud-vue/l10n/mt.json'),
	nb: () => import(/* webpackChunkName: "nextcloud-vue-l10n-nb" */ '@conduction/nextcloud-vue/l10n/nb.json'),
	nl: () => import(/* webpackChunkName: "nextcloud-vue-l10n-nl" */ '@conduction/nextcloud-vue/l10n/nl.json'),
	pl: () => import(/* webpackChunkName: "nextcloud-vue-l10n-pl" */ '@conduction/nextcloud-vue/l10n/pl.json'),
	pt: () => import(/* webpackChunkName: "nextcloud-vue-l10n-pt" */ '@conduction/nextcloud-vue/l10n/pt.json'),
	rm: () => import(/* webpackChunkName: "nextcloud-vue-l10n-rm" */ '@conduction/nextcloud-vue/l10n/rm.json'),
	ro: () => import(/* webpackChunkName: "nextcloud-vue-l10n-ro" */ '@conduction/nextcloud-vue/l10n/ro.json'),
	ru: () => import(/* webpackChunkName: "nextcloud-vue-l10n-ru" */ '@conduction/nextcloud-vue/l10n/ru.json'),
	sk: () => import(/* webpackChunkName: "nextcloud-vue-l10n-sk" */ '@conduction/nextcloud-vue/l10n/sk.json'),
	sl: () => import(/* webpackChunkName: "nextcloud-vue-l10n-sl" */ '@conduction/nextcloud-vue/l10n/sl.json'),
	sq: () => import(/* webpackChunkName: "nextcloud-vue-l10n-sq" */ '@conduction/nextcloud-vue/l10n/sq.json'),
	sr: () => import(/* webpackChunkName: "nextcloud-vue-l10n-sr" */ '@conduction/nextcloud-vue/l10n/sr.json'),
	sv: () => import(/* webpackChunkName: "nextcloud-vue-l10n-sv" */ '@conduction/nextcloud-vue/l10n/sv.json'),
	tr: () => import(/* webpackChunkName: "nextcloud-vue-l10n-tr" */ '@conduction/nextcloud-vue/l10n/tr.json'),
	uk: () => import(/* webpackChunkName: "nextcloud-vue-l10n-uk" */ '@conduction/nextcloud-vue/l10n/uk.json'),
}

/**
 * Fetch one of the library's locale catalogues.
 *
 * @spec exclude UI plumbing: loads a third-party string catalogue; no OpenSpec requirement covers UI string catalogues.
 * @param {string} lang A two-letter language code.
 * @return {Promise<object>} The catalogue, `{ translations, plurals }`; rejects for a language the library does not ship.
 */
function loadCatalogue(lang) {
	const loader = LIBRARY_CATALOGUES[lang]
	if (loader === undefined) {
		return Promise.reject(new Error(`no library catalogue for ${lang}`))
	}
	return loader().then((module) => module.default ?? module)
}

/**
 * A catalogue's plurals, keyed the way `@nextcloud/l10n` looks them up.
 *
 * The same keying as the library's own `pluralEntries()`: a locale stores its
 * plurals as `singular -> [forms]`, and the English plural msgid that completes
 * the lookup key comes from the English catalogue.
 *
 * @spec exclude UI plumbing: keys a third-party plural catalogue; no OpenSpec requirement covers UI string catalogues.
 * @param {object} bundle The reader's catalogue.
 * @param {object} english The English catalogue.
 * @return {object} Identifier-keyed plural entries.
 */
export function libraryPluralEntries(bundle, english) {
	const entries = {}
	for (const [singular, forms] of Object.entries(bundle?.plurals ?? {})) {
		const englishPlural = english?.plurals?.[singular]?.[1]
		if (!englishPlural || !Array.isArray(forms)) {
			continue
		}
		entries[`_${singular}_::_${englishPlural}_`] = forms
	}
	return entries
}

/**
 * Register the library's catalogue for the reader's language, loading only
 * that language.
 *
 * For an entry that runs on every page (the global integration script):
 * `registerLibraryTranslations()` pulls all 37 of the library's catalogues into
 * the entry itself, which then sits in front of every Nextcloud page. This one
 * fetches the reader's catalogue (and the English one its plural keys need) as
 * separate chunks after the page has its script. Nothing on such a page
 * renders a library label before an integration tab is opened, and the app
 * whose page it is registers the library's catalogue itself.
 *
 * Never rejects. A failed load leaves the library's labels in their English
 * source, which is a worse page but not a broken one.
 *
 * @spec exclude UI plumbing: registers a third-party string catalogue; no OpenSpec requirement covers UI string catalogues.
 * @param {function(string): Promise<object>} [load] Loads a catalogue by language code (a seam for tests).
 * @return {Promise<void>} Settles once the catalogue is registered or given up.
 */
export async function loadLibraryTranslations(load = loadCatalogue) {
	try {
		const lang = (getLanguage() || 'en').split(/[-_]/)[0]
		const english = await load('en')
		let bundle = english
		if (lang !== 'en') {
			bundle = await load(lang).catch(() => english)
		}
		register('nextcloud-vue', {
			...bundle.translations,
			...libraryPluralEntries(bundle, english),
		})
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			'[openregister] loading the library translations failed; library labels stay English',
			e,
		)
	}
}
