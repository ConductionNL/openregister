/**
 * The Vue half of a Files sidebar tab: loaded with the tab's own code, the
 * first time a tab renders.
 *
 * The Files sidebar entry runs on every page with a Files sidebar, so it holds
 * no Vue and nothing from OpenRegister's vendor chunk: this module brings Vue
 * in when a tab is opened.
 *
 * @license EUPL-1.2
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp, h, reactive } from 'vue'

/**
 * Mount a tab's component in the sidebar's element.
 *
 * The app and its props are kept on the element, under `appKey` and
 * `propsKey`. The props object is made `reactive` in place, so a `fileId`
 * written by `update()` before the component loaded is the one it renders.
 *
 * @param {object} options What to mount
 * @param {HTMLElement} options.el The element the Files sidebar owns
 * @param {string} options.appKey Where the app is kept on `el`
 * @param {string} options.propsKey Where the root props are kept on `el`
 * @param {function(): Promise<object>} options.load Resolves the tab's component
 * @param {function(): boolean} options.isCurrent False once the tab was destroyed or mounted again meanwhile
 * @return {Promise<void>}
 * @spec openspec/specs/frontend-app-bootstrap/spec.md
 */
export async function mountTabApp({ el, appKey, propsKey, load, isCurrent }) {
	const component = await load()
	if (!isCurrent()) {
		return
	}

	if (el[appKey]) {
		el[appKey].unmount()
	}

	el[propsKey] = reactive(el[propsKey])
	// Rendered through a root render function: `createApp(Comp, props)`
	// copies a reactive props object, so `update()` would never reach it.
	const app = createApp({
		render: () => h(component, el[propsKey]),
	})
	// Vue 3's `app.mixin()` is per-app: each entry bundle installs `t`/`n` itself.
	app.mixin({ methods: { t, n } })
	app.mount(el)
	el[appKey] = app
}
