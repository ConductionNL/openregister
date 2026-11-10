<template>
	<span :class="active ? 'status-active' : 'status-inactive'">
		{{ active ? t('openregister', 'Active') : t('openregister', 'Inactive') }}
	</span>
</template>

<script>
/**
 * An application's Active / Inactive label. The status-active and
 * status-inactive rules stay in the view that shows it.
 *
 * @spec exclude UI plumbing — shared status label markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ActiveStatus',
	props: {
		/** Whether the application is active. */
		active: {
			type: Boolean,
			default: false,
		},
	},

	methods: {
		t,
	},
}
</script>
