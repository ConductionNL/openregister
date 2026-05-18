<script>
import { NcAppContent } from '@nextcloud/vue'
import { CnIntegrationTab, useIntegrationRegistry } from '@conduction/nextcloud-vue'
import { BTabs, BTab } from 'bootstrap-vue'
import { computed, ref } from 'vue'

/**
 * IntegrationsView — standalone surface that renders the integration
 * registry against a single object identified by URL params. Designed
 * for the per-leaf screenshot harness: no dependency on ObjectsList,
 * objectStore.objectItem, or the legacy sub-resource plugins, so the
 * Vue render won't be aborted mid-template by an unrelated race in
 * filesPlugin / auditTrailsPlugin / relationsPlugin.
 *
 * Route: /integrations/:register/:schema/:objectId
 */
export default {
	name: 'IntegrationsView',

	components: {
		NcAppContent,
		CnIntegrationTab,
		BTabs,
		BTab,
	},

	setup() {
		const { integrations } = useIntegrationRegistry()
		const providers = computed(() => (integrations.value || []))
		const ready = ref(true)
		return { providers, ready }
	},

	computed: {
		register() {
			return String(this.$route.params.register || '')
		},
		schema() {
			return String(this.$route.params.schema || '')
		},
		objectId() {
			return String(this.$route.params.objectId || '')
		},
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

				<BTabs content-class="mt-3" pills>
					<BTab v-for="provider in providers"
						:key="provider.id"
						:title="provider.label || provider.id"
						:title-attr="`${provider.id} (${provider.group || 'integration'})`">
						<CnIntegrationTab
							:integration-id="provider.id"
							:register="register"
							:schema="schema"
							:object-id="objectId" />
					</BTab>
				</BTabs>
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
/* Bootstrap-vue pills layout — without these the tabs render as a plain
   vertical link list because bootstrap CSS isn't loaded in this stack. */
.integrations-view :deep(.nav-pills) {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}
.integrations-view :deep(.nav-pills .nav-item) {
	margin: 0;
}
.integrations-view :deep(.nav-pills .nav-link) {
	display: inline-block;
	padding: 6px 12px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	text-decoration: none;
	font-size: 13px;
	border: 1px solid transparent;
	cursor: pointer;
}
.integrations-view :deep(.nav-pills .nav-link:hover) {
	background: var(--color-background-dark);
}
.integrations-view :deep(.nav-pills .nav-link.active) {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}
.integrations-view :deep(.tab-content) {
	min-height: 320px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
}
.integrations-view :deep(.tab-content > .tab-pane:not(.active)) {
	display: none;
}
</style>
