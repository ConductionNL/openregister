<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="head">
					<h1 class="h1">
						{{ objectStore.objectItem.id }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton @click="navigationStore.setModal('editObject')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton @click="navigationStore.setDialog('deleteObject')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span><b>Uri:</b> {{ objectStore.objectItem.uri }}</span>
				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>Register:</b>
						<p>{{ objectStore.objectItem.register }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Schema:</b>
						<p>{{ objectStore.objectItem.schema }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Updated:</b>
						<p>{{ objectStore.objectItem.updated }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Created:</b>
						<p>{{ objectStore.objectItem.created }}</p>
					</div>
				</div>

				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Data" active>
							<pre class="json-display"><!-- do not remove this comment
                                -->{{ JSON.stringify(objectStore.objectItem.object, null, 2) }}
                            </pre>
						</BTab>
						<BTab title="Uses">
							<div v-if="objectStore.objectItem.relations && Object.keys(objectStore.objectItem.relations).length > 0">
								<NcListItem v-for="(relation, key) in objectStore.objectItem.relations"
									:key="key"
									:name="key"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<CubeOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ relation }}
									</template>
								</NcListItem>
							</div>
							<div v-else class="tabPanel">
								No relations found
							</div>
						</BTab>
						<BTab title="Used by">
							<div v-if="objectStore.relations.length">
								<NcListItem v-for="(relation, key) in objectStore.relations"
									:key="key"
									:name="relation.id"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<CubeOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ relation.uri }}
									</template>
								</NcListItem>
							</div>
							<div v-else class="tabPanel">
								No relations found
							</div>
						</BTab>
						<BTab title="Files">
							<div v-if="true || !syncs.length" class="tabPanel">
								{{ JSON.stringify(objectStore.objectItem.files, null, 2) }}
							</div>
						</BTab>
						<BTab title="Syncs">
							<div v-if="true || !syncs.length" class="tabPanel">
								No synchronizations found
							</div>
						</BTab>
						<BTab title="Audit Trails">
							<div v-if="objectStore.auditTrails.length">
								<NcListItem v-for="(auditTrail, key) in objectStore.auditTrails"
									:key="key"
									:name="new Date(auditTrail.created).toLocaleString()"
									:bold="false"
									:details="auditTrail.action"
									:counter-number="Object.keys(auditTrail.changed).length"
									:force-display-actions="true">
									<template #icon>
										<TimelineQuestionOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ auditTrail.userName }}
									</template>
									<template #actions>
										<NcActionButton @click="objectStore.setAuditTrailItem(auditTrail); navigationStore.setModal('viewObjectAuditTrail')">
											<template #icon>
												<Eye :size="20" />
											</template>
											View details
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!objectStore.auditTrails.length">
								No audit trails found
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
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'

export default {
	name: 'ObjectDetails',
	components: {
		NcActions,
		NcActionButton,
		NcListItem,
		BTabs,
		BTab,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		TimelineQuestionOutline,
		CubeOutline,
		Eye,
	},
	data() {
		return {
			currentActiveObject: undefined,
			auditTrailLoading: false,
			auditTrails: [],
			relationsLoading: false,
			relations: [],
		}
	},
	mounted() {
		if (objectStore.objectItem?.id) {
			this.currentActiveObject = objectStore.objectItem?.id
			this.getAuditTrails()
			this.getRelations()
		}
	},
	updated() {
		if (this.currentActiveObject !== objectStore.objectItem?.id) {
			this.currentActiveObject = objectStore.objectItem?.id
			this.getAuditTrails()
			this.getRelations()
		}
	},
	methods: {
		getAuditTrails() {
			this.auditTrailLoading = true

			objectStore.getAuditTrails(objectStore.objectItem.id)
				.then(({ data }) => {
					this.auditTrails = data
					this.auditTrailLoading = false
				})
		},
		getRelations() {
			this.relationsLoading = true

			objectStore.getRelations(objectStore.objectItem.id)
				.then(({ data }) => {
					this.relations = data
					this.relationsLoading = false
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
