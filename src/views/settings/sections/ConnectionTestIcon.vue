<!--
  The icon of a "Test connection" button: a spinner while the test runs, a
  check or an alert once it answered, a refresh arrow before it ran.

  @spec exclude Presentational icon of the file settings connection tests.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcLoadingIcon v-if="testingFirst && testing" :size="20" />
	<CheckIcon v-else-if="result === 'success'" :size="20" />
	<AlertCircleIcon v-else-if="result === 'error'" :size="20" />
	<NcLoadingIcon v-else-if="testing" :size="20" />
	<RefreshIcon v-else :size="20" />
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'

/**
 * The icon of a connection test button.
 *
 * @spec exclude Presentational icon of the file settings connection tests.
 */
export default {
	name: 'ConnectionTestIcon',
	components: { NcLoadingIcon, AlertCircleIcon, CheckIcon, RefreshIcon },

	props: {
		/** The last answer: 'success', 'error', or anything else for none. */
		result: { type: String, default: null },
		/** Whether the test is running now. */
		testing: { type: Boolean, default: false },
		/** Show the spinner even over an earlier answer. */
		testingFirst: { type: Boolean, default: false },
	},
}
</script>
