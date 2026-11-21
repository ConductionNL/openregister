<template>
	<NcDialog
		:name="t('openregister', 'Edit View')"
		size="large"
		@closing="handleClose">
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openregister', 'View successfully updated') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success">
			<!-- Tabs -->
			<div class="tabContainer">
				<AppTabs v-model="activeTab" contentClass="mt-3" justified>
					<AppTab active>
						<template #title>
							<Cog :size="16" />
							<span>{{ t('openregister', 'Settings') }}</span>
						</template>
						<div class="form-editor">
							<NcTextField
								v-model="viewData.name"
								:disabled="loading"
								:label="t('openregister', 'View Name') + ' *'"
								:placeholder="
									t('openregister', 'Enter view name...')
								"
								:error="!viewData.name.trim() && nameTouched"
								:helperText="
									!viewData.name.trim() && nameTouched
										? t('openregister', 'View name is required')
										: ''
								"
								@blur="nameTouched = true" />

							<NcTextField
								v-model="viewData.description"
								:disabled="loading"
								:label="t('openregister', 'Description')"
								:placeholder="
									t(
										'openregister',
										'Enter description (optional)...',
									)
								" />

							<NcCheckboxRadioSwitch
								v-model="viewData.isPublic"
								:disabled="loading"
								type="switch">
								{{ t('openregister', 'Public View') }}
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Public views can be accessed by anyone in the system',
									)
								}}
							</p>
						</div>
					</AppTab>

					<AppTab>
						<template #title>
							<ViewDashboardOutline :size="16" />
							<span>{{ t('openregister', 'Presentation') }}</span>
						</template>
						<div class="form-editor">
							<CnViewPresentationPicker
								:schema="viewSchema"
								:value="viewData.presentation"
								:errors="presentationErrors"
								:disabled="loading"
								@input="onPresentationInput" />
						</div>
					</AppTab>

					<AppTab>
						<template #title>
							<ShareVariant :size="16" />
							<span>{{ t('openregister', 'Share') }}</span>
						</template>
						<div class="form-editor">
							<GroupSelect
								v-model="selectedGroups"
								:label="t('openregister', 'Share with Groups')"
								:options="availableGroups"
								:disabled="loading"
								:loading="loadingGroups"
								:closeOnSelect="false"
								:placeholder="
									t('openregister', 'Type to search groups...')
								"
								:emptyText="
									t('openregister', 'Type to search for groups')
								"
								:hint="
									t(
										'openregister',
										'Members of selected groups can access this view',
									)
								"
								@searchChange="searchGroups" />

							<div class="groups-select-container">
								<label class="groups-label">{{
									t('openregister', 'Share with Users')
								}}</label>
								<NcSelect
									v-model="selectedUsers"
									inputLabel="Selected Users"
									:disabled="loading || loadingUsers"
									:options="availableUsers"
									label="name"
									trackBy="id"
									:multiple="true"
									:labelOutside="true"
									:filterable="false"
									:closeOnSelect="false"
									:placeholder="
										t('openregister', 'Type to search users...')
									"
									@searchChange="searchUsers">
									<template #option="{ name }">
										<div class="user-option">
											<span class="user-name">{{ name }}</span>
										</div>
									</template>
									<template #no-options>
										<span v-if="loadingUsers">{{
											t('openregister', 'Loading users...')
										}}</span>
										<span v-else>{{
											t(
												'openregister',
												'Type to search for users',
											)
										}}</span>
									</template>
								</NcSelect>
								<p class="field-hint">
									{{
										t(
											'openregister',
											'Selected users can access this view',
										)
									}}
								</p>
							</div>
						</div>
					</AppTab>
				</AppTabs>
			</div>

			<!-- Actions -->
			<div class="modal-actions">
				<NcButton variant="secondary" @click="handleClose()">
					<template #icon>
						<Close :size="20" />
					</template>
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!viewData.name.trim() || loading"
					@click="saveView()">
					<template #icon>
						<ContentSave :size="20" />
					</template>
					{{
						loading
							? t('openregister', 'Saving...')
							: t('openregister', 'Save')
					}}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { CnViewPresentationPicker } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import GroupSelect from '../../components/shared/GroupSelect.vue'
import AppTab from '../../components/tabs/AppTab.vue'
import AppTabs from '../../components/tabs/AppTabs.vue'
import { searchGroupsDebounced } from '../../services/groupSearch.js'
import { objectStore, viewsStore } from '../../store/store.js'

/**
 * The presentation refusal, keyed by the picker path it names.
 *
 * A view save refuses an unrenderable presentation (REQ-VIEW-PRES-01) with a
 * message that names its path — `kanban.groupByField`, `calendar.dateField`
 * or `calendar.endDateField`. The picker shows the message under that field.
 *
 * @param {string} message The server's refusal message.
 * @return {object} A map of picker path to message, empty when none matches.
 * @spec openspec/changes/saved-view-presentation-picker/specs/saved-search-views/spec.md#requirement-the-view-editor-sets-a-views-presentation-req-view-pres-06
 */
function presentationErrorsFrom(message) {
	const paths = ['kanban.groupByField', 'calendar.dateField', 'calendar.endDateField']
	const errors = {}
	for (const path of paths) {
		if (typeof message === 'string' && message.includes(path)) {
			errors[path] = message
		}
	}
	return errors
}

/**
 * Ask Nextcloud for the users matching a search.
 *
 * @param {string} searchQuery The search query
 * @return {Promise<Response>} The OCS response
 */
function fetchUsersMatching(searchQuery) {
	return fetch(
		`/ocs/v1.php/cloud/users?format=json&search=${encodeURIComponent(searchQuery)}`,
		{
			headers: {
				'OCS-APIRequest': 'true',
			},
		},
	)
}

export default {
	name: 'EditView',
	components: {
		CnViewPresentationPicker,
		GroupSelect,
		NcDialog,
		NcTextField,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcNoteCard,
		AppTabs,
		AppTab,
		Cog,
		ShareVariant,
		ViewDashboardOutline,
		Close,
		ContentSave,
	},

	props: {
		view: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			activeTab: 'settings',
			nameTouched: false,
			loading: false,
			loadingGroups: false,
			loadingUsers: false,
			success: false,
			error: null,
			presentationErrors: {},
			viewData: emptyViewData(),

			...emptySharing(),
		}
	},

	computed: {
		/**
		 * The JSON schema the view is edited against — the schema the Tables
		 * page is filtered to — which decides what each presentation role
		 * offers. Null when no schema is active; the picker then offers Table
		 * only.
		 *
		 * @return {object|null} The schema with its `properties`.
		 * @spec openspec/changes/saved-view-presentation-picker/specs/saved-search-views/spec.md#requirement-the-view-editor-sets-a-views-presentation-req-view-pres-06
		 */
		viewSchema() {
			return objectStore.searchSchema || null
		},
	},

	watch: {
		view: {
			immediate: true,
			/**
			 * @param {object|null} newView
			 * @spec exclude Watcher hydrating the local form model from the view prop; UI reactivity plumbing.
			 */
			handler(newView) {
				if (newView) {
					this.viewData = viewFormData(newView)

					// Initialize selected groups and users from the view
					// Convert string IDs to objects for NcSelect
					this.selectedGroups = sharedGroupsOf(newView)
					this.selectedUsers = sharedUsersOf(newView)

					// Populate available options with currently selected items
					this.availableGroups = [...this.selectedGroups]
					this.availableUsers = [...this.selectedUsers]

					this.activeTab = 'settings'
					this.nameTouched = false
					this.success = false
					this.error = null
				}
			},
		},
	},

	methods: {
		t,
		/**
		 * Record the chosen presentation and clear a refusal shown on it.
		 *
		 * @param {object} presentation The presentation the picker emits.
		 * @return {void}
		 * @spec openspec/changes/saved-view-presentation-picker/specs/saved-search-views/spec.md#requirement-the-view-editor-sets-a-views-presentation-req-view-pres-06
		 */
		onPresentationInput(presentation) {
			this.viewData.presentation = presentation
			this.presentationErrors = {}
		},

		/**
		 * Search for Nextcloud groups with debouncing
		 *
		 * @param {string} searchQuery - The search query entered by user
		 * @return {void}
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		searchGroups(searchQuery) {
			searchGroupsDebounced(this, {
				query: searchQuery,
				onEmpty: () => {
					this.availableGroups = []
				},
				toGroup: (groupId) => ({ id: groupId, name: groupId }),
				warnOnRefusal: true,
			})
		},

		/**
		 * Search for Nextcloud users with debouncing
		 *
		 * @param {string} searchQuery - The search query entered by user
		 * @return {void}
		 * @spec exclude Debounced user-autocomplete search via OCS; UI search plumbing.
		 */
		searchUsers(searchQuery) {
			// Clear existing debounce timer
			if (this.userSearchDebounce) {
				clearTimeout(this.userSearchDebounce)
			}

			// If search is empty, clear results
			if (!searchQuery || searchQuery.trim() === '') {
				this.availableUsers = []
				return
			}

			// Debounce the search by 300ms
			this.userSearchDebounce = setTimeout(
				() => this.runUserSearch(searchQuery),
				300,
			)
		},

		/**
		 * Look up the Nextcloud users matching a search, keeping the selected
		 * users visible.
		 *
		 * @param {string} searchQuery The search query entered by the user
		 * @return {Promise<void>}
		 */
		async runUserSearch(searchQuery) {
			this.loadingUsers = true
			try {
				const response = await fetchUsersMatching(searchQuery)
				if (response.ok) {
					this.showFoundUsers(await response.json())
				} else {
					console.warn('Failed to search users:', response.statusText)
				}
			} catch (error) {
				console.error('Error searching Nextcloud users:', error)
			} finally {
				this.loadingUsers = false
			}
		},

		/**
		 * Offer the found users after the already selected ones, without
		 * listing a selected user twice.
		 *
		 * @param {object} data The OCS answer
		 * @return {void}
		 */
		showFoundUsers(data) {
			if (!data.ocs?.data?.users) {
				return
			}
			// Transform user IDs into objects
			const searchResults = data.ocs.data.users.map((userId) => ({
				id: userId,
				name: userId,
			}))
			const selectedUserIds = this.selectedUsers.map((u) => u.id)
			this.availableUsers = [
				...this.selectedUsers,
				...searchResults.filter((u) => !selectedUserIds.includes(u.id)),
			]
		},

		/**
		 * @spec exclude Save handler delegating to viewsStore.updateView and refreshing the list; entity persistence lives in the store, this is modal orchestration plumbing.
		 */
		async saveView() {
			if (!this.viewData.name.trim()) {
				this.nameTouched = true
				return
			}

			this.loading = true
			this.error = null
			this.presentationErrors = {}

			try {
				await this.persistView()
			} catch (error) {
				this.showSaveFailure(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the view as the form describes it, then say so.
		 *
		 * @return {Promise<void>}
		 */
		async persistView() {
			await viewsStore.updateView(
				this.view.id || this.view.uuid,
				this.viewUpdate(),
			)
			await this.showSaved()
		},

		/**
		 * The view as the form now describes it, with whom it is shared.
		 *
		 * @return {object} The view update
		 */
		viewUpdate() {
			const sharedWith = this.selectedGroups.map((g) => ({
				group: g.id,
				mode: g.mode || 'read',
			}))
			return {
				name: this.viewData.name.trim(),
				description: this.viewData.description || '',
				isPublic: this.viewData.isPublic,
				isDefault: this.viewData.isDefault,
				query: this.viewData.query,
				presentation: this.viewData.presentation || { viewType: 'table' },
				sharedWith,
				sharedUsers: this.selectedUsers.map((u) => u.id),
			}
		},

		/**
		 * Say the view was saved, refresh the views list and close after a
		 * brief delay to show the success message.
		 *
		 * @return {Promise<void>}
		 */
		async showSaved() {
			this.success = true

			// Refresh views list
			await viewsStore.fetchViews()

			setTimeout(() => {
				this.$emit('close')
			}, 1500)
		},

		/**
		 * Log a failed save and say why it failed: the server's error, the
		 * error's message, or a generic one.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 */
		showSaveFailure(error) {
			console.error('Error updating view:', error)
			const message =
				error.body?.error
				|| error.response?.data?.error
				|| error.message
				|| this.t('openregister', 'Failed to update view')
			this.error = message
			this.presentationErrors = presentationErrorsFrom(message)
		},

		/**
		 * @spec exclude Modal close handler resetting local state and emitting close; UI plumbing.
		 */
		handleClose() {
			this.success = false
			this.error = null
			this.activeTab = 'settings'
			this.nameTouched = false
			this.$emit('close')
		},
	},
}

/**
 * The form of a new view: unnamed, private, not the default, no query.
 *
 * @return {object} The form
 */
function emptyViewData() {
	return {
		name: '',
		description: '',
		isPublic: false,
		isDefault: false,
		query: {},
		presentation: { viewType: 'table' },
	}
}

/**
 * The sharing state of a new view: no groups or users chosen or offered, and
 * no search pending.
 *
 * @return {object} The state
 */
function emptySharing() {
	return {
		selectedGroups: [],
		selectedUsers: [],
		availableGroups: [],
		availableUsers: [],
		groupSearchDebounce: null,
		userSearchDebounce: null,
	}
}

/**
 * The form of a stored view; its query falls back to its configuration.
 *
 * @param {object} view The view
 * @return {object} The form
 */
function viewFormData(view) {
	return {
		name: view.name || '',
		description: view.description || '',
		isPublic: view.isPublic || false,
		isDefault: view.isDefault || false,
		query: view.query || view.configuration || {},
		presentation: view.presentation || { viewType: 'table' },
	}
}

/**
 * The groups a view is shared with, as select options. A view's group shares
 * are `sharedWith: [{ group, mode }]`; the mode of an existing share is kept,
 * and a share without one reads.
 *
 * @param {object} view The view
 * @return {Array<{id: string, name: string, mode: string}>} The options
 */
function sharedGroupsOf(view) {
	return (view.sharedWith || []).map((share) => ({
		id: share.group,
		name: share.group,
		mode: share.mode || 'read',
	}))
}

/**
 * The users a view is shared with, as select options.
 *
 * @param {object} view The view
 * @return {Array<{id: string, name: string}>} The options
 */
function sharedUsersOf(view) {
	return (view.sharedUsers || []).map((id) => ({
		id,
		name: id,
	}))
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

/* EditView-specific styles matching EditOrganisation */
.tabContainer {
	margin-top: 20px;
}

/* Tab title styling with icons */
@include tab-container-deep-nav-link-rules;

@include groups-select-container-rules;

.group-option,
.user-option {
	display: flex;
	align-items: center;
	gap: 8px;
}

.group-name,
.user-name {
	font-weight: 500;
}

.modal-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	padding: 16px 0 0 0;
	border-top: 1px solid var(--color-border);
	margin-top: 24px;
}
</style>
