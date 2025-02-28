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
							<LockOutline v-if="objectStore.objectItem.locked" :size="20" />
							<DotsHorizontal v-else :size="20" />
						</template>
						<NcActionButton @click="navigationStore.setModal('editObject')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton v-if="!objectStore.objectItem.locked" @click="navigationStore.setModal('lockObject')">
							<template #icon>
								<LockOutline :size="20" />
							</template>
							Lock
						</NcActionButton>
						<NcActionButton v-if="objectStore.objectItem.locked" @click="objectStore.unlockObject(objectStore.objectItem.id)">
							<template #icon>
								<LockOpenOutline :size="20" />
							</template>
							Unlock
						</NcActionButton>
						<NcActionButton @click="navigationStore.setDialog('deleteObject')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
						<NcActionButton
							:disabled="!objectStore.objectItem.folder"
							@click="openFolder(objectStore.objectItem.folder)">
							<template #icon>
								<FolderOutline :size="20" />
							</template>
							Open Folder
						</NcActionButton>
					</NcActions>
				</div>

				<NcNoteCard
					v-if="objectStore.objectItem.locked"
					type="warning"
					:show-close="false">
					<template #icon>
						<LockOutline :size="20" />
					</template>
					This object is locked by {{ objectStore.objectItem.locked.user }}
					{{ objectStore.objectItem.locked.process ? `for process "${objectStore.objectItem.locked.process}"` : '' }}
					until {{ new Date(objectStore.objectItem.locked.expiration).toLocaleString() }}
				</NcNoteCard>

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
						<b>Folder:</b>
						<p>{{ objectStore.objectItem.folder || '-' }}</p>
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
								<BPagination v-if="!relationsLoading && objectStore.relations.total > pagination.relations.limit"
									v-model="pagination.relations.currentPage"
									class="tabPagination"
									:total-rows="objectStore.relations.total"
									:per-page="pagination.relations.limit" />
							</div>
							<div v-else class="tabPanel">
								No relations found
							</div>
						</BTab>
						<BTab title="Files">
							<NcButton @click="openFolder(objectStore.objectItem.folder)">
								<template #icon>
									<FolderOutline :size="20" />
								</template>
								Open folder
							</NcButton>

							<div v-if="objectStore.files.results?.length > 0">
								<NcListItem v-for="(attachment, i) in objectStore.files.results"
									:key="`${attachment}${i}`"
									:name="attachment.name ?? attachment?.title"
									:bold="false"
									:active="activeAttachment === attachment.id"
									:force-display-actions="true"
									@click="() => {
										if (activeAttachment === attachment.id) activeAttachment = null
										else activeAttachment = attachment.id
									}">
									<template #icon>
										<ExclamationThick v-if="!attachment.accessUrl || !attachment.downloadUrl" class="warningIcon" :size="44" />
										<FileOutline v-else
											class="publishedIcon"
											disable-menu
											:size="44" />
									</template>

									<template #details>
										<span>{{ formatFileSize(attachment?.size) }}</span>
									</template>
									<template #indicator>
										<div class="fileLabelsContainer">
											<NcCounterBubble v-for="label of attachment.labels" :key="label">
												{{ label }}
											</NcCounterBubble>
										</div>
									</template>
									<template #subname>
										{{ attachment?.type || 'Geen type' }}
									</template>
									<template #actions>
										<NcActionButton @click="openFile(attachment)">
											<template #icon>
												<OpenInNew :size="20" />
											</template>
											Bekijk bestand
										</NcActionButton>
									</template>
								</NcListItem>

								<BPagination v-if="!fileLoading && objectStore.files.total > pagination.files.limit"
									v-model="pagination.files.currentPage"
									class="tabPagination"
									:total-rows="objectStore.files.total"
									:per-page="pagination.files.limit" />
							</div>

							<div v-if="objectStore.files.results?.length === 0">
								Nog geen bijlage toegevoegd
							</div>

							<div
								v-if="objectStore.files.results?.length !== 0 && !objectStore.files.results?.length > 0 && fileLoading">
								<NcLoadingIcon :size="64"
									class="loadingIcon"
									appearance="dark"
									name="Bijlagen aan het laden" />
							</div>
						</BTab>
						<BTab title="Syncs">
							<div v-if="true || !syncs.length" class="tabPanel">
								No synchronizations found
							</div>
						</BTab>
						<BTab title="Audit Trails">
							<div v-if="objectStore.auditTrails.results?.length">
								<NcListItem v-for="(auditTrail, key) in objectStore.auditTrails.results"
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
								<BPagination v-if="!auditTrailLoading && objectStore.auditTrails.total > pagination.auditTrails.limit"
									v-model="pagination.auditTrails.currentPage"
									class="tabPagination"
									:total-rows="objectStore.auditTrails.total"
									:per-page="pagination.auditTrails.limit" />
							</div>
							<div v-if="!objectStore.auditTrails.results?.length">
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
	NcNoteCard,
	NcButton,
	NcCounterBubble,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { BTabs, BTab, BPagination } from 'bootstrap-vue'

import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import LockOpenOutline from 'vue-material-design-icons/LockOpenOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'ObjectDetails',
	components: {
		NcActions,
		NcActionButton,
		NcListItem,
		NcNoteCard,
		NcButton,
		NcCounterBubble,
		BTabs,
		BTab,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		TimelineQuestionOutline,
		CubeOutline,
		Eye,
		LockOutline,
		LockOpenOutline,
		FolderOutline,
		FileOutline,
	},
	data() {
		return {
			currentActiveObject: undefined,
			auditTrailLoading: false,
			auditTrails: [],
			relationsLoading: false,
			relations: [],
			activeAttachment: null,
			fileLoading: false,
			pagination: {
				files: {
					limit: 200,
					currentPage: objectStore.files.page || 1,
					totalPages: objectStore.files.total || 1,
				},
				auditTrails: {
					limit: 200,
					currentPage: objectStore.auditTrails.page || 1,
					totalPages: objectStore.auditTrails.total || 1,
				},
				relations: {
					limit: 200,
					currentPage: objectStore.relations.page || 1,
					totalPages: objectStore.relations.total || 1,
				},
			},
		}
	},
	watch: {
		'pagination.files.currentPage': {
			handler() {
				this.getFiles()
			},
		},
		'pagination.auditTrails.currentPage': {
			handler() {
				this.getAuditTrails()
			},
		},
		'pagination.relations.currentPage': {
			handler() {
				this.getRelations()
			},
		},
	},
	mounted() {
		if (objectStore.objectItem?.id) {
			this.currentActiveObject = objectStore.objectItem?.id
			this.getFiles()
			this.getAuditTrails()
			this.getRelations()
		}
	},
	updated() {
		if (this.currentActiveObject !== objectStore.objectItem?.id) {
			this.currentActiveObject = objectStore.objectItem?.id
			this.getFiles()
			this.getAuditTrails()
			this.getRelations()
		}
	},
	methods: {
		getFiles() {
			this.fileLoading = true

			objectStore.getFiles(objectStore.objectItem.id, {
				limit: this.pagination.files.limit,
				page: this.pagination.files.currentPage,
			}).finally(() => {
				this.fileLoading = false
			})
		},
		getAuditTrails() {
			this.auditTrailLoading = true

			objectStore.getAuditTrails(objectStore.objectItem.id, {
				limit: this.pagination.auditTrails.limit,
				page: this.pagination.auditTrails.currentPage,
			})
				.then(({ data }) => {
					this.auditTrails = data
					this.auditTrailLoading = false
				})
				.finally(() => {
					this.auditTrailLoading = false
				})
		},
		getRelations() {
			this.relationsLoading = true

			objectStore.getRelations(objectStore.objectItem.id, {
				limit: this.pagination.relations.limit,
				page: this.pagination.relations.currentPage,
			})
				.then(({ data }) => {
					this.relations = data
					this.relationsLoading = false
				})
				.finally(() => {
					this.relationsLoading = false
				})
		},
		/**
		 * Opens the folder URL in a new tab after parsing the encoded URL and converting to Nextcloud format
		 * @param {string} url - The encoded folder URL to open (e.g. "Open Registers\/Publicatie Register\/Publicatie\/123")
		 */
		openFolder(url) {
			// Parse the encoded URL by replacing escaped characters
			const decodedUrl = url.replace(/\\\//g, '/')

			// Ensure URL starts with forward slash
			const normalizedUrl = decodedUrl.startsWith('/') ? decodedUrl : '/' + decodedUrl

			// Construct the proper Nextcloud Files app URL with the normalized path
			// Use window.location.origin to get the current domain instead of hardcoding
			const nextcloudUrl = `${window.location.origin}/index.php/apps/files/files?dir=${encodeURIComponent(normalizedUrl)}`

			// Open URL in new tab
			window.open(nextcloudUrl, '_blank')
		},
		/**
		 * Opens a file in the Nextcloud Files app
		 * @param {object} file - The file object containing id, path, and other metadata
		 */
		openFile(file) {
			// Extract the directory path without the filename
			const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))

			// Remove the '/admin/files/' prefix if it exists
			const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')

			// Construct the proper Nextcloud Files app URL with file ID and openfile parameter
			const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`

			// Open URL in new tab
			window.open(filesAppUrl, '_blank')
		},
		/**
		 * Formats a file size in bytes to a human readable string
		 * @param {number} bytes - The file size in bytes
		 * @return {string} Formatted file size (e.g. "1.5 MB")
		 */
		 formatFileSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (bytes === 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
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

<style scoped>
.fileLabelsContainer {
	display: inline-flex;
	gap: 3px;
}
</style>
