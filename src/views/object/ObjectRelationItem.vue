<template>
	<NcListItem :name="name" :bold="false" :forceDisplayActions="true">
		<template #icon>
			<CubeOutline disableMenu :size="44" />
		</template>
		<template #subname>
			{{ subname }}
		</template>
	</NcListItem>
</template>

<script>
import { NcListItem } from '@nextcloud/vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'

/**
 * One row of the Uses / Used by tabs of the object detail view.
 *
 * @spec exclude UI plumbing — presentational list row of an object relation.
 */
export default {
	name: 'ObjectRelationItem',
	components: { NcListItem, CubeOutline },

	props: {
		/** The related object's title. */
		name: {
			type: String,
			default: '',
		},

		/** The relation label shown under the title. */
		subname: {
			type: String,
			default: '',
		},
	},
}
</script>
