<!--
  The logs tab panel of a view modal, which has no logs to show.

  @spec exclude Presentational empty state shared by the view modals.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<TabEmptyContent
		:name="t('openregister', 'No logs found')"
		:description="description">
		<PostOutline :size="64" />
	</TabEmptyContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'
import TabEmptyContent from './TabEmptyContent.vue'

/**
 * The empty logs tab of the source and configuration view modals.
 *
 * @spec exclude Presentational empty state shared by the view modals.
 */
export default {
	name: 'EmptyLogsTab',

	components: {
		PostOutline,
		TabEmptyContent,
	},

	props: {
		/** What the modal shows: 'source' or 'configuration'. */
		subject: {
			type: String,
			required: true,
			validator: (value) => ['source', 'configuration'].includes(value),
		},
	},

	computed: {
		/**
		 * The explanation for the modal's subject.
		 *
		 * @return {string} The translated explanation
		 */
		description() {
			if (this.subject === 'source') {
				return t('openregister', 'No logs are available for this source.')
			}
			return t('openregister', 'No logs are available for this configuration.')
		},
	},

	methods: { t },
}
</script>
