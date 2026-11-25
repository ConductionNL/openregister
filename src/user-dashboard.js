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
 * dependencies (webpack.config.js keeps it out of the shared vendor chunk).
 */

// Nextcloud's dashboard renders each widget into an element with id
// `<widget-id>` — see apps/dashboard/lib/Controller/DashboardController.
// Our IntegrationDashboardWidget::getId() returns 'openregister-integrations'.
const MOUNT_SELECTOR = '#openregister-integrations'

/**
 * Mount the umbrella app into the tile once it exists.
 *
 * Returns true once the tile was found (the app then mounts when its chunk
 * has loaded), false while the tile is not there yet.
 *
 * @spec openspec/changes/archive/2026-07-07-frontend-code-splitting-and-fetch-efficiency/tasks.md
 * @return {boolean} Whether the tile was found
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

	import(/* webpackChunkName: "user-dashboard-umbrella" */ './user-dashboard/umbrella.js')
		.then(({ mountUmbrellaApp }) => mountUmbrellaApp(el))
		.catch((error) => {
			el.dataset.openregisterMounted = ''
			// No logger import here: any package import would put this entry
			// back on the shared vendor chunk.
			// eslint-disable-next-line no-console
			console.error('OpenRegister: could not load the dashboard integrations', error)
		})

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
