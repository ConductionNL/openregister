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

							<NcSelect
								v-model="selectedUser"
								:disabled="loading || loadingUsers"
								:loading="loadingUsers"
								:options="availableUsers"
								input-label="Default User (for cron/background jobs)"
								label="displayName"
								track-by="id"
								placeholder="Select a user"
								@input="updateUser">
								<template #helper-text-message>
									When agent runs without a user session (e.g., scheduled tasks), this user's context will be used
								</template>
							</NcSelect>
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

				<BTab title="Tools">
					<div class="form-editor">
						<NcNoteCard type="info">
							<p><strong>Function Tools</strong></p>
							<p>Enable tools that allow the agent to interact with data through function calling.</p>
							<p>Tools respect the agent's views, permissions, and organization boundaries.</p>
						</NcNoteCard>

						<div v-if="loadingTools" class="loading-indicator">
							<NcLoadingIcon :size="20" />
							<span>Loading available tools...</span>
						</div>

						<div v-else-if="availableTools.length > 0" class="tools-selection">
							<div v-for="tool in availableTools" 
								:key="tool.id" 
								class="tool-item"
								@click="handleCardClick(tool.id, $event)">
								<div class="tool-row">
									<div class="tool-icon-wrapper">
										<span v-if="tool.icon" :class="tool.icon" class="tool-icon" />
										<span v-else class="tool-icon icon-category-office" />
									</div>
									<div class="tool-content">
										<div class="tool-header">
											<div class="tool-title">
												<strong>{{ tool.name }}</strong>
												<span v-if="tool.app" class="tool-app-badge">{{ tool.app }}</span>
											</div>
											<div class="tool-toggle" @click.stop>
												<NcCheckboxRadioSwitch
													:key="`toggle-${tool.id}-${isToolChecked(tool.id)}`"
													:checked="isToolChecked(tool.id)"
													type="switch"
													@update:checked="handleToggleChange(tool.id, $event)" />
											</div>
										</div>
										<p class="tool-description">{{ tool.description }}</p>
									</div>
								</div>
							</div>
						</div>

						<NcNoteCard v-else type="warning">
							<p>No tools available. Tools can be registered by installed apps.</p>
						</NcNoteCard>

						<NcNoteCard v-if="agentItem.tools && agentItem.tools.length > 0" type="warning">
							<p><strong>Note:</strong> Tools execute with the agent's default user permissions when no user session is active (e.g., cron jobs). Configure the default user in the Settings tab.</p>
						</NcNoteCard>
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
				tools: [],
				user: '',
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
			loadingTools: false,
			availableTools: [],
			loadingUsers: false,
			availableUsers: [],
			selectedUser: null,
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
		this.fetchTools()
		this.fetchUsers()
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
				if (this.agentItem.user) {
					// Will be populated after fetchUsers completes
					this.selectedUser = this.availableUsers.find(u => u.id === this.agentItem.user) || null
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
					tools: [],
					user: '',
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
		async fetchTools() {
			this.loadingTools = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/agents/tools', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				const data = await response.json()
				if (data && data.results) {
					// Convert from object with IDs as keys to array with IDs
					this.availableTools = Object.entries(data.results).map(([id, metadata]) => ({
						id,
						...metadata,
					}))
				}
			} catch (error) {
				console.error('Failed to fetch tools:', error)
			} finally {
				this.loadingTools = false
			}
		},
		async fetchUsers() {
			this.loadingUsers = true
			try {
				// Get current organisation
				const orgResponse = await fetch('/index.php/apps/openregister/api/organisations/active', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})
				const orgData = await orgResponse.json()
				
				if (!orgData || !orgData.users || !Array.isArray(orgData.users)) {
					// Fallback: get all users if organisation doesn't have specific users
					const usersResponse = await fetch('/ocs/v2.php/cloud/users', {
						headers: {
							'OCS-APIRequest': 'true',
							Accept: 'application/json',
						},
					})
					const usersData = await usersResponse.json()
					
					if (usersData.ocs && usersData.ocs.data && usersData.ocs.data.users) {
						// Fetch details for each user
						const userDetails = await Promise.all(
							usersData.ocs.data.users.map(async (userId) => {
								try {
									const detailResponse = await fetch(`/ocs/v2.php/cloud/users/${userId}`, {
										headers: {
											'OCS-APIRequest': 'true',
											Accept: 'application/json',
										},
									})
									const detailData = await detailResponse.json()
									return {
										id: userId,
										displayName: detailData.ocs?.data?.displayname || userId,
										email: detailData.ocs?.data?.email || '',
									}
								} catch (err) {
									return { id: userId, displayName: userId, email: '' }
								}
							})
						)
						this.availableUsers = userDetails
					}
				} else {
					// Use organisation users
					this.availableUsers = orgData.users.map(userId => ({
						id: userId,
						displayName: userId, // Could be enhanced with full user details
						email: '',
					}))
				}
				
				// Update selectedUser if agentItem.user is set
				if (this.agentItem.user) {
					this.selectedUser = this.availableUsers.find(u => u.id === this.agentItem.user) || null
				}
			} catch (error) {
				console.error('Error fetching users:', error)
			} finally {
				this.loadingUsers = false
			}
		},
		updateUser(selectedUser) {
			this.agentItem.user = selectedUser ? selectedUser.id : ''
		},
		handleCardClick(toolId, event) {
			// Card click handler - toggle the tool
			const currentState = this.isToolChecked(toolId)
			this.toggleTool(toolId, !currentState)
		},
		handleToggleChange(toolId, newValue) {
			// Toggle change handler - called when toggle is clicked directly
			this.toggleTool(toolId, newValue)
		},
		toggleTool(toolId, enabled) {
			if (!this.agentItem.tools) {
				this.agentItem.tools = []
			}
			// Support both old format (e.g., 'register') and new format (e.g., 'openregister.register')
			// Normalize to new format
			const normalizedId = toolId.includes('.') ? toolId : `openregister.${toolId}`
			
			if (enabled) {
				// Check if not already present
				if (!this.agentItem.tools.includes(normalizedId) && !this.agentItem.tools.includes(toolId)) {
					// Create new array to trigger Vue reactivity
					this.agentItem.tools = [...this.agentItem.tools, normalizedId]
				}
			} else {
				// Create new array to trigger Vue reactivity
				this.agentItem.tools = this.agentItem.tools.filter(t => t !== normalizedId && t !== toolId)
			}
			
			// Force Vue to detect the change by updating the reference
			this.$set(this.agentItem, 'tools', [...this.agentItem.tools])
		},
		isToolChecked(toolId) {
			if (!this.agentItem.tools) {
				return false
			}
			// Support both formats for backward compatibility
			const legacyId = toolId.split('.').pop() // Extract 'register' from 'openregister.register'
			return this.agentItem.tools.includes(toolId) || this.agentItem.tools.includes(legacyId)
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
					
					// Synchronize selectedGroups after availableGroups is loaded
					if (this.agentItem.groups && Array.isArray(this.agentItem.groups)) {
						this.selectedGroups = this.availableGroups.filter(g => this.agentItem.groups.includes(g.id))
					}
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
				
				// Synchronize selectedViews after availableViews is loaded
				if (this.agentItem.views && Array.isArray(this.agentItem.views)) {
					this.selectedViews = this.availableViews.filter(v => this.agentItem.views.includes(v.id))
				}
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

.tools-selection {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.tool-item {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	transition: all 0.2s ease;
	cursor: pointer;
}

.tool-item:hover {
	background-color: var(--color-background-hover);
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.tool-row {
	display: flex;
	gap: 20px;
	align-items: center;
}

.tool-icon-wrapper {
	width: 64px;
	height: 64px;
	min-width: 64px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary-element-light);
	border-radius: 12px;
	padding: 4px;
}

.tool-icon {
	font-size: 56px;
	line-height: 1;
	color: var(--color-primary-element);
	display: block;
}

.tool-content {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.tool-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}

.tool-title {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1;
	min-width: 0;
}

.tool-title strong {
	font-size: 1.1rem;
	color: var(--color-main-text);
}

.tool-toggle {
	display: flex;
	align-items: center;
	cursor: pointer;
}

.tool-app-badge {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.tool-description {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.5;
	padding-left: 0;
}
</style>

