/**
 * Maps integration registration.
 *
 * Registers the NC Maps geolocation integration with the pluggable integration
 * registry (ADR-019). The tab and widget components (CnMapTab, CnMapCard) are
 * provided by @conduction/nextcloud-vue; this file wires them into the registry.
 *
 * @package OpenRegister
 *
 * @spec openspec/changes/integration-maps/tasks.md#task-7
 */

/**
 * Register the Maps integration.
 *
 * Called once from the app entry point after the integration registry is
 * initialised. The registry resolves the `tab` and `widget` components lazily
 * so they are not bundled into the main chunk when Maps is not installed.
 *
 * @param {object} registry The OCA.OpenRegister.integrations registry instance.
 * @return {void}
 */
export function registerMapsIntegration(registry) {
	registry.register({
		id: 'maps',
		label: 'Location',
		icon: 'MapMarker',
		group: 'docs',
		requiredApp: 'maps',
		referenceType: 'maps',
		storageStrategy: 'link-table',

		// Tab component — loaded from @conduction/nextcloud-vue.
		tab: () => import(/* webpackChunkName: "integration-maps-tab" */ '@conduction/nextcloud-vue/src/integrations/CnMapTab.vue'),

		// Widget component — loaded from @conduction/nextcloud-vue.
		widget: () => import(/* webpackChunkName: "integration-maps-widget" */ '@conduction/nextcloud-vue/src/integrations/CnMapCard.vue'),
	})
}
