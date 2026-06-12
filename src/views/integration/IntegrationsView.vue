<script>
import { NcAppContent } from '@nextcloud/vue'
import { CnIntegrationWidget, useIntegrationRegistry } from '@conduction/nextcloud-vue'
import { computed, ref } from 'vue'

/**
 * IntegrationsView — standalone surface that renders the integration
 * registry against a single object identified by URL params. Designed
 * for the per-leaf screenshot harness: no dependency on ObjectsList,
 * objectStore.objectItem, or the legacy sub-resource plugins, so the
 * Vue render won't be aborted mid-template by an unrelated race in
 * filesPlugin / auditTrailsPlugin / relationsPlugin.
 *
 * Renders the tabbed CnIntegrationWidget (nc-vue, ADR-019/024) — the
 * same app-faithful surface OR's object-detail page now uses — so the
 * screenshot harness and the live detail page stay visually in sync.
 * Supersedes the previous hand-rolled BTabs pills + `provider.tab ||
 * CnIntegrationTab` dispatch, which produced a flat generic surface
 * that erased each integrated app's identity.
 *
 * Route: /integrations/:register/:schema/:objectId
 */
export default {
	name: 'IntegrationsView',

	components: {
		NcAppContent,
		CnIntegrationWidget,
	},

	/**
	 * Composition entry wiring the integration registry into the view.
	 *
	 * @spec exclude UI plumbing — registry wiring for the screenshot harness; integration contract owned by ADR-019 / generic-integrations.
	 * @return {object} exposed refs (providers, ready)
	 */
	setup() {
		const { integrations } = useIntegrationRegistry()
		// Used ONLY by the render guard / header count below;
		// CnIntegrationWidget reads the same singleton itself.
		const providers = computed(() => (integrations.value || []))
		const ready = ref(true)
		return { providers, ready }
	},

	computed: {
		/**
		 * Register slug from the route.
		 *
		 * @spec exclude UI plumbing — route-param accessor, no observable contract.
		 * @return {string}
		 */
		register() {
			return String(this.$route.params.register || '')
		},
		/**
		 * Schema slug from the route.
		 *
		 * @spec exclude UI plumbing — route-param accessor, no observable contract.
		 * @return {string}
		 */
		schema() {
			return String(this.$route.params.schema || '')
		},
		/**
		 * Object id from the route.
		 *
		 * @spec exclude UI plumbing — route-param accessor, no observable contract.
		 * @return {string}
		 */
		objectId() {
			return String(this.$route.params.objectId || '')
		},
		/**
		 * Whether all params + providers are present to render tabs.
		 *
		 * @spec exclude UI plumbing — render-guard predicate, no observable contract.
		 * @return {boolean}
		 */
		ok() {
			return this.register && this.schema && this.objectId && this.providers.length > 0
		},
	},
}
</script>

<template>
	<NcAppContent>
		<div class="integrations-view">
			<div v-if="!ok" class="integrations-view__empty">
				<h2>Integrations view</h2>
				<p>
					URL: <code>/integrations/:register/:schema/:objectId</code>
				</p>
				<p v-if="!providers.length">
					<em>Waiting for the integration registry to publish providers…</em>
				</p>
				<p v-else>
					Provide all three params in the URL to load the per-leaf tabs.
				</p>
			</div>

			<div v-else>
				<h1 class="integrations-view__title">
					Integrations
				</h1>
				<p class="integrations-view__subtitle">
					Register <code>{{ register }}</code> &middot;
					Schema <code>{{ schema }}</code> &middot;
					Object <code>{{ objectId }}</code> &middot;
					{{ providers.length }} providers
				</p>

				<!--
					Tabbed CnIntegrationWidget — one app-faithful tab per
					registered IntegrationProvider, app icon + brand accent on
					the active tab, per-leaf content (provider.tab) in the
					panel, and an NcEmptyContent set-up state for unavailable /
					unconfigured integrations (Phase J-B availability).
				-->
				<CnIntegrationWidget
					:register="register"
					:schema="schema"
					:object-id="objectId"
					surface="detail-page" />
			</div>
		</div>
	</NcAppContent>
</template>

<style scoped>
.integrations-view {
	padding: 24px 32px;
	max-width: 1400px;
}
.integrations-view__title {
	font-size: 28px;
	font-weight: 700;
	margin: 0 0 8px;
	color: var(--color-main-text);
}
.integrations-view__subtitle {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0 0 16px;
}
.integrations-view__empty {
	padding: 48px 32px;
	color: var(--color-text-maxcontrast);
}
</style>
