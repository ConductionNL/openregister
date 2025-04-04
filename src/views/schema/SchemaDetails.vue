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

				<div class="detailGrid">
					<div class="gridContent ">
						<b>Id:</b>
						<p>{{ schemaStore.schemaItem.id }}</p>
					</div>
					<div class="gridContent ">
						<b>Uuid:</b>
						<p>{{ schemaStore.schemaItem.uuid }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Version:</b>
						<p>{{ schemaStore.schemaItem.version }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Slug:</b>
						<p>{{ schemaStore.schemaItem.slug }}</p>
					</div>
				</div>
				<!-- Add more schema-specific details here -->
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Properties" active>
							<div v-if="Object.keys(schemaStore.schemaItem.properties).length">
								<NcListItem v-for="(property, key) in schemaStore.schemaItem.properties"
									:key="key"
									:name="key"
									:active="schemaStore.schemaPropertyKey === key"
									:bold="false"
									:force-display-actions="true"
									@click="setActiveProperty(key)">
									<template #icon>
										<CircleOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ property.description }}
									</template>
									<template #actions>
										<NcActionButton :aria-label="`Edit '${key}'`"
											@click="schemaStore.setSchemaPropertyKey(key); navigationStore.setModal('editSchemaProperty')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
										<NcActionButton :aria-label="`Delete '${key}'`"
											@click="schemaStore.setSchemaPropertyKey(key); navigationStore.setModal('deleteSchemaProperty')">
											<template #icon>
												<TrashCanOutline :size="20" />
											</template>
											Delete
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!Object.keys(schemaStore.schemaItem.properties).length" class="tabPanel">
								No properties found
							</div>
						</BTab>
						<BTab title="Logs">
							<div v-if="false && logs.length > 0">
								<NcListItem v-for="(log, key) in logs"
									:key="key"
									:name="log.title"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<PostOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ log.description }}
									</template>
								</NcListItem>
							</div>
							<div v-if="true || logs.length === 0" class="tabPanel">
								No logs found
							</div>
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcListItem } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'

export default {
	name: 'SchemaDetails',
	components: {
		NcActions,
		NcActionButton,
		NcListItem,
		BTabs,
		BTab,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		PlusCircleOutline,
		CircleOutline,
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
