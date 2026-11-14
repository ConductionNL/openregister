<template>
	<NcDialog
		v-if="navigationStore.modal === modalName"
		:name="t('openregister', 'Publish Configuration to GitLab')"
		size="normal"
		:noClose="loading"
		@update:open="closeModal">
		<NcNoteCard v-if="successMessage" type="success">
			<p>{{ successMessage }}</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="configuration" class="publishForm">
			<h3>{{ t('openregister', 'Configuration') }}</h3>
			<p class="formDescription">
				{{ configuration.title }}
			</p>

			<NcTextField
				v-model="repository"
				:label="t('openregister', 'Repository')"
				placeholder="group/project"
				:disabled="loading" />
			<NcTextField
				v-model="branch"
				:label="t('openregister', 'Branch')"
				placeholder="main"
				:disabled="loading" />
			<NcTextField
				v-model="filePath"
				:label="t('openregister', 'Path in repository')"
				placeholder="lib/settings/config.json"
				:disabled="loading" />
			<NcTextField
				v-model="commitMessage"
				:label="t('openregister', 'Commit message')"
				:disabled="loading" />
		</div>

		<template #actions>
			<NcButton :disabled="loading" @click="closeModal">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="loading || !canPublish"
				@click="publish">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
				</template>
				{{ t('openregister', 'Publish') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { jsonOrThrow } from '../../services/githubPublish.js'
import { gitlabProject, gitlabPublishBody } from '../../services/gitlabPublish.js'
import { withRequestToken } from '../../services/requestToken.js'
import { configurationStore, navigationStore } from '../../store/store.js'

/**
 * Publishes the selected local configuration as a file in a GitLab project.
 *
 * @spec openspec/changes/capability-rows-verified-b/tasks.md#task-2.1
 */
export default {
	name: 'PublishToGitlab',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** The modal name that opens this dialog. */
		modalName: {
			type: String,
			default: 'publishConfigurationGitLab',
		},
	},

	data() {
		return {
			navigationStore,
			repository: '',
			branch: 'main',
			filePath: '',
			commitMessage: '',
			loading: false,
			error: null,
			successMessage: '',
		}
	},

	computed: {
		/**
		 * @spec exclude UI accessor — the configuration being published.
		 */
		configuration() {
			return configurationStore.configurationItem
		},

		/**
		 * @spec openspec/changes/capability-rows-verified-b/tasks.md#task-2.1
		 */
		canPublish() {
			return Boolean(this.configuration?.id) && gitlabProject(this.repository) !== null
		},
	},

	methods: {
		t,

		/**
		 * Publish the configuration to the GitLab project, then refresh the list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-verified-b/tasks.md#task-2.1
		 */
		async publish() {
			if (!this.canPublish) {
				return
			}
			this.loading = true
			this.error = null
			this.successMessage = ''
			try {
				const response = await fetch(
					`/index.php/apps/openregister/api/configurations/${this.configuration.id}/publish/gitlab`,
					withRequestToken({
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(gitlabPublishBody(this)),
					}),
				)
				const data = await jsonOrThrow(response, 'Failed to publish configuration')
				this.successMessage = data.file_url
					? `${data.message} ${data.file_url}`
					: data.message
				await configurationStore.refreshConfigurationList()
			} catch (e) {
				this.error = e.message || 'Failed to publish configuration'
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec exclude Modal plumbing — closes the dialog and clears its state.
		 */
		closeModal() {
			if (this.loading) {
				return
			}
			navigationStore.setModal(null)
			this.error = null
			this.successMessage = ''
		},
	},
}
</script>

<style scoped>
.publishForm {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.formDescription {
	color: var(--color-text-maxcontrast);
}
</style>
