<template>
	<NcDialog
		v-if="navigationStore.modal === modalName"
		:name="modalName"
		:title="copy.title"
		size="large"
		:canClose="!loading"
		@update:open="closeModal">
		<NcNoteCard v-if="success" type="success">
			<p v-for="(line, index) in successMessage.split('\n')" :key="index">
				{{ line }}
			</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="entity" class="publishForm">
			<div class="formRow">
				<div class="formSection formSection--inline" :class="sectionClass">
					<h3>{{ copy.entityHeading }}</h3>
					<p class="formDescription">
						{{ entity.title }}
					</p>
				</div>

				<div class="formSection formSection--inline">
					<h3>{{ t('openregister', 'Repository') }}</h3>
					<NcLoadingIcon v-if="loadingRepositories" :size="32" />
					<NcSelect
						v-else
						v-model="selectedRepository"
						:options="repositoryOptions"
						label="label"
						trackBy="value"
						:placeholder="t('openregister', 'Select a repository')"
						:disabled="loading"
						:labelOutside="true"
						aria-label-combobox="Repository selection" />
					<p class="formHint">
						{{
							t(
								'openregister',
								'Select a repository you have write access to',
							)
						}}
					</p>
				</div>

				<div
					v-if="selectedRepository"
					class="formSection formSection--inline">
					<h3>{{ t('openregister', 'Branch') }}</h3>
					<NcLoadingIcon v-if="loadingBranches" :size="32" />
					<NcSelect
						v-else
						v-model="selectedBranch"
						:options="branchOptions"
						label="label"
						trackBy="value"
						:placeholder="t('openregister', 'Select a branch')"
						:disabled="loading"
						:labelOutside="true"
						aria-label-combobox="Branch selection" />
					<p class="formHint">
						{{ t('openregister', 'Select the branch to publish to') }}
					</p>
				</div>
			</div>

			<div v-if="selectedBranch" class="formSection">
				<h3>{{ t('openregister', 'File Path') }}</h3>
				<NcTextField
					v-model="filePath"
					:placeholder="copy.filePathPlaceholder"
					:disabled="loading"
					:label="t('openregister', 'Path in repository')" />
				<p class="formHint">
					{{ copy.filePathHint }}
				</p>
			</div>

			<div v-if="filePath" class="formSection">
				<h3>{{ t('openregister', 'Commit Message') }}</h3>
				<NcTextField
					v-model="commitMessage"
					:placeholder="copy.commitPlaceholder"
					:disabled="loading"
					:label="t('openregister', 'Commit message')" />
			</div>

			<div class="formActions">
				<NcButton
					variant="primary"
					:disabled="!canPublish || loading"
					@click="publish">
					<template #icon>
						<CloudUploadOutline :size="20" />
					</template>
					{{
						loading
							? t('openregister', 'Publishing...')
							: t('openregister', 'Publish')
					}}
				</NcButton>
				<NcButton :disabled="loading" @click="closeModal">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import { navigationStore } from '../../store/store.js'

export default {
	name: 'PublishToGithub',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcNoteCard,
		NcLoadingIcon,
		CloudUploadOutline,
	},

	props: {
		/**
		 * The navigationStore modal key that opens this form.
		 */
		modalName: {
			type: String,
			required: true,
		},

		/**
		 * The entity to publish: it needs an `id` and a `title`.
		 */
		entity: {
			type: Object,
			default: null,
		},

		/**
		 * The API collection the entity is published from, as in `/api/{entityPath}/{id}/publish/github`.
		 */
		entityPath: {
			type: String,
			required: true,
		},

		/**
		 * The translated texts: title, entityHeading, filePathPlaceholder, filePathHint, commitPlaceholder,
		 * and the untranslated commitPrefix, publishedMessage and failureMessage.
		 */
		copy: {
			type: Object,
			required: true,
		},

		/**
		 * The modifier class of the entity section.
		 */
		sectionClass: {
			type: String,
			default: '',
		},

		/**
		 * The file path filled in when the form opens with none.
		 */
		defaultFilePath: {
			type: String,
			default: '',
		},

		/**
		 * Select the repository, branch and path the entity was last published to.
		 */
		preselectPublished: {
			type: Boolean,
			default: false,
		},

		/**
		 * Refreshes the entity's list after a publish.
		 */
		refresh: {
			type: Function,
			required: true,
		},
	},

	/**
	 * @spec exclude Vue setup hook — exposes navigationStore to the template.
	 */
	setup() {
		return { navigationStore }
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
		/**
		 * @spec exclude UI state helper — enables the publish button when repo/branch/path are set.
		 */
		canPublish() {
			return (
				this.selectedRepository
				&& this.selectedBranch
				&& this.filePath.trim() !== ''
			)
		},

		/**
		 * @spec exclude UI display helper — maps repositories to NcSelect option objects.
		 */
		repositoryOptions() {
			return this.repositories.map((repo) => ({
				value: repo.full_name,
				label: `${repo.full_name}${repo.private ? ' (Private)' : ''}`,
				...repo,
			}))
		},

		/**
		 * @spec exclude UI display helper — maps branches to NcSelect option objects.
		 */
		branchOptions() {
			return this.branches.map((branch) => ({
				value: branch.name,
				label: branch.name,
				...branch,
			}))
		},
	},

	watch: {
		/**
		 * @param {string|object|null} newValue The selected repository
		 * @spec exclude UI watcher — reloads branches when the selected repository changes.
		 */
		selectedRepository(newValue) {
			if (newValue) {
				// Extract value if it's an object, otherwise use the value directly
				const repoValue =
					typeof newValue === 'object'
						? newValue.value || newValue.full_name
						: newValue
				this.onRepositoryChange(repoValue)
			} else {
				// Clear branches when repository is cleared
				this.branches = []
				this.selectedBranch = null
			}
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook — loads repositories and sets the default commit message and path.
	 */
	async mounted() {
		await this.loadRepositories()

		if (this.entity) {
			this.commitMessage = `${this.copy.commitPrefix}${this.entity.title}`
			if (!this.filePath && this.defaultFilePath) {
				this.filePath = this.defaultFilePath
			}
		}
	},

	methods: {
		t,

		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		closeModal() {
			if (!this.loading) {
				navigationStore.setModal(null)
				// Note: Component will be destroyed when modal closes (due to v-if),
				// so we don't need to clear data here - it will be reset on next mount
			}
		},

		/**
		 * @spec exclude Modal data-load plumbing — fetches GitHub repositories for the picker.
		 */
		async loadRepositories() {
			this.loadingRepositories = true
			this.error = null

			try {
				const response = await fetch(
					'/index.php/apps/openregister/api/configurations/github/repositories',
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
						},
					},
				)

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || 'Failed to load repositories')
				}

				const data = await response.json()
				this.repositories = data.repositories || []

				// Pre-select the GitHub repo the entity was already published to
				if (this.preselectPublished && this.entity?.githubRepo) {
					const existingRepo = this.repositories.find(
						(r) => r.full_name === this.entity.githubRepo,
					)
					if (existingRepo) {
						this.selectedRepository = existingRepo.full_name
						// Load branches for this repo
						await this.loadBranches(
							existingRepo.owner,
							existingRepo.name,
						)
						// Pre-select existing branch
						if (this.entity?.githubBranch) {
							this.selectedBranch = this.entity.githubBranch
						}
						// Pre-fill path
						if (this.entity?.githubPath) {
							this.filePath = this.entity.githubPath
						}
					}
				}
			} catch (e) {
				this.error = e.message || 'Failed to load repositories'
				console.error('Failed to load repositories:', e)
			} finally {
				this.loadingRepositories = false
			}
		},

		/**
		 * @param {string} owner The repository owner
		 * @param {string} repo The repository name
		 * @spec exclude Modal data-load plumbing — fetches branches for the selected repository.
		 */
		async loadBranches(owner, repo) {
			if (!owner || !repo) return

			this.loadingBranches = true
			this.error = null

			try {
				const response = await fetch(
					`/index.php/apps/openregister/api/configurations/github/branches?owner=${encodeURIComponent(owner)}&repo=${encodeURIComponent(repo)}`,
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
						},
					},
				)

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || 'Failed to load branches')
				}

				const data = await response.json()
				this.branches = data.branches || []

				// Select default branch if available
				if (this.branches.length > 0 && !this.selectedBranch) {
					const defaultBranch =
						this.branches.find((b) => b.name === 'main')
						|| this.branches.find((b) => b.name === 'master')
						|| this.branches[0]
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

		/**
		 * @param {string|object|null} value The selected repository
		 * @spec exclude UI event handler — reloads branches when repository selection changes.
		 */
		onRepositoryChange(value) {
			// Clear branches and selected branch when repository changes
			this.branches = []
			this.selectedBranch = null

			if (value) {
				// Handle both string (full_name) and object cases
				const repoFullName =
					typeof value === 'string'
						? value
						: value.value || value.full_name

				const repo = this.repositories.find(
					(r) => r.full_name === repoFullName,
				)
				if (repo) {
					this.loadBranches(repo.owner, repo.name)
				}
			}
		},

		/**
		 * @spec exclude Modal action plumbing — pushes the entity to the selected GitHub repo/branch.
		 */
		async publish() {
			if (!this.canPublish || !this.entity) return

			this.loading = true
			this.error = null
			this.success = false

			try {
				// Extract repository value (handle both object and string)
				const repoValue =
					typeof this.selectedRepository === 'object'
						? this.selectedRepository.value
							|| this.selectedRepository.full_name
						: this.selectedRepository

				if (!repoValue) {
					throw new Error('Repository not selected')
				}

				const repo = this.repositories.find((r) => r.full_name === repoValue)
				if (!repo) {
					throw new Error('Repository not found')
				}

				const [owner, repoName] = repoValue.split('/')

				// Extract branch value (handle both object and string)
				const branchValue =
					typeof this.selectedBranch === 'object'
						? this.selectedBranch.value || this.selectedBranch.name
						: this.selectedBranch

				if (!branchValue) {
					throw new Error('Branch not selected')
				}

				const response = await fetch(
					`/index.php/apps/openregister/api/${this.entityPath}/${this.entity.id}/publish/github`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							owner,
							repo: repoName,
							path: this.filePath.trim(),
							branch: branchValue,
							commitMessage:
								this.commitMessage
								|| `${this.copy.commitPrefix}${this.entity.title}`,
						}),
					},
				)

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || this.copy.failureMessage)
				}

				const data = await response.json()

				this.success = true
				let message = `${this.copy.publishedMessage} Commit: ${data.commit_sha?.substring(0, 7) || 'N/A'}`
				if (data.indexing_note) {
					message += `\n\n${data.indexing_note}`
				}
				this.successMessage = message

				await this.refresh()

				// Close modal after 4 seconds (longer to read the indexing note)
				setTimeout(() => {
					this.closeModal()
				}, 4000)
			} catch (e) {
				this.error = e.message || this.copy.failureMessage
				console.error(`${this.copy.failureMessage}:`, e)
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

.formSection--register,
.formSection--config {
	flex: 0 0 auto; /* The entity name doesn't need to grow */
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
