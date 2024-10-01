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
					<div class="gridContent gridFullWidth">
						<b>Version:</b>
						<p>{{ schemaStore.schemaItem.version }}</p>
					</div>
				</div>
				<!-- Add more schema-specific details here -->
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
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
							<div v-if="true || logs.length === 0">
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
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'

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
	},
}
</script>

<style>
/* Styles remain the same */
</style>
