<script setup>
import { registerStore, navigationStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="head">
					<h1 class="h1">
						{{ registerStore.registerItem.title }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton @click="navigationStore.setModal('editRegister')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton @click="navigationStore.setModal('exportRegister')">
							<template #icon>
								<Export :size="20" />
							</template>
							Export
						</NcActionButton>
						<NcActionButton @click="navigationStore.setModal('uploadRegister')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Upload
						</NcActionButton>
						<NcActionButton @click="navigationStore.setDialog('deleteRegister')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ registerStore.registerItem.description }}</span>

				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>Table Prefix:</b>
						<p>{{ registerStore.registerItem.tablePrefix }}</p>
					</div>
				</div>
				<!-- Add more register-specific details here -->
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Schemas" active>
							<div v-if="filterSchemas.length > 0">
								<NcListItem v-for="(schema) in filterSchemas"
									:key="schema.id"
									:name="schema.title"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<FileTreeOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ schema.description }}
									</template>
									<template #actions>
										<NcActionButton :aria-label="`Go to schema '${schema.title}'`"
											@click="schemaStore.setSchemaItem(schema); navigationStore.setSelected('schemas')">
											<template #icon>
												<EyeArrowRight :size="20" />
											</template>
											View
										</NcActionButton>
										<NcActionButton :aria-label="`Edit '${schema.title}'`"
											@click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!filterSchemas.length" class="tabPanel">
								No schemas found
							</div>
						</BTab>
						<BTab title="Logs">
							<div v-if="false && logs.length">
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
							<div v-if="true || !logs.length" class="tabPanel">
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
import {
	NcActions,
	NcActionButton,
	NcListItem,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import EyeArrowRight from 'vue-material-design-icons/EyeArrowRight.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Export from 'vue-material-design-icons/Export.vue'

export default {
	name: 'RegisterDetails',
	components: {
		NcActions,
		NcActionButton,
		NcListItem,
		BTabs,
		BTab,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		FileTreeOutline,
		EyeArrowRight,
		Upload,
		Export,
	},
	data() {
		return {
			schemasLoading: false,
		}
	},
	computed: {
		filterSchemas() {
			return schemaStore.schemaList.filter((schema) => {
				return registerStore.registerItem.schemas.map(String).includes(schema.id.toString())
			})
		},
	},
	mounted() {
		this.fetchSchemas()
	},
	methods: {
		fetchSchemas() {
			this.schemasLoading = true
			schemaStore.refreshSchemaList()
				.then(() => {
					this.schemasLoading = false
				})
		},
	},
}
</script>

<style>
.head{
	display: flex;
	justify-content: space-between;
}

h4 {
  font-weight: bold
}

.h1 {
  display: block !important;
  font-size: 2em !important;
  margin-block-start: 0.67em !important;
  margin-block-end: 0.67em !important;
  margin-inline-start: 0px !important;
  margin-inline-end: 0px !important;
  font-weight: bold !important;
  unicode-bidi: isolate !important;
}

.grid {
  display: grid;
  grid-gap: 24px;
  grid-template-columns: 1fr 1fr;
  margin-block-start: var(--OR-margin-50);
  margin-block-end: var(--OR-margin-50);
}

.gridContent {
  display: flex;
  gap: 25px;
}
</style>
