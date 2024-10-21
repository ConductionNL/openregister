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
				<span>{{ objectStore.objectItem.uuid }}</span>
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
							<pre class="json-display">
								{{ JSON.stringify(objectStore.objectItem.object, null, 2) }}
							</pre>
						</BTab>
						<BTab title="Syncs">
							<div v-if="true || !syncs.length" class="tabPanel">
								No synchronizations found
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
								
								<table width="100%">							
									<tr>
										<th><b>Tijdstip</b></th>
										<th><b>Gebruiker</b></th>
										<th><b>Actie</b></th>
										<th><b>Details</b></th>
									</tr>
									<tr v-for="(auditTrail, index) in auditTrails" :key="index">
										<td>{{ new Date(auditTrail.created).toLocaleString() }}</td>
										<td>{{ auditTrail.userName }}</td>
										<td>{{ auditTrail.action }}</td>
										<td>
											<NcButton @click="() => { navigationStore.setDialog('viewLog'); objectStore.setAuditTrailItem(auditTrail); }">
												<template #icon>
													<TimelineQuestionOutline :size="20" />
												</template>
												Bekijk details
											</NcButton>
										</td>
									</tr>
								</table>
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
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
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
	},
	data() {
		return {
			auditTrailLoading: false,
			auditTrails: [],
		}
	},
	mounted() {
		this.getAuditTrails();
	},
	methods: {		
		getAuditTrails() {
			this.syncLoading = true
			fetch(
				`/index.php/apps/openregister/api/audit-trails/${objectStore.objectItem.id}`,
				{
					method: 'GET',
				},
			)
				.then(
					(response) => {
						response.json().then(
							(data) => {
								this.auditTrails = data
								console.log(this.auditTrails)
								this.auditTrailLoading = false
							},
						)
					},
				)
				.catch((err) => {
					this.error = err
					this.auditTrailLoading = false
				})
		},
	}
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
