<template>
	<RadioFilterSidebar
		rootClass="files-sidebar"
		:title="t('openregister', 'Search Files')"
		:searchLabel="t('openregister', 'Search by file name or path')"
		:search="search"
		:groups="groups"
		:values="{ status, riskLevel }"
		@update:search="$emit('update:search', $event)"
		@update:filter="(key, value) => $emit(`update:${key}`, value)" />
</template>

<script>
import { t } from '@nextcloud/l10n'
import RadioFilterSidebar from './shared/RadioFilterSidebar.vue'

export default {
	name: 'FilesSidebar',

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
		 * @spec exclude two-way-bound extraction-status filter prop, UI plumbing
		 */
		status: {
			type: String,
			default: null,
		},

		/**
		 * @spec exclude two-way-bound risk-level filter prop, UI plumbing
		 */
		riskLevel: {
			type: String,
			default: null,
		},
	},

	emits: ['update:search', 'update:status', 'update:riskLevel'],

	computed: {
		/**
		 * The extraction status and risk level radio groups.
		 *
		 * @return {Array<object>} The groups
		 */
		groups() {
			return [
				{
					key: 'status',
					heading: t('openregister', 'Extraction Status'),
					options: [
						{
							id: 'all',
							value: null,
							label: t('openregister', 'All Files'),
						},
						{
							id: 'pending',
							value: 'pending',
							label: t('openregister', 'Pending'),
						},
						{
							id: 'processing',
							value: 'processing',
							label: t('openregister', 'Processing'),
						},
						{
							id: 'completed',
							value: 'completed',
							label: t('openregister', 'Completed'),
						},
						{
							id: 'failed',
							value: 'failed',
							label: t('openregister', 'Failed'),
						},
					],
				},
				{
					key: 'riskLevel',
					heading: t('openregister', 'Risk Level'),
					options: [
						{
							id: 'all',
							value: null,
							label: t('openregister', 'All Levels'),
						},
						{
							id: 'none',
							value: 'none',
							label: t('openregister', 'None'),
						},
						{ id: 'low', value: 'low', label: t('openregister', 'Low') },
						{
							id: 'medium',
							value: 'medium',
							label: t('openregister', 'Medium'),
						},
						{
							id: 'high',
							value: 'high',
							label: t('openregister', 'High'),
						},
						{
							id: 'very_high',
							value: 'very_high',
							label: t('openregister', 'Very High'),
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
