<!--
  The empty state of one tab panel of a view modal: a centred
  NcEmptyContent whose icon is the default slot.

  @spec exclude Presentational empty state shared by the view modals.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="emptyTabContent">
		<NcEmptyContent :name="name" :description="description">
			<template #icon>
				<slot />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'

/**
 * The empty state of a view modal's tab panel.
 *
 * @spec exclude Presentational empty state shared by the view modals.
 */
export default {
	name: 'TabEmptyContent',

	components: {
		NcEmptyContent,
	},

	props: {
		/** The heading, already translated. */
		name: { type: String, required: true },
		/** The explanation below the heading, already translated. */
		description: { type: String, required: true },
	},
}
</script>
