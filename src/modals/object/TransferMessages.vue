<template>
	<div v-if="result.warnings?.length" class="report-section">
		<h4>{{ t('openregister', 'Warnings') }}</h4>
		<ul>
			<li
				v-for="warning in result.warnings"
				:key="warning"
				class="warning-text">
				{{ warning }}
			</li>
		</ul>
	</div>

	<div v-if="result.errors?.length" class="report-section">
		<h4>{{ t('openregister', 'Errors') }}</h4>
		<ul>
			<li v-for="error in result.errors" :key="error" class="error-text">
				{{ error }}
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * The warnings and errors sections of a merge or migration report.
 *
 * @spec exclude Client-side report sections of the object transfer dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
export default {
	name: 'TransferMessages',

	props: {
		/**
		 * @spec exclude The transfer result whose warnings and errors are listed.
		 */
		result: {
			type: Object,
			required: true,
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include report-section;

@include report-section-h4-rules;

.report-section li {
	margin-bottom: 4px;
}

@include error-text-rules;
</style>
