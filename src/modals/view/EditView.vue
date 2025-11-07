<template>
	<NcDialog
		:name="t('openregister', 'Edit View')"
		size="large"
		:can-close="true"
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
				<BTabs v-model="activeTab" content-class="mt-3" justified>
					<BTab active>
						<template #title>
							<Cog :size="16" />
							<span>{{ t('openregister', 'Settings') }}</span>
						</template>
						<div class="form-editor">
							<NcTextField
								:disabled="loading"
								:label="t('openregister', 'View Name') + ' *'"
								:value.sync="viewData.name"
								:placeholder="t('openregister', 'Enter view name...')"
								:error="!viewData.name.trim() && nameTouched"
								:helper-text="!viewData.name.trim() && nameTouched ? t('openregister', 'View name is required') : ''"
								@blur="nameTouched = true" />
							
							<NcTextField
								:disabled="loading"
								:label="t('openregister', 'Description')"
								:value.sync="viewData.description"
								:placeholder="t('openregister', 'Enter description (optional)...')" />
							
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="viewData.isPublic"
								type="switch">
								{{ t('openregister', 'Public View') }}
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								{{ t('openregister', 'Public views can be accessed by anyone in the system') }}
							</p>
						</div>
					</BTab>

					<BTab>
						<template #title>
							<ShareVariant :size="16" />
							<span>{{ t('openregister', 'Share') }}</span>
						</template>
						<div class="form-editor">
							<div class="groups-select-container">
								<label class="groups-label">{{ t('openregister', 'Share with Groups') }}</label>
								<NcSelect
									v-model="selectedGroups"
									:disabled="loading || loadingGroups"
									:options="availableGroups"
									label="name"
									track-by="id"
									:multiple="true"
									:label-outside="true"
									:filterable="false"
									:close-on-select="false"
									:placeholder="t('openregister', 'Type to search groups...')"
									@search-change="searchGroups">
									<template #option="{ name }">
										<div class="group-option">
											<span class="group-name">{{ name }}</span>
										</div>
									</template>
									<template #no-options>
										<span v-if="loadingGroups">{{ t('openregister', 'Loading groups...') }}</span>
										<span v-else>{{ t('openregister', 'Type to search for groups') }}</span>
									</template>
								</NcSelect>
								<p class="field-hint">
									{{ t('openregister', 'Members of selected groups can access this view') }}
								</p>
							</div>

							<div class="groups-select-container">
								<label class="groups-label">{{ t('openregister', 'Share with Users') }}</label>
								<NcSelect
									v-model="selectedUsers"
									:disabled="loading || loadingUsers"
									:options="availableUsers"
									label="name"
									track-by="id"
									:multiple="true"
									:label-outside="true"
									:filterable="false"
									:close-on-select="false"
									:placeholder="t('openregister', 'Type to search users...')"
									@search-change="searchUsers">
									<template #option="{ name }">
										<div class="user-option">
											<span class="user-name">{{ name }}</span>
										</div>
									</template>
									<template #no-options>
										<span v-if="loadingUsers">{{ t('openregister', 'Loading users...') }}</span>
										<span v-else>{{ t('openregister', 'Type to search for users') }}</span>
									</template>
								</NcSelect>
								<p class="field-hint">
									{{ t('openregister', 'Selected users can access this view') }}
								</p>
							</div>
						</div>
					</BTab>
				</BTabs>
			</div>

			<!-- Actions -->
			<div class="modal-actions">
				<NcButton
					type="secondary"
					@click="handleClose()">
					<template #icon>
						<Close :size="20" />
					</template>
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!viewData.name.trim() || loading"
					@click="saveView()">
					<template #icon>
						<ContentSave :size="20" />
					</template>
					{{ loading ? t('openregister', 'Saving...') : t('openregister', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcTextField, NcButton, NcCheckboxRadioSwitch, NcSelect, NcNoteCard } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { translate as t } from '@nextcloud/l10n'
import { viewsStore } from '../../store/store.js'

export default {
	name: 'EditView',
	components: {
		NcDialog,
		NcTextField,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcNoteCard,
		BTabs,
		BTab,
		Cog,
		ShareVariant,
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
			viewData: {
				name: '',
				description: '',
				isPublic: false,
				isDefault: false,
				query: {},
			},
			selectedGroups: [],
			selectedUsers: [],
			availableGroups: [],
			availableUsers: [],
			groupSearchDebounce: null,
			userSearchDebounce: null,
		}
	},
	watch: {
		view: {
			immediate: true,
			handler(newView) {
				if (newView) {
					this.viewData = {
						name: newView.name || '',
						description: newView.description || '',
						isPublic: newView.isPublic || false,
						isDefault: newView.isDefault || false,
						query: newView.query || newView.configuration || {},
					}
					
					// Initialize selected groups and users from the view
					// Convert string IDs to objects for NcSelect
					this.selectedGroups = (newView.sharedGroups || []).map(id => ({ id, name: id }))
					this.selectedUsers = (newView.sharedUsers || []).map(id => ({ id, name: id }))
					
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
		 * Search for Nextcloud groups with debouncing
		 *
		 * @param {string} searchQuery - The search query entered by user
		 * @return {void}
		 */
		searchGroups(searchQuery) {
			// Clear existing debounce timer
			if (this.groupSearchDebounce) {
				clearTimeout(this.groupSearchDebounce)
			}

			// If search is empty, clear results
			if (!searchQuery || searchQuery.trim() === '') {
				this.availableGroups = []
				return
			}

			// Debounce the search by 300ms
			this.groupSearchDebounce = setTimeout(async () => {
				this.loadingGroups = true
				try {
					// Query Nextcloud OCS API with search parameter
					const response = await fetch(`/ocs/v1.php/cloud/groups?format=json&search=${encodeURIComponent(searchQuery)}`, {
						headers: {
							'OCS-APIRequest': 'true',
						},
					})

					if (response.ok) {
						const data = await response.json()
						if (data.ocs?.data?.groups) {
							// Transform group IDs into objects
							const searchResults = data.ocs.data.groups.map(groupId => ({
								id: groupId,
								name: groupId,
							}))

							// Merge with already selected groups to ensure they remain visible
							const selectedGroupIds = this.selectedGroups.map(g => g.id)
							const mergedGroups = [
								...this.selectedGroups,
								...searchResults.filter(g => !selectedGroupIds.includes(g.id)),
							]

							this.availableGroups = mergedGroups
						}
					} else {
						console.warn('Failed to search groups:', response.statusText)
					}
				} catch (error) {
					console.error('Error searching Nextcloud groups:', error)
				} finally {
					this.loadingGroups = false
				}
			}, 300)
		},

		/**
		 * Search for Nextcloud users with debouncing
		 *
		 * @param {string} searchQuery - The search query entered by user
		 * @return {void}
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
			this.userSearchDebounce = setTimeout(async () => {
				this.loadingUsers = true
				try {
					// Query Nextcloud OCS API with search parameter
					const response = await fetch(`/ocs/v1.php/cloud/users?format=json&search=${encodeURIComponent(searchQuery)}`, {
						headers: {
							'OCS-APIRequest': 'true',
						},
					})

					if (response.ok) {
						const data = await response.json()
						if (data.ocs?.data?.users) {
							// Transform user IDs into objects
							const searchResults = data.ocs.data.users.map(userId => ({
								id: userId,
								name: userId,
							}))

							// Merge with already selected users to ensure they remain visible
							const selectedUserIds = this.selectedUsers.map(u => u.id)
							const mergedUsers = [
								...this.selectedUsers,
								...searchResults.filter(u => !selectedUserIds.includes(u.id)),
							]

							this.availableUsers = mergedUsers
						}
					} else {
						console.warn('Failed to search users:', response.statusText)
					}
				} catch (error) {
					console.error('Error searching Nextcloud users:', error)
				} finally {
					this.loadingUsers = false
				}
			}, 300)
		},
		async saveView() {
			if (!this.viewData.name.trim()) {
				this.nameTouched = true
				return
			}

			this.loading = true
			this.error = null

			try {
				const updateData = {
					name: this.viewData.name.trim(),
					description: this.viewData.description || '',
					isPublic: this.viewData.isPublic,
					isDefault: this.viewData.isDefault,
					query: this.viewData.query,
					sharedGroups: this.selectedGroups.map(g => g.id),
					sharedUsers: this.selectedUsers.map(u => u.id),
				}

				await viewsStore.updateView(this.view.id || this.view.uuid, updateData)
				
				this.success = true
				
				// Refresh views list
				await viewsStore.fetchViews()

				// Close after a brief delay to show success message
				setTimeout(() => {
					this.$emit('close')
				}, 1500)
			} catch (error) {
				console.error('Error updating view:', error)
				this.error = error.response?.data?.error || error.message || this.t('openregister', 'Failed to update view')
			} finally {
				this.loading = false
			}
		},
		handleClose() {
			this.success = false
			this.error = null
			this.activeTab = 'settings'
			this.nameTouched = false
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
/* EditView-specific styles matching EditOrganisation */
.tabContainer {
	margin-top: 20px;
}

/* Tab title styling with icons */
.tabContainer :deep(.nav-link) {
	display: flex;
	align-items: center;
	gap: 8px;
	justify-content: center;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.groups-select-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.groups-label {
	font-weight: 500;
	color: var(--color-main-text);
	font-size: 14px;
}

.field-hint {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0;
}

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

