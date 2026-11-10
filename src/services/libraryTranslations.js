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
import { registerTranslations } from '@conduction/nextcloud-vue'

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
