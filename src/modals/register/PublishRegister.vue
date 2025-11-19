<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'publishRegister'"
		name="publishRegister"
		title="Publish Register OAS to GitHub"
		size="large"
		:can-close="!loading"
		@update:open="closeModal">
		
		<NcNoteCard v-if="success" type="success">
			<p v-for="(line, index) in successMessage.split('\n')" :key="index">{{ line }}</p>
		</NcNoteCard>
		
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		
		<div v-if="register" class="publishForm">
			<div class="formRow">
				<div class="formSection formSection--inline formSection--register">
					<h3>{{ t('openregister', 'Register') }}</h3>
					<p class="formDescription">{{ register.title }}</p>
				</div>
				
				<div class="formSection formSection--inline">
					<h3>{{ t('openregister', 'Repository') }}</h3>
					<NcLoadingIcon v-if="loadingRepositories" :size="32" />
					<NcSelect
						v-else
						v-model="selectedRepository"
						:options="repositoryOptions"
						label="label"
						track-by="value"
						:placeholder="t('openregister', 'Select a repository')"
						:disabled="loading"
						:label-outside="true"
						aria-label-combobox="Repository selection" />
					<p class="formHint">{{ t('openregister', 'Select a repository you have write access to') }}</p>
				</div>
				
				<div v-if="selectedRepository" class="formSection formSection--inline">
					<h3>{{ t('openregister', 'Branch') }}</h3>
					<NcLoadingIcon v-if="loadingBranches" :size="32" />
					<NcSelect
						v-else
						v-model="selectedBranch"
						:options="branchOptions"
						label="label"
						track-by="value"
						:placeholder="t('openregister', 'Select a branch')"
						:disabled="loading"
						:label-outside="true"
						aria-label-combobox="Branch selection" />
					<p class="formHint">{{ t('openregister', 'Select the branch to publish to') }}</p>
				</div>
			</div>
			
			<div v-if="selectedBranch" class="formSection">
				<h3>{{ t('openregister', 'File Path') }}</h3>
				<NcTextField
					v-model="filePath"
					:placeholder="t('openregister', 'e.g., lib/Settings/register.json')"
					:disabled="loading"
					:label="t('openregister', 'Path in repository')" />
				<p class="formHint">{{ t('openregister', 'Path where the register OAS file will be saved in the repository') }}</p>
			</div>
			
			<div v-if="filePath" class="formSection">
				<h3>{{ t('openregister', 'Commit Message') }}</h3>
				<NcTextField
					v-model="commitMessage"
					:placeholder="t('openregister', 'Update register OAS: ...')"
					:disabled="loading"
					:label="t('openregister', 'Commit message')" />
			</div>
			
			<div class="formActions">
				<NcButton
					type="primary"
					:disabled="!canPublish || loading"
					@click="publishRegister">
					<template #icon>
						<CloudUploadOutline :size="20" />
					</template>
					{{ loading ? t('openregister', 'Publishing...') : t('openregister', 'Publish') }}
				</NcButton>
				<NcButton
					:disabled="loading"
					@click="closeModal">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcSelect, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import { registerStore, navigationStore } from '../../store/store.js'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'

export default {
	name: 'PublishRegister',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcNoteCard,
		NcLoadingIcon,
		CloudUploadOutline,
	},
	data() {
		return {
			loading: false,
			error: null,
			success: false,
			successMessage: '',
			
			// Form fields
			selectedRepository: null,
			selectedBranch: null,
			filePath: '',
			commitMessage: '',
			
			// Data
			repositories: [],
			branches: [],
			loadingRepositories: true, // Start as true to show loading until repos are loaded
			loadingBranches: false,
		}
	},
	computed: {
		register() {
			return registerStore.registerItem
		},
		canPublish() {
			return this.selectedRepository && this.selectedBranch && this.filePath.trim() !== ''
		},
		repositoryOptions() {
			return this.repositories.map(repo => ({
				value: repo.full_name,
				label: `${repo.full_name}${repo.private ? ' (Private)' : ''}`,
				...repo
			}))
		},
		branchOptions() {
			return this.branches.map(branch => ({
				value: branch.name,
				label: branch.name,
				...branch
			}))
		},
	},
	watch: {
		selectedRepository(newValue) {
			if (newValue) {
				// Extract value if it's an object, otherwise use the value directly
				const repoValue = typeof newValue === 'object' ? (newValue.value || newValue.full_name) : newValue
				this.onRepositoryChange(repoValue)
			} else {
				// Clear branches when repository is cleared
				this.branches = []
				this.selectedBranch = null
			}
		},
	},
	async mounted() {
		await this.loadRepositories()
		
		// Set default commit message and file path
		if (this.register) {
			this.commitMessage = `Update register OAS: ${this.register.title}`
			// Default filename based on register slug
			if (!this.filePath && this.register.slug) {
				this.filePath = `${this.register.slug}_openregister.json`
			}
		}
	},
	methods: {
		closeModal() {
			if (!this.loading) {
				navigationStore.setModal(null)
				// Note: Component will be destroyed when modal closes (due to v-if),
				// so we don't need to clear data here - it will be reset on next mount
			}
		},
		async loadRepositories() {
			this.loadingRepositories = true
			this.error = null
			
			try {
				const response = await fetch('/index.php/apps/openregister/api/configurations/github/repositories', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})
				
				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || 'Failed to load repositories')
				}
				
				const data = await response.json()
				this.repositories = data.repositories || []
			} catch (e) {
				this.error = e.message || 'Failed to load repositories'
				console.error('Failed to load repositories:', e)
			} finally {
				this.loadingRepositories = false
			}
		},
		async loadBranches(owner, repo) {
			if (!owner || !repo) return
			
			this.loadingBranches = true
			this.error = null
			
			try {
				const response = await fetch(`/index.php/apps/openregister/api/configurations/github/branches?owner=${encodeURIComponent(owner)}&repo=${encodeURIComponent(repo)}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})
				
				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || 'Failed to load branches')
				}
				
				const data = await response.json()
				this.branches = data.branches || []
				
				// Select default branch if available
				if (this.branches.length > 0 && !this.selectedBranch) {
					const defaultBranch = this.branches.find(b => b.name === 'main') || this.branches.find(b => b.name === 'master') || this.branches[0]
					if (defaultBranch) {
						this.selectedBranch = defaultBranch.name
					}
				}
			} catch (e) {
				this.error = e.message || 'Failed to load branches'
				console.error('Failed to load branches:', e)
			} finally {
				this.loadingBranches = false
			}
		},
		onRepositoryChange(value) {
			// Clear branches and selected branch when repository changes
			this.branches = []
			this.selectedBranch = null
			
			if (value) {
				// Handle both string (full_name) and object cases
				const repoFullName = typeof value === 'string' ? value : value.value || value.full_name
				
				const repo = this.repositories.find(r => r.full_name === repoFullName)
				if (repo) {
					this.loadBranches(repo.owner, repo.name)
				}
			}
		},
		async publishRegister() {
			if (!this.canPublish || !this.register) return
			
			this.loading = true
			this.error = null
			this.success = false
			
			try {
				// Extract repository value (handle both object and string)
				const repoValue = typeof this.selectedRepository === 'object' 
					? (this.selectedRepository.value || this.selectedRepository.full_name)
					: this.selectedRepository
				
				if (!repoValue) {
					throw new Error('Repository not selected')
				}
				
				const repo = this.repositories.find(r => r.full_name === repoValue)
				if (!repo) {
					throw new Error('Repository not found')
				}
				
				const [owner, repoName] = repoValue.split('/')
				
				// Extract branch value (handle both object and string)
				const branchValue = typeof this.selectedBranch === 'object'
					? (this.selectedBranch.value || this.selectedBranch.name)
					: this.selectedBranch
				
				if (!branchValue) {
					throw new Error('Branch not selected')
				}
				
				const response = await fetch(`/index.php/apps/openregister/api/registers/${this.register.id}/publish/github`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						owner,
						repo: repoName,
						path: this.filePath.trim(),
						branch: branchValue,
						commitMessage: this.commitMessage || `Update register OAS: ${this.register.title}`,
					}),
				})
				
				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || 'Failed to publish register OAS')
				}
				
				const data = await response.json()
				
				this.success = true
				let message = `Register OAS published successfully! Commit: ${data.commit_sha?.substring(0, 7) || 'N/A'}`
				if (data.indexing_note) {
					message += `\n\n${data.indexing_note}`
				}
				this.successMessage = message
				
				// Refresh register list
				await registerStore.refreshRegisterList()
				
				// Close modal after 4 seconds (longer to read the indexing note)
				setTimeout(() => {
					this.closeModal()
				}, 4000)
			} catch (e) {
				this.error = e.message || 'Failed to publish register OAS'
				console.error('Failed to publish register OAS:', e)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.publishForm {
	padding: 20px;
}

.formRow {
	display: flex;
	gap: 24px;
	margin-bottom: 24px;
	align-items: flex-start;
}

.formSection {
	margin-bottom: 24px;
}

.formSection--inline {
	flex: 1;
	margin-bottom: 0;
	min-width: 0; /* Allow flex items to shrink below content size */
}

.formSection--register {
	flex: 0 0 auto; /* Register name doesn't need to grow */
	min-width: 150px; /* But give it a minimum width */
	max-width: 250px; /* Limit maximum width */
}

.formSection h3 {
	margin-bottom: 8px;
	font-weight: 600;
	font-size: 1rem;
}

.formDescription {
	color: var(--color-text-lighter);
	margin-bottom: 16px;
}

.formHint {
	margin-top: 8px;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.formActions {
	display: flex;
	gap: 12px;
	justify-content: flex-end;
	margin-top: 32px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}
</style>

