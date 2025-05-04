<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="head">
					<h1 class="h1">
						{{ schemaStore.schemaItem.title }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton @click="navigationStore.setModal('editSchema')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton @click="schemaStore.setSchemaPropertyKey(null); navigationStore.setModal('editSchemaProperty')">
							<template #icon>
								<PlusCircleOutline />
							</template>
							Add Property
						</NcActionButton>
						<NcActionButton @click="navigationStore.setModal('uploadSchema')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Upload
						</NcActionButton>
						<NcActionButton @click="schemaStore.downloadSchema(schemaStore.schemaItem)">
							<template #icon>
								<Download :size="20" />
							</template>
							Download
						</NcActionButton>
						<NcActionButton @click="navigationStore.setDialog('deleteSchema')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ schemaStore.schemaItem.description }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'

export default {
	name: 'SchemaDetails',
	components: {
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		PlusCircleOutline,
		Download,
		Upload,
	},
	methods: {
		setActiveProperty(key) {
			if (JSON.stringify(schemaStore.schemaPropertyKey) === JSON.stringify(key)) {
				schemaStore.setSchemaPropertyKey(null)
			} else { schemaStore.setSchemaPropertyKey(key) }
		},
	},
}
</script>

<style>
/* Styles remain the same */
</style>
