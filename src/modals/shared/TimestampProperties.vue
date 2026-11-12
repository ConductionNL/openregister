<!--
  The created and updated rows of a view modal's property list, each shown
  only when the item has that date.

  @spec exclude Presentational rows shared by the view modals.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div v-if="created" class="propertyItem">
		<strong>{{ t('openregister', 'Created') }}:</strong>
		<span>{{ new Date(created).toLocaleString() }}</span>
	</div>
	<div v-if="updated" class="propertyItem">
		<strong>{{ t('openregister', 'Updated') }}:</strong>
		<span>{{ new Date(updated).toLocaleString() }}</span>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * The created and updated rows of a view modal's property list.
 *
 * @spec exclude Presentational rows shared by the view modals.
 */
export default {
	name: 'TimestampProperties',

	props: {
		created: { type: [String, Number], default: null },
		updated: { type: [String, Number], default: null },
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include property-item-rules;
</style>
