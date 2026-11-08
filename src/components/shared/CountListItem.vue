<template>
	<NcListItem :name="name" :bold="false">
		<template #icon>
			<slot name="icon" />
		</template>
		<template #subname>
			{{ subname }}
		</template>
	</NcListItem>
</template>

<script>
import { NcListItem } from '@nextcloud/vue'

/**
 * One row of a sidebar's top list: an icon, a name, and how often it occurs.
 *
 * @spec exclude Presentational list row; the sidebars own the counts.
 */
export default {
	name: 'CountListItem',
	components: {
		NcListItem,
	},

	props: {
		name: {
			type: String,
			default: '',
		},

		// The count, already translated, e.g. "3 searches".
		subname: {
			type: String,
			default: '',
		},
	},
}
</script>
