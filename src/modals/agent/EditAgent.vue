<script setup>
import { agentStore, organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="agentStore.agentItem?.uuid ? 'Edit Agent' : 'Create Agent'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Agent successfully {{ agentStore.agentItem?.uuid ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="!success">
			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs v-model="activeTab" content-class="mt-3" justified>
					<BTab title="Settings" active>
						<div class="form-editor">
							<NcTextField
								:disabled="loading"
								label="Name *"
								:value.sync="agentItem.name"
								:error="!agentItem.name.trim()"
								placeholder="Enter agent name" />

							<NcTextArea
								:disabled="loading"
								label="Description"
								:value.sync="agentItem.description"
								placeholder="Enter agent description (optional)"
								:rows="4" />

							<NcSelect
								v-model="selectedType"
								:disabled="loading"
								:options="agentTypes"
								input-label="Agent Type"
								label="label"
								track-by="value"
								placeholder="Select agent type"
								@input="updateType">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
						</NcSelect>

						<NcTextArea
								:disabled="loading"
								label="System Prompt"
								:value.sync="agentItem.prompt"
								placeholder="Enter system prompt for the agent"
								:rows="6" />

						<div class="form-field">
							<label for="temperature" class="slider-label">
								{{ t('openregister', 'Temperature') }}: {{ agentItem.temperature }}
							</label>
							<input
								id="temperature"
								v-model.number="agentItem.temperature"
								:disabled="loading"
								type="range"
								min="0"
								max="2"
								step="0.1"
								class="temperature-slider">
							<p class="field-hint">{{ t('openregister', 'Controls randomness (0 = deterministic, 2 = very creative)') }}</p>
						</div>

						<NcTextField
							:disabled="loading"
							label="Max Tokens"
							type="number"
							:value.sync="agentItem.maxTokens"
							placeholder="1000">
							<template #helper-text-message>
								{{ t('openregister', 'Maximum tokens to generate') }}
							</template>
						</NcTextField>

							<NcCheckboxRadioSwitch
								:checked="agentItem.active"
								type="switch"
								@update:checked="agentItem.active = $event">
								Active
							</NcCheckboxRadioSwitch>
						</div>
					</BTab>

				<BTab title="RAG Configuration">
					<div class="form-editor">
						<NcCheckboxRadioSwitch
							:checked="agentItem.enableRag"
							type="switch"
							@update:checked="agentItem.enableRag = $event">
							Enable RAG
						</NcCheckboxRadioSwitch>

						<div v-if="agentItem.enableRag" class="rag-config">
							<NcSelect
								v-model="selectedRagSearchMode"
								:disabled="loading"
								:options="ragSearchModes"
								input-label="Search Mode"
								label="label"
								track-by="value"
								placeholder="Select search mode"
								@input="updateRagSearchMode">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
							</NcSelect>

							<NcTextField
								:disabled="loading"
								label="Number of Sources"
								type="number"
								min="1"
								max="20"
								:value.sync="agentItem.ragNumSources"
								placeholder="5" />

							<div class="views-select-container">
								<NcSelect
									v-model="selectedViews"
									:disabled="loading || loadingViews"
									:options="availableViews"
									input-label="Data Views"
									label="name"
									track-by="id"
									:multiple="true"
									placeholder="Select views to filter data (optional)"
									@input="updateViews">
									<template #option="{ name, description }">
										<div class="view-option">
											<span class="view-name">{{ name }}</span>
											<span v-if="description" class="view-description">{{ description }}</span>
										</div>
									</template>
								</NcSelect>
								<p class="field-hint">
									Select views to limit which data the agent can access
								</p>
							</div>

							<NcCheckboxRadioSwitch
								:checked="agentItem.searchFiles"
								type="switch"
								@update:checked="agentItem.searchFiles = $event">
								Search in Files
							</NcCheckboxRadioSwitch>

							<NcCheckboxRadioSwitch
								:checked="agentItem.searchObjects"
								type="switch"
								@update:checked="agentItem.searchObjects = $event">
								Search in Database Objects
							</NcCheckboxRadioSwitch>
						</div>
					</div>
				</BTab>

					<BTab title="Resource Quotas">
						<div class="form-editor">
							<NcNoteCard type="info">
								<p><strong>Resource Quotas</strong></p>
								<p>Set limits for API usage and token consumption. Use 0 for unlimited resources.</p>
							</NcNoteCard>

							<NcTextField
								:disabled="loading"
								label="Request Quota (per day)"
								type="number"
								placeholder="0 = unlimited"
								:value.sync="agentItem.requestQuota" />

							<NcTextField
								:disabled="loading"
								label="Token Quota (per request)"
								type="number"
								placeholder="0 = unlimited"
								:value.sync="agentItem.tokenQuota" />
						</div>
					</BTab>

				<BTab title="Security">
					<div class="security-section">
						<NcCheckboxRadioSwitch
							:checked="agentItem.isPrivate"
							type="switch"
							@update:checked="agentItem.isPrivate = $event">
							Private Agent (Default)
						</NcCheckboxRadioSwitch>
						<p class="field-hint">
							<strong>Private agents</strong> are only accessible to invited users. 
							Disable this to make the agent <strong>public</strong> and accessible to all users in selected groups (or all users if no groups selected).
						</p>

						<div v-if="!agentItem.isPrivate" class="groups-select-container">
							<NcSelect
								v-model="selectedGroups"
								:disabled="loading || loadingGroups"
								:options="availableGroups"
								input-label="Select groups with access to this agent"
								label="name"
								track-by="id"
								:multiple="true"
								placeholder="Select groups (optional)"
								@input="updateGroups">
								<template #option="{ name }">
									<div class="group-option">
										<span class="group-name">{{ name }}</span>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								Leave empty to allow all users access
							</p>
						</div>

						<div v-if="agentItem.isPrivate" class="invited-users-container">
							<NcTextField
								:value.sync="newUserInput"
								:disabled="loading"
								label="Invite Users"
								placeholder="Enter username and press Enter"
								@keyup.enter="addInvitedUser">
								<template #trailing-button-icon>
									<NcButton
										type="tertiary"
										:disabled="!newUserInput || loading"
										@click="addInvitedUser">
										Add
									</NcButton>
								</template>
							</NcTextField>
							<p class="field-hint">
								Enter Nextcloud usernames to grant access to this private agent
							</p>

							<div v-if="selectedInvitedUsers.length > 0" class="invited-users-list">
								<h3>Invited Users</h3>
								<div class="user-items">
									<div v-for="user in selectedInvitedUsers" :key="user" class="user-item">
										<span class="user-badge">{{ user }}</span>
										<NcButton
											type="tertiary"
											:disabled="loading"
											@click="removeInvitedUser(user)">
											<template #icon>
												<Close :size="16" />
											</template>
										</NcButton>
									</div>
								</div>
							</div>
						</div>

						<div v-if="loadingGroups" class="loading-indicator">
							<NcLoadingIcon :size="20" />
							<span>Loading groups...</span>
						</div>
					</div>
				</BTab>
				</BTabs>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				Cancel
			</NcButton>
			<NcButton
				:disabled="!isValid || loading"
				type="primary"
				@click="saveAgent">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ agentStore.agentItem?.uuid ? 'Update' : 'Create' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { BTabs, BTab } from 'bootstrap-vue'
import {
	NcDialog,
	NcButton,
	NcTextField,
	NcTextArea,
	NcSelect,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcLoadingIcon,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'EditAgent',
	components: {
		BTabs,
		BTab,
		NcDialog,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcLoadingIcon,
		ContentSaveOutline,
		Close,
	},
	data() {
		return {
			activeTab: 0,
			loading: false,
			success: false,
			error: null,
			agentItem: {
				name: '',
				description: '',
				type: 'chat',
				prompt: '',
				temperature: 0.7,
				maxTokens: 1000,
				active: true,
				enableRag: false,
				ragSearchMode: 'hybrid',
				ragNumSources: 5,
				ragIncludeFiles: false,
				ragIncludeObjects: false,
				requestQuota: 0,
				tokenQuota: 0,
				groups: [],
				views: [],
				searchFiles: true,
				searchObjects: true,
				isPrivate: true,
				invitedUsers: [],
			},
			selectedType: null,
			selectedRagSearchMode: null,
			selectedGroups: [],
			selectedViews: [],
			selectedInvitedUsers: [],
			newUserInput: '',
			loadingGroups: false,
			availableGroups: [],
			loadingViews: false,
			availableViews: [],
			agentTypes: [
				{ value: 'chat', label: 'Chat', description: 'Conversational AI assistant' },
				{ value: 'automation', label: 'Automation', description: 'Automated task execution' },
				{ value: 'analysis', label: 'Analysis', description: 'Data analysis and insights' },
				{ value: 'assistant', label: 'Assistant', description: 'General purpose assistant' },
			],
			ragSearchModes: [
				{ value: 'hybrid', label: 'Hybrid', description: 'Combined keyword + semantic search' },
				{ value: 'semantic', label: 'Semantic', description: 'AI-powered semantic search' },
				{ value: 'keyword', label: 'Keyword', description: 'Traditional keyword search' },
			],
		}
	},
	computed: {
		isValid() {
			return this.agentItem.name && this.agentItem.name.trim().length > 0
		},
	},
	watch: {
		'navigationStore.modal'(newValue) {
			if (newValue === 'editAgent') {
				this.initializeAgent()
			}
		},
	},
	mounted() {
		this.initializeAgent()
		this.fetchGroups()
		this.fetchViews()
	},
	methods: {
		initializeAgent() {
			if (agentStore.agentItem) {
				this.agentItem = { ...agentStore.agentItem }
				this.selectedType = this.agentTypes.find(t => t.value === this.agentItem.type)
				this.selectedRagSearchMode = this.ragSearchModes.find(m => m.value === this.agentItem.ragSearchMode)
				if (this.agentItem.groups && Array.isArray(this.agentItem.groups)) {
					this.selectedGroups = this.availableGroups.filter(g => this.agentItem.groups.includes(g.id))
				}
				if (this.agentItem.views && Array.isArray(this.agentItem.views)) {
					this.selectedViews = this.availableViews.filter(v => this.agentItem.views.includes(v.id))
				}
				if (this.agentItem.invitedUsers && Array.isArray(this.agentItem.invitedUsers)) {
					this.selectedInvitedUsers = [...this.agentItem.invitedUsers]
				}
			} else {
				this.agentItem = {
					name: '',
					description: '',
					type: 'chat',
					prompt: '',
					temperature: 0.7,
					maxTokens: 1000,
					active: true,
					enableRag: false,
					ragSearchMode: 'hybrid',
					ragNumSources: 5,
					searchFiles: true,
					searchObjects: true,
					requestQuota: 0,
					tokenQuota: 0,
					groups: [],
					views: [],
					isPrivate: true,
					invitedUsers: [],
				}
				this.selectedType = this.agentTypes[0]
				this.selectedRagSearchMode = this.ragSearchModes[0]
				this.selectedGroups = []
				this.selectedViews = []
				this.selectedInvitedUsers = []
			}
			this.newUserInput = ''
			this.success = false
			this.error = null
		},
		updateType(type) {
			this.agentItem.type = type ? type.value : 'chat'
		},
		updateRagSearchMode(mode) {
			this.agentItem.ragSearchMode = mode ? mode.value : 'hybrid'
		},
		updateGroups(groups) {
			this.agentItem.groups = groups ? groups.map(g => g.id) : []
		},
		removeGroup(group) {
			this.selectedGroups = this.selectedGroups.filter(g => g.id !== group.id)
			this.agentItem.groups = this.selectedGroups.map(g => g.id)
		},
		updateViews(views) {
			this.agentItem.views = views ? views.map(v => v.id) : []
		},
		addInvitedUser() {
			if (!this.newUserInput || !this.newUserInput.trim()) {
				return
			}
			const username = this.newUserInput.trim()
			if (!this.selectedInvitedUsers.includes(username)) {
				this.selectedInvitedUsers.push(username)
				this.agentItem.invitedUsers = [...this.selectedInvitedUsers]
			}
			this.newUserInput = ''
		},
		removeInvitedUser(username) {
			this.selectedInvitedUsers = this.selectedInvitedUsers.filter(u => u !== username)
			this.agentItem.invitedUsers = [...this.selectedInvitedUsers]
		},
		async fetchGroups() {
			this.loadingGroups = true
			try {
				const response = await fetch('/ocs/v2.php/cloud/groups', {
					headers: {
						'OCS-APIRequest': 'true',
						Accept: 'application/json',
					},
				})
				const data = await response.json()
				if (data.ocs && data.ocs.data && data.ocs.data.groups) {
					this.availableGroups = data.ocs.data.groups.map(groupId => ({
						id: groupId,
						name: groupId,
					}))
				}
			} catch (error) {
				console.error('Error fetching groups:', error)
			} finally {
				this.loadingGroups = false
			}
		},
		async fetchViews() {
			this.loadingViews = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/views', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})
				
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}
				
				const data = await response.json()
				this.availableViews = (data.results || []).map(view => ({
					id: view.id,
					name: view.name || 'Unnamed View',
					description: view.description || '',
				}))
			} catch (error) {
				console.error('Error fetching views:', error)
			} finally {
				this.loadingViews = false
			}
		},
		async saveAgent() {
			this.loading = true
			this.error = null

			try {
				await agentStore.saveAgent(this.agentItem)
				this.success = true
				setTimeout(() => {
					this.closeModal()
				}, 1500)
			} catch (err) {
				this.error = err.message || 'Failed to save agent'
			} finally {
				this.loading = false
			}
		},
		closeModal() {
			navigationStore.setModal(null)
		},
		handleDialogClose(open) {
			if (!open) {
				this.closeModal()
			}
		},
	},
}
</script>

<style scoped>
.tabContainer {
	margin-top: 20px;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.form-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.field-hint {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0 0;
}

.slider-label {
	display: block;
	font-size: 0.9375rem;
	font-weight: 600;
	margin-bottom: 8px;
	color: var(--color-main-text);
}

.temperature-slider {
	width: 100%;
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
	outline: none;
	-webkit-appearance: none;
	appearance: none;
	margin: 8px 0;
}

.temperature-slider::-webkit-slider-thumb {
	-webkit-appearance: none;
	appearance: none;
	width: 20px;
	height: 20px;
	border-radius: 50%;
	background: var(--color-primary-element);
	cursor: pointer;
	transition: all 0.2s ease;
}

.temperature-slider::-webkit-slider-thumb:hover {
	background: var(--color-primary-element-hover);
	transform: scale(1.2);
}

.temperature-slider::-moz-range-thumb {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	background: var(--color-primary-element);
	cursor: pointer;
	border: none;
	transition: all 0.2s ease;
}

.temperature-slider::-moz-range-thumb:hover {
	background: var(--color-primary-element-hover);
	transform: scale(1.2);
}

.temperature-slider:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.temperature-slider:disabled::-webkit-slider-thumb {
	cursor: not-allowed;
}

.temperature-slider:disabled::-moz-range-thumb {
	cursor: not-allowed;
}

.option-content {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.option-title {
	font-weight: 600;
}

.option-description {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.rag-config {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.security-section {
	padding: 16px;
}

.groups-select-container {
	margin: 16px 0;
}

.groups-list {
	margin-top: 16px;
}

.groups-list h3 {
	font-size: 1rem;
	font-weight: 600;
	margin-bottom: 12px;
}

.group-items {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.views-select-container {
	margin: 16px 0;
}

.view-option {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.view-name {
	font-weight: 500;
}

.view-description {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.invited-users-container {
	margin: 16px 0;
}

.invited-users-list {
	margin-top: 16px;
}

.invited-users-list h3 {
	font-size: 1rem;
	font-weight: 600;
	margin-bottom: 12px;
}

.user-items {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.user-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	background-color: var(--color-primary-element-light);
	border-radius: var(--border-radius-large);
}

.user-badge {
	font-weight: 600;
	font-size: 0.875rem;
	color: var(--color-primary-element);
}

.loading-indicator {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px;
	color: var(--color-text-maxcontrast);
}

.group-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.group-badge {
	font-weight: 600;
	font-size: 0.875rem;
}

.loading-groups {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px;
}

.no-groups {
	padding: 16px;
	color: var(--color-text-maxcontrast);
}
</style>

