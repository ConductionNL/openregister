<script setup>
import { objectStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<ObjectsList />
		</template>
		<template #default>
			<NcEmptyContent v-if="!objectStore.objectItem || $route.path !== '/objects'"
				class="detailContainer"
				name="No object"
				description="No object selected yet">
				<template #icon>
					<DatabaseOutline />
				</template>
				<template #action>
					<NcButton type="primary" @click="addObject">
						Add Object
					</NcButton>
				</template>
			</NcEmptyContent>
			<ObjectDetails v-if="objectStore.objectItem && $route.path === '/objects'" />
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
	methods: {
		addObject() {
			// Clear any existing object and open the add object modal
			objectStore.setObjectItem(null)
			// Ensure register and schema are set for new object creation
			if (registerStore.registerItem) {
				registerStore.setRegisterItem(registerStore.registerItem)
			}
			if (schemaStore.schemaItem) {
				schemaStore.setSchemaItem(schemaStore.schemaItem)
			}
			navigationStore.setModal('viewObject')
		},
	},
}
</script>
