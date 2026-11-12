<template>
	<CountListItem :forceDisplayActions="true">
		<template #icon>
			<CubeOutline disableMenu :size="44" />
		</template>
	</CountListItem>
</template>

<script>
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import CountListItem from '../../components/shared/CountListItem.vue'

/**
 * One row of the Uses / Used by tabs of the object detail view: a
 * CountListItem with a cube icon whose actions always show. It takes
 * CountListItem's props, `name` (the related object's title) and `subname`
 * (the relation label).
 *
 * @spec exclude UI plumbing — presentational list row of an object relation.
 */
export default {
	name: 'ObjectRelationItem',
	components: { CountListItem, CubeOutline },
}
</script>
