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
import { radioFilterSidebarHost } from './shared/radioFilterSidebarHost.js'

export default {
	name: 'FilesSidebar',

	components: {
		RadioFilterSidebar,
	},

	mixins: [radioFilterSidebarHost],

	props: {
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
			return [statusGroup(), riskLevelGroup()]
		},
	},
}

/**
 * One radio option: its id is the value, or `all` for the unfiltered choice.
 *
 * @param {string|null} value The filter value, null for no filter
 * @param {string} label The translated label
 * @return {object} The option
 */
function radioOption(value, label) {
	return { id: value ?? 'all', value, label }
}

/**
 * The extraction status radio group.
 *
 * @return {object} The group
 */
function statusGroup() {
	return {
		key: 'status',
		heading: t('openregister', 'Extraction Status'),
		options: [
			radioOption(null, t('openregister', 'All Files')),
			radioOption('pending', t('openregister', 'Pending')),
			radioOption('processing', t('openregister', 'Processing')),
			radioOption('completed', t('openregister', 'Completed')),
			radioOption('failed', t('openregister', 'Failed')),
		],
	}
}

/**
 * The risk level radio group.
 *
 * @return {object} The group
 */
function riskLevelGroup() {
	return {
		key: 'riskLevel',
		heading: t('openregister', 'Risk Level'),
		options: [
			radioOption(null, t('openregister', 'All Levels')),
			radioOption('none', t('openregister', 'None')),
			radioOption('low', t('openregister', 'Low')),
			radioOption('medium', t('openregister', 'Medium')),
			radioOption('high', t('openregister', 'High')),
			radioOption('very_high', t('openregister', 'Very High')),
		],
	}
}
</script>
