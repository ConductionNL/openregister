<template>
	<span
		v-if="external"
		class="managedBadge managedBadge--external">
		<CogOutline :size="16" />
		{{ t('openregister', 'Managed') }}
	</span>
	<span
		v-else-if="local"
		class="managedBadge managedBadge--local">
		<CogOutline :size="16" />
		{{ t('openregister', 'Local') }}
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import {
	isExternalConfiguration,
	isLocalConfiguration,
} from '../../composables/managedConfiguration.js'

/**
 * "Managed" / "Local" badge for a row linked to a configuration.
 * Renders nothing when the row is not managed.
 */
export default {
	name: 'ManagedBadge',
	components: { CogOutline },
	props: {
		/** The configuration managing the row, or null. */
		config: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — display predicate
		 * @return {boolean}
		 */
		external() {
			return isExternalConfiguration(this.config)
		},

		/**
		 * @spec exclude UI plumbing — display predicate
		 * @return {boolean}
		 */
		local() {
			return isLocalConfiguration(this.config)
		},
	},

	methods: { t },
}
</script>

<style lang="scss" scoped>
@use '../../styles/common.scss' as *;

@include managed-badge;
@include managed-badge-external;
@include managed-badge-local;
</style>
