// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
/**
 * The umbrella app of the Nextcloud dashboard tile (ADR-019 Phase E, Option B).
 *
 * Loaded by `src/user-dashboard.js` only once the tile element exists, so a
 * Dashboard whose layout does not hold the OpenRegister tile downloads none of
 * this. Nextcloud calls every registered widget's `load()` on every Dashboard
 * render, whether the user placed the tile or not.
 *
 * The grid comes from its own dist module, not the package barrel: the barrel
 * keeps every component (the package marks `.vue` modules as side effects).
 * The library stylesheet is installed explicitly, as the barrel did.
 *
 * @license EUPL-1.2
 */

import CnIntegrationWidgetGrid from '@conduction/nextcloud-vue/dist/esm/components/CnIntegrationWidgetGrid/CnIntegrationWidgetGrid.vue.js'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import { ensureIntegrationRegistry } from '../integrations/bootstrap.js'
import { registerLibraryTranslations } from '../services/libraryTranslations.js'

import '@conduction/nextcloud-vue/dist/esm/library-style.js'

/**
 * Register translations and integrations, then mount the widget grid.
 *
 * @spec openspec/changes/archive/2026-07-07-frontend-code-splitting-and-fetch-efficiency/tasks.md
 * @param {HTMLElement} el The dashboard tile to mount into
 * @return {object} The mounted app
 */
export function mountUmbrellaApp(el) {
	// Register nextcloud-vue's own catalogue before anything renders, so the
	// library's labels follow the reader's language on this page too.
	registerLibraryTranslations()

	// Install + populate the integration registry on this entry bundle's
	// JS scope. Each NC entry bundle runs in isolation. Idempotent.
	ensureIntegrationRegistry()

	// Vue 3: a plain options object handed to createApp(); props pass FLAT.
	const app = createApp({
		name: 'IntegrationDashboardUmbrella',
		components: { CnIntegrationWidgetGrid },
		render() {
			return h(CnIntegrationWidgetGrid, { surface: 'user-dashboard' })
		},
	})

	// Vue 3's `app.mixin()` is scoped to one app instance, so this entry
	// installs `t`/`n` for everything it renders.
	app.mixin({ methods: { t, n } })

	const target = document.createElement('div')
	el.appendChild(target)
	app.mount(target)
	return app
}
