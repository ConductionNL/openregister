<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<ObjectsList />
		</template>
		<template #default>
			<NcEmptyContent v-if="!objectStore.objectItem || navigationStore.selected != 'objects'"
				class="detailContainer"
				name="No object"
				description="No object selected yet">
				<template #icon>
					<DatabaseOutline />
				</template>
				<template #action>
					<NcButton type="primary" @click="objectStore.setObjectItem(null); navigationStore.setModal('editObject')">
						Add Object
					</NcButton>
				</template>
			</NcEmptyContent>
			<ObjectDetails v-if="objectStore.objectItem && navigationStore.selected === 'objects'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import ObjectsList from './ObjectsList.vue'
import ObjectDetails from './ObjectDetails.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'

export default {
	name: 'ObjectsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		ObjectsList,
		ObjectDetails,
		DatabaseOutline,
	},
}
</script>
