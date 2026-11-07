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

export default {
	name: 'WebhooksSidebar',

	components: {
		RadioFilterSidebar,
	},

	props: {
		/**
		 * @spec exclude two-way-bound search prop, UI plumbing
		 */
		search: {
			type: String,
			default: '',
		},

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
					options: [
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
					],
				},
			]
		},
	},

	methods: {
		t,
	},
}
</script>
