<template>
	<RadioFilterSidebar
		rootClass="webhooks-sidebar"
		:title="t('openregister', 'Search Webhooks')"
		:searchLabel="t('openregister', 'Search by name or URL')"
		:search="search"
		:groups="groups"
		:values="{ enabled }"
		@update:search="$emit('update:search', $event)"
		@update:filter="(key, value) => $emit(`update:${key}`, value)" />
</template>

<script>
import { t } from '@nextcloud/l10n'
import RadioFilterSidebar from './shared/RadioFilterSidebar.vue'
import { radioFilterSidebarHost } from './shared/radioFilterSidebarHost.js'

/**
 * The options of the enabled-status filter: all, enabled or disabled webhooks.
 *
 * @return {Array<{id: string, value: boolean|null, label: string}>} The options
 */
function enabledOptions() {
	return [
		{
			id: 'all',
			value: null,
			label: t('openregister', 'All Webhooks'),
		},
		{
			id: 'enabled',
			value: true,
			label: t('openregister', 'Enabled'),
		},
		{
			id: 'disabled',
			value: false,
			label: t('openregister', 'Disabled'),
		},
	]
}

export default {
	name: 'WebhooksSidebar',

	components: {
		RadioFilterSidebar,
	},

	mixins: [radioFilterSidebarHost],

	props: {
		/**
		 * @spec exclude two-way-bound enabled-status filter prop, UI plumbing
		 */
		enabled: {
			type: Boolean,
			default: null,
		},
	},

	emits: ['update:search', 'update:enabled'],

	computed: {
		/**
		 * The enabled-status radio group.
		 *
		 * @return {Array<object>} The groups
		 */
		groups() {
			return [
				{
					key: 'enabled',
					heading: t('openregister', 'Status'),
					options: enabledOptions(),
				},
			]
		},
	},
}
</script>
