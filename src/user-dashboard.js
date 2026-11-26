/**
 * User-dashboard entry bundle (ADR-019 Phase E, Option B).
 *
 * Mounts a Vue app inside the NC global dashboard tile created by
 * `OCA\OpenRegister\Dashboard\IntegrationDashboardWidget` (the "umbrella"
 * widget). The mounted app delegates to `CnIntegrationWidgetGrid`
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

/**
 * The umbrella app itself (`./user-dashboard/umbrella.js`: the widget grid,
 * the library stylesheet, translations and the integration registry) is
 * imported only once the tile exists. Nextcloud calls every registered
 * widget's `load()` on every Dashboard render, also when the user's layout
 * does not hold this tile, so this entry stays a few KB and carries no
 * dependencies: with no static import it never joins the shared vendor
 * chunk (user-dashboard.spec.js guards that).
 */

// The id IntegrationDashboardWidget::getId() returns.
const WIDGET_ID = 'openregister-integrations'
// Older dashboards rendered each widget into an element with that id.
const MOUNT_SELECTOR = '#' + WIDGET_ID

/**
 * Mount the umbrella app into a tile element, once.
 *
 * @spec openspec/changes/archive/2026-07-07-frontend-code-splitting-and-fetch-efficiency/tasks.md
 * @param {HTMLElement} el The tile element
 * @return {void}
 */
function mountInto(el) {
	// Guard against double-mount when NC reloads the widget (e.g. on
	// dashboard layout change).
	if (el.dataset.openregisterMounted === '1') {
		return
	}
	el.dataset.openregisterMounted = '1'

	import(/* webpackChunkName: "user-dashboard-umbrella" */ './user-dashboard/umbrella.js')
		.then(({ mountUmbrellaApp }) => mountUmbrellaApp(el))
		.catch((error) => {
			el.dataset.openregisterMounted = ''
			// No logger import here: any package import would put this entry
			// back on the shared vendor chunk.
			// eslint-disable-next-line no-console
			console.error('OpenRegister: could not load the dashboard integrations', error)
		})
}

/**
 * Hand the dashboard a callback for the tile.
 *
 * Nextcloud's dashboard (`OCA.Dashboard.register`) calls it with the panel
 * element, and only when the user's layout holds the tile; until then the
 * panel shows a spinner. Without this registration the tile never mounted.
 *
 * @spec openspec/changes/archive/2026-07-07-frontend-code-splitting-and-fetch-efficiency/tasks.md
 * @return {boolean} Whether the dashboard API was there
 */
function registerWithDashboard() {
	const dashboard = window.OCA?.Dashboard
	if (typeof dashboard?.register !== 'function') {
		return false
	}
	dashboard.register(WIDGET_ID, (el) => mountInto(el))
	return true
}

/**
 * Register with the dashboard, or mount into an element with the widget id.
 *
 * @return {boolean} Whether there is nothing left to wait for
 */
function mountUmbrella() {
	if (registerWithDashboard()) {
		return true
	}
	const el = document.querySelector(MOUNT_SELECTOR)
	if (el === null) {
		return false
	}
	mountInto(el)
	return true
}

/**
 * Schedule mount: try immediately, then on DOMContentLoaded, then
 * poll briefly (the dashboard API or the tile can appear after this
 * script has run).
 */
function scheduleMount() {
	if (mountUmbrella() === true) {
		return
	}

	let done = false
	const tryMount = () => {
		if (done === false && mountUmbrella() === true) {
			done = true
		}
		return done
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', tryMount, { once: true })
	}

	// Polling window for a late dashboard. Stops as soon as it is
	// found or after ~3 seconds.
	let attempts = 0
	const interval = window.setInterval(() => {
		attempts += 1
		if (tryMount() === true || attempts >= 30) {
			window.clearInterval(interval)
		}
	}, 100)
}

scheduleMount()
