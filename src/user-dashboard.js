/**
 * User-dashboard entry bundle (ADR-019 Phase E, Option B).
 *
 * Mounts a Vue app inside the NC global dashboard tile created by
 * `OCA\OpenRegister\Dashboard\IntegrationDashboardWidget` (the "umbrella"
 * widget). The mounted app delegates to {@see CnIntegrationWidgetGrid}
 * with `surface="user-dashboard"`, which iterates the pluggable
 * integration registry and mounts each leaf's `user-dashboard` widget
 * via the registry's AD-19 fallback rule.
 *
 * Option B is locked over per-integration NC widgets: 24 separate
 * dashboard tiles would clutter the NC dashboard. One umbrella tile
 * lists everything that registers itself.
 *
 * The umbrella does NO data fetching itself — each leaf's widget
 * self-fetches based on its own props/context.
 *
 * @license EUPL-1.2
 */

import Vue from 'vue'
import { CnIntegrationWidgetGrid } from '@conduction/nextcloud-vue'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'

// Install + populate the integration registry on this entry bundle's
// JS scope. Each NC entry bundle runs in isolation, so bootstrap must
// happen here in addition to main.js. Idempotent.
ensureIntegrationRegistry()

// Nextcloud's dashboard renders each widget into an element with id
// `<widget-id>` — see apps/dashboard/lib/Controller/DashboardController.
// Our IntegrationDashboardWidget::getId() returns 'openregister-integrations'.
const MOUNT_SELECTOR = '#openregister-integrations'

/**
 * Mount the umbrella Vue app once the DOM is ready and the NC
 * dashboard has rendered the tile container. The tile may not exist
 * yet when this script loads, so we retry on DOMContentLoaded and
 * fall back to a short polling window for late renders.
 */
function mountUmbrella() {
	const el = document.querySelector(MOUNT_SELECTOR)
	if (el === null) {
		return false
	}

	// Guard against double-mount when NC reloads the widget (e.g. on
	// dashboard layout change).
	if (el.dataset.openregisterMounted === '1') {
		return true
	}
	el.dataset.openregisterMounted = '1'

	const View = Vue.extend({
		name: 'IntegrationDashboardUmbrella',
		components: { CnIntegrationWidgetGrid },
		render(h) {
			return h(CnIntegrationWidgetGrid, {
				props: { surface: 'user-dashboard' },
			})
		},
	})

	const app = new View()
	const target = document.createElement('div')
	el.appendChild(target)
	app.$mount(target)

	return true
}

/**
 * Schedule mount: try immediately, then on DOMContentLoaded, then
 * poll briefly (Dashboard widgets often render after initial load).
 */
function scheduleMount() {
	if (mountUmbrella() === true) {
		return
	}

	const tryMount = () => {
		if (mountUmbrella() === true) {
			return true
		}
		return false
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', tryMount, { once: true })
	}

	// Polling window for late-mounting dashboard tiles. Stops as soon
	// as the umbrella mounts or after ~3 seconds.
	let attempts = 0
	const interval = window.setInterval(() => {
		attempts += 1
		if (tryMount() === true || attempts >= 30) {
			window.clearInterval(interval)
		}
	}, 100)
}

scheduleMount()
