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
						<NcActionButton close-after-click @click="navigationStore.setModal('viewObject')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton v-if="!objectStore.objectItem.locked" close-after-click @click="navigationStore.setModal('lockObject')">
							<template #icon>
								<LockOutline :size="20" />
							</template>
							Lock
						</NcActionButton>
						<NcActionButton v-if="objectStore.objectItem.locked" close-after-click @click="objectStore.unlockObject(objectStore.objectItem.id)">
							<template #icon>
								<LockOpenOutline :size="20" />
							</template>
							Unlock
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteObject')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
						<NcActionButton close-after-click
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

				<span><b>Uri:</b> {{ objectStore.objectItem['@self']?.uri || objectStore.objectItem.uri }}</span>
				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>Register:</b>
						<p>{{ objectStore.objectItem['@self']?.register || objectStore.objectItem.register }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Schema:</b>
						<p>{{ objectStore.objectItem['@self']?.schema || objectStore.objectItem.schema }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Folder:</b>
						<p>{{ objectStore.objectItem['@self']?.folder || objectStore.objectItem.folder || '-' }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Updated:</b>
						<p>{{ objectStore.objectItem['@self']?.updated || objectStore.objectItem.updated }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Created:</b>
						<p>{{ objectStore.objectItem['@self']?.created || objectStore.objectItem.created }}</p>
					</div>
				</div>

				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab :title="t('openregister', 'Data')" active>
							<pre class="json-display"><!-- do not remove this comment
                                -->{{ JSON.stringify(objectStore.objectItem.object, null, 2) }}
                            </pre>
						</BTab>
						<BTab :title="t('openregister', 'Uses')">
							<div v-if="objectStore.objectItem?.relations && Object.keys(objectStore.objectItem.relations).length > 0">
								<NcListItem v-for="(relation, key) in objectStore.objectItem?.relations"
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
								{{ t('openregister', 'No relations found') }}
							</div>
						</BTab>
						<BTab :title="t('openregister', 'Used by')">
							<div v-if="objectStore.relations?.length">
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
								<BPagination v-if="!relationsLoading && objectStore.relations?.total > pagination.relations.limit"
									v-model="pagination.relations.currentPage"
									class="tabPagination"
									:total-rows="objectStore.relations?.total"
									:per-page="pagination.relations.limit" />
							</div>
							<div v-else class="tabPanel">
								No relations found
							</div>
						</BTab>
						<BTab :title="t('openregister', 'Files')">
							<NcButton @click="openFolder(objectStore.objectItem.folder)">
								<template #icon>
									<FolderOutline :size="20" />
								</template>
								{{ t('openregister', 'Open folder') }}
							</NcButton>

							<div v-if="objectStore.files?.results?.length > 0">
								<NcListItem v-for="(attachment, i) in objectStore.files?.results"
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
										<NcActionButton close-after-click @click="openFile(attachment)">
											<template #icon>
												<OpenInNew :size="20" />
											</template>
											Bekijk bestand
										</NcActionButton>
									</template>
								</NcListItem>

								<BPagination v-if="!fileLoading && objectStore.files?.total > pagination.files.limit"
									v-model="pagination.files.currentPage"
									class="tabPagination"
									:total-rows="objectStore.files?.total"
									:per-page="pagination.files.limit" />
							</div>

							<div v-if="objectStore.files?.results?.length === 0">
								Nog geen bijlage toegevoegd
							</div>

							<div
								v-if="objectStore.files?.results?.length !== 0 && !objectStore.files?.results?.length > 0 && fileLoading">
								<NcLoadingIcon :size="64"
									class="loadingIcon"
									appearance="dark"
									name="Bijlagen aan het laden" />
							</div>
						</BTab>
						<BTab :title="t('openregister', 'Syncs')">
							<div class="tabPanel">
								{{ t('openregister', 'No synchronizations found') }}
							</div>
						</BTab>
						<BTab v-if="relationContext" title="Emails">
							<EmailsTab
								:register="relationContext.register"
								:schema="relationContext.schema"
								:object-id="relationContext.id" />
						</BTab>
						<BTab v-if="relationContext" title="Events">
							<EventsTab
								:register="relationContext.register"
								:schema="relationContext.schema"
								:object-id="relationContext.id" />
						</BTab>
						<BTab v-if="relationContext" title="Contacts">
							<ContactsTab
								:register="relationContext.register"
								:schema="relationContext.schema"
								:object-id="relationContext.id" />
						</BTab>
						<BTab v-if="relationContext" title="Deck">
							<DeckTab
								:register="relationContext.register"
								:schema="relationContext.schema"
								:object-id="relationContext.id" />
						</BTab>
						<BTab v-if="relationContext" title="Relations">
							<RelationsTab
								:register="relationContext.register"
								:schema="relationContext.schema"
								:object-id="relationContext.id" />
						</BTab>
						<BTab v-if="relationContext && (integrationProviders?.length || 0) > 0" :title="t('openregister', 'Integrations')">
							<!--
								Registry-driven integration surface. Renders the tabbed
								CnIntegrationWidget (nc-vue, ADR-019/024) — one app-faithful
								tab per advertised IntegrationProvider, with the app icon +
								brand accent on the active tab, the bespoke per-leaf content
								(provider.tab) in the active panel, and an NcEmptyContent
								set-up state (app icon + "{App} not available" + docs link)
								for any integration whose backing app is missing or
								unconfigured (Phase J-B availability capability).

								This SUPERSEDES the previous hand-rolled BTabs pills +
								`provider.tab || CnIntegrationTab` dispatch, which rendered a
								flat generic surface that erased each app's visual identity.
								The widget reads the same useIntegrationRegistry() singleton
								that OR's bootstrap (main.js) populates, so every registered
								leaf (5 built-ins + xwiki + 18 leaves) still becomes a
								deterministic Playwright target with no consumer-app wiring.
							-->
							<CnIntegrationWidget
								:register="String(relationContext.register)"
								:schema="String(relationContext.schema)"
								:object-id="String(relationContext.id)"
								surface="detail-page" />
						</BTab>
						<BTab v-if="objectStore.auditTrails" :title="t('openregister', 'Audit Trails')">
							<div v-if="objectStore.auditTrails?.results?.length">
								<NcListItem v-for="(auditTrail, key) in objectStore.auditTrails?.results"
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
										<NcActionButton close-after-click @click="objectStore.setAuditTrailItem(auditTrail); navigationStore.setModal('viewObjectAuditTrail')">
											<template #icon>
												<Eye :size="20" />
											</template>
											View details
										</NcActionButton>
									</template>
								</NcListItem>
								<BPagination v-if="!auditTrailLoading && objectStore.auditTrails?.total > pagination.auditTrails.limit"
									v-model="pagination.auditTrails.currentPage"
									class="tabPagination"
									:total-rows="objectStore.auditTrails?.total"
									:per-page="pagination.auditTrails.limit" />
							</div>
							<div v-if="!objectStore.auditTrails?.results?.length">
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
import EmailsTab from '../../components/object-relations/EmailsTab.vue'
import EventsTab from '../../components/object-relations/EventsTab.vue'
import ContactsTab from '../../components/object-relations/ContactsTab.vue'
import DeckTab from '../../components/object-relations/DeckTab.vue'
import RelationsTab from '../../components/object-relations/RelationsTab.vue'
import { computed } from 'vue'
import { CnIntegrationWidget, useIntegrationRegistry } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'

export default {
	name: 'ObjectDetails',
	components: {
		NcActions,
		NcActionButton,
		NcListItem,
		NcNoteCard,
		NcButton,
		NcCounterBubble,
		NcLoadingIcon,
		BTabs,
		BTab,
		BPagination,
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
		ExclamationThick,
		OpenInNew,
		EmailsTab,
		EventsTab,
		ContactsTab,
		DeckTab,
		RelationsTab,
		CnIntegrationWidget,
	},
	/**
	 * Composition-API setup: expose the integration-provider registry and
	 * template helpers to the Options-API template.
	 *
	 * @spec exclude UI plumbing — registry snapshot and template helper exposure
	 * @return {object}
	 */
	setup() {
		// Reactive snapshot of every IntegrationProvider registered through
		// nc-vue's in-page registry — drained from window.OCA.OpenRegister
		// once main.js has called installIntegrationRegistry() +
		// registerBuiltinIntegrations() + registerLeafIntegrations(). Used
		// ONLY by the "Integrations" BTab's v-if guard, so the tab is hidden
		// when no provider is registered; CnIntegrationWidget reads the same
		// singleton itself to render the tab strip + per-leaf panels.
		//
		// IMPORTANT: This setup() block lives in the Options-API <script>
		// block. A previous version of this file also had a leading
		// <script setup> block — Vue's SFC compiler silently drops the
		// Options-API setup() when both co-exist, so useIntegrationRegistry()
		// never ran and integrationProviders stayed empty. The duplicate
		// block has been removed; the template-level helpers (t / objectStore
		// / navigationStore) that previously lived in <script setup> are now
		// re-exposed here so the template keeps working.
		const { integrations } = useIntegrationRegistry()
		const integrationProviders = computed(() => integrations.value || [])
		return {
			integrationProviders,
			t,
			objectStore,
			navigationStore,
		}
	},
	data() {
		return {
			currentActiveObject: undefined,
			// Live-updates handle for the or-object-{uuid} subscription of the
			// currently opened object (adopt-live-updates-ui). Managed by
			// syncLiveSubscription(); liveKey is `${type}::${uuid}` so a
			// re-render for the same object is a no-op. livePendingKey marks
			// an in-flight subscribe so a concurrent same-key call doesn't
			// double-subscribe; liveEpoch invalidates in-flight resolutions
			// after a release (object switch / destroy).
			liveHandle: null,
			liveKey: '',
			livePendingKey: '',
			liveEpoch: 0,
			liveUnwatch: null,
			auditTrailLoading: false,
			auditTrails: [],
			relationsLoading: false,
			relations: [],
			activeAttachment: null,
			fileLoading: false,
			// Guard against the race where deep-link navigation primes
			// objectStore.objectItem before its sub-resource plugins
			// (filesPlugin / auditTrailsPlugin / relationsPlugin) have
			// populated their backing state. Under the original
			// click-through nav flow these are always set by the time
			// the user opens an object's detail; the deep-link route
			// (/objects/:register/:schema/:id) skips that warm-up.
			pagination: {
				files: {
					limit: 200,
					currentPage: objectStore.files?.page || 1,
					totalPages: objectStore.files?.total || 1,
				},
				auditTrails: {
					limit: 200,
					currentPage: objectStore.auditTrails?.page || 1,
					totalPages: objectStore.auditTrails?.total || 1,
				},
				relations: {
					limit: 200,
					currentPage: objectStore.relations?.page || 1,
					totalPages: objectStore.relations?.total || 1,
				},
			},
		}
	},
	computed: {
		/**
		 * Build the (register, schema, id) triple used by the entity-relations
		 * tabs (Emails, Events, Contacts, Deck, Relations). Returns null when
		 * any of the three is missing, so the tabs only render once a saved
		 * object is being viewed.
		 *
		 * @spec exclude UI plumbing — derived view state gating relation tabs
		 * @return {{register:(string|number), schema:(string|number), id:string}|null}
		 */
		relationContext() {
			const item = objectStore?.objectItem
			if (!item) {
				return null
			}

			const self = item['@self'] || {}
			const register = self.register ?? item.register
			const schema = self.schema ?? item.schema
			const id = self.id ?? item.id ?? item.uuid
			if (!register || !schema || !id) {
				return null
			}

			return { register, schema, id }
		},
	},
	watch: {
		'pagination.files.currentPage': {
			/**
			 * Reload files when the files page changes.
			 *
			 * @spec exclude UI plumbing — pagination watch handler
			 * @return {void}
			 */
			handler() {
				this.getFiles()
			},
		},
		'pagination.auditTrails.currentPage': {
			/**
			 * Reload audit trails when the audit-trails page changes.
			 *
			 * @spec exclude UI plumbing — pagination watch handler
			 * @return {void}
			 */
			handler() {
				this.getAuditTrails()
			},
		},
		'pagination.relations.currentPage': {
			/**
			 * Reload relations when the relations page changes.
			 *
			 * @spec exclude UI plumbing — pagination watch handler
			 * @return {void}
			 */
			handler() {
				this.getRelations()
			},
		},
	},
	/**
	 * Lifecycle hook: load files, audit trails and relations on mount.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch for display only
	 * @return {void}
	 */
	mounted() {
		if (objectStore.objectItem?.id) {
			this.currentActiveObject = objectStore.objectItem?.id
			this.getFiles()
			this.getAuditTrails()
			this.getRelations()
		}
		this.syncLiveSubscription()
	},
	/**
	 * Lifecycle hook: reload sub-resources when the viewed object changes.
	 *
	 * @spec exclude UI plumbing — re-fetch on active-object change
	 * @return {void}
	 */
	updated() {
		if (this.currentActiveObject !== objectStore.objectItem?.id) {
			this.currentActiveObject = objectStore.objectItem?.id
			this.getFiles()
			this.getAuditTrails()
			this.getRelations()
			this.syncLiveSubscription()
		}
	},
	/**
	 * Lifecycle hook: release the live object subscription on unmount.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 * @return {void}
	 */
	beforeDestroy() {
		this.releaseLiveSubscription()
	},
	methods: {
		/**
		 * Subscribe to live updates for the currently opened object
		 * (adopt-live-updates-ui): or-object-{uuid} via notify_push with
		 * polling fallback. Events are refetch hints only — the
		 * liveUpdatesPlugin re-runs fetchObject(type, uuid), which lands in
		 * the package store's objects[type][uuid] cache; the watcher installed
		 * here bridges that fresh data into objectStore.objectItem so this
		 * detail view re-renders. Idempotent per (type, uuid); releases the
		 * previous subscription when another object is opened.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {Promise<void>}
		 */
		async syncLiveSubscription() {
			if (typeof objectStore.subscribe !== 'function') {
				return
			}
			const ctx = this.relationContext
			const item = objectStore.objectItem
			// The push event key is or-object-{uuid} — prefer the uuid over a
			// numeric id when both are present.
			const uuid = item?.['@self']?.uuid ?? item?.uuid ?? ctx?.id
			if (!ctx || !uuid) {
				this.releaseLiveSubscription()
				return
			}
			const type = `${ctx.register}-${ctx.schema}`
			const key = `${type}::${uuid}`
			if (this.liveHandle && this.liveKey === key) {
				return
			}
			if (this.livePendingKey === key) {
				// A subscribe for this exact object is already in flight —
				// re-subscribing here would leak the first handle + watcher.
				return
			}
			this.releaseLiveSubscription()
			try {
				// Subscription is independent of the list view's registration
				// timing: register the type ourselves when it is not known yet.
				if (!objectStore.objectTypes.includes(type)) {
					objectStore.registerObjectType(type, ctx.schema, ctx.register)
				}
				const epoch = this.liveEpoch
				this.livePendingKey = key
				this.liveKey = key
				const handle = await objectStore.subscribe(type, uuid)
				if (this.livePendingKey === key) {
					this.livePendingKey = ''
				}
				if (this.liveEpoch !== epoch) {
					// Released while awaiting (another object opened, or the
					// component was destroyed) — drop the stale subscription.
					objectStore.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
				// Bridge: event → plugin refetch → objects[type][uuid] cache →
				// objectItem (which this template renders).
				this.liveUnwatch = this.$watch(
					() => objectStore.getObject(type, uuid),
					(fresh) => {
						if (fresh && this.liveKey === key) {
							objectStore.setObjectItem(fresh)
						}
					},
				)
			} catch (e) {
				if (this.livePendingKey === key) {
					this.livePendingKey = ''
				}
				this.liveHandle = null
				this.liveKey = ''
				console.warn('[ObjectDetails] live subscription failed:', e?.message ?? e)
			}
		},
		/**
		 * Release the current live object subscription and its cache watcher,
		 * and invalidate any in-flight subscribe (its resolution unsubscribes
		 * itself via the epoch check).
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {void}
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingKey = ''
			if (this.liveUnwatch) {
				this.liveUnwatch()
				this.liveUnwatch = null
			}
			if (this.liveHandle && typeof objectStore.unsubscribe === 'function') {
				objectStore.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
			this.liveKey = ''
		},
		// Race-safe sub-resource fetches. Deep-link navigation primes
		// objectStore.objectItem from the REST API before the plugins
		// that own these actions (filesPlugin / auditTrailsPlugin /
		// relationsPlugin) have installed them. Calling a method that
		// doesn't exist on the store throws TypeError mid-mount and
		// aborts the whole render — guard each call.
		/**
		 * Fetch the object's attached files for display (race-safe).
		 *
		 * @spec exclude UI plumbing — delegates to the object store fetch
		 * @return {void}
		 */
		getFiles() {
			if (!objectStore.objectItem?.id || typeof objectStore.getFiles !== 'function') {
				return
			}
			this.fileLoading = true

			objectStore.getFiles(objectStore.objectItem.id, {
				limit: this.pagination.files.limit,
				page: this.pagination.files.currentPage,
			}).finally(() => {
				this.fileLoading = false
			})
		},
		/**
		 * Fetch the object's audit trails for display (race-safe).
		 *
		 * @spec exclude UI plumbing — delegates to the object store fetch
		 * @return {void}
		 */
		getAuditTrails() {
			if (!objectStore.objectItem?.id || typeof objectStore.getAuditTrails !== 'function') {
				return
			}
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
		/**
		 * Fetch the object's relations for display (race-safe).
		 *
		 * @spec exclude UI plumbing — delegates to the object store fetch
		 * @return {void}
		 */
		getRelations() {
			if (!objectStore.objectItem?.id || typeof objectStore.getRelations !== 'function') {
				return
			}
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
		 * @spec exclude UI plumbing — opens the Nextcloud Files app in a new tab
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
		 * @spec exclude UI plumbing — opens the Nextcloud Files app in a new tab
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
		 * @spec exclude UI plumbing — pure presentation helper
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
