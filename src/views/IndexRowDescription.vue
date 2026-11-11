<!--
  The muted description line under a row's name in the CnIndexPage list
  tables. The span is the root, so the list view's scoped styles for
  .textDescription and .textEllipsis still reach it.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<span v-if="text" class="textDescription textEllipsis">{{ text }}</span>
</template>

<script>
export default {
	name: 'IndexRowDescription',
	props: {
		/** The row's description; nothing renders without one. */
		text: {
			type: String,
			default: '',
		},
	},
}
</script>
