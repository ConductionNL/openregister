<template>
	<NcSettingsSection
		:name="t('openregister', 'API Token Configuration')"
		:description="t('openregister', 'Configure API tokens for external service integrations')">
		<div class="settings-section">
			<!-- Description -->
			<NcNoteCard type="info">
				<p>
					{{ t('openregister', 'API tokens are used to discover and import OpenRegister configurations from GitHub and GitLab repositories. These tokens enable the "Discover" feature in the Configuration Import dialog.') }}
				</p>
				<p class="mt-2">
					<strong>{{ t('openregister', 'Required Scopes:') }}</strong>
				</p>
				<ul>
					<li><strong>GitHub:</strong> {{ t('openregister', 'repo (for code search API access)') }}</li>
					<li><strong>GitLab:</strong> {{ t('openregister', 'read_api (for global search access)') }}</li>
				</ul>
				<p class="mt-2">
					<a href="https://docs.openregister.nl/user-guide/configuration/api-tokens" target="_blank" rel="noopener noreferrer">
						{{ t('openregister', 'View detailed documentation on obtaining API tokens') }} →
					</a>
				</p>
			</NcNoteCard>

			<!-- GitHub Token -->
			<div class="token-field">
				<div class="token-header">
					<Github :size="24" />
					<h3>{{ t('openregister', 'GitHub Personal Access Token') }}</h3>
				</div>
				<NcPasswordField
					:value.sync="githubToken"
					:label="t('openregister', 'GitHub Token')"
					:placeholder="t('openregister', 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')"
					:helper-text="t('openregister', 'Optional: Required only for discovering configurations from GitHub')"
					autocomplete="off"
					@update:value="updateGitHubToken">
					<Github :size="20" />
				</NcPasswordField>
				<NcButton
					v-if="githubToken && githubToken !== originalGithubToken"
					type="primary"
					:disabled="saving"
					@click="saveGitHubToken">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('openregister', 'Save GitHub Token') }}
				</NcButton>
				<NcButton
					v-if="githubToken"
					type="error"
					:disabled="saving"
					@click="clearGitHubToken">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('openregister', 'Clear Token') }}
				</NcButton>
			</div>

			<!-- GitLab Token -->
			<div class="token-field">
				<div class="token-header">
					<Gitlab :size="24" />
					<h3>{{ t('openregister', 'GitLab Personal Access Token') }}</h3>
				</div>
				<NcPasswordField
					:value.sync="gitlabToken"
					:label="t('openregister', 'GitLab Token')"
					:placeholder="t('openregister', 'glpat-xxxxxxxxxxxxxxxxxxxx')"
					:helper-text="t('openregister', 'Optional: Required only for discovering configurations from GitLab')"
					autocomplete="off"
					@update:value="updateGitLabToken">
					<Gitlab :size="20" />
				</NcPasswordField>
				<NcButton
					v-if="gitlabToken && gitlabToken !== originalGitlabToken"
					type="primary"
					:disabled="saving"
					@click="saveGitLabToken">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('openregister', 'Save GitLab Token') }}
				</NcButton>
				<NcButton
					v-if="gitlabToken"
					type="error"
					:disabled="saving"
					@click="clearGitLabToken">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('openregister', 'Clear Token') }}
				</NcButton>
			</div>

			<!-- GitLab Instance URL (for self-hosted) -->
			<div class="token-field">
				<h3>{{ t('openregister', 'GitLab Instance URL (Optional)') }}</h3>
				<NcTextField
					:value.sync="gitlabUrl"
					:label="t('openregister', 'GitLab API Base URL')"
					:placeholder="t('openregister', 'https://gitlab.com/api/v4')"
					:helper-text="t('openregister', 'Leave empty to use GitLab.com. For self-hosted instances, enter your GitLab API URL')"
					@update:value="updateGitLabUrl">
					<Web :size="20" />
				</NcTextField>
				<NcButton
					v-if="gitlabUrl && gitlabUrl !== originalGitlabUrl"
					type="primary"
					:disabled="saving"
					@click="saveGitLabUrl">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('openregister', 'Save GitLab URL') }}
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcNoteCard, NcPasswordField, NcTextField, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Github from 'vue-material-design-icons/Github.vue'
import Gitlab from 'vue-material-design-icons/Gitlab.vue'
import Web from 'vue-material-design-icons/Web.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

/**
 * API Token Configuration Component
 *
 * Manages GitHub and GitLab API tokens for configuration discovery
 *
 * @category Settings
 * @package  OCA\OpenRegister\Views\Settings
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git_id>
 *
 * @link     https://www.OpenRegister.nl
 */
export default {
	name: 'ApiTokenConfiguration',

	components: {
		NcSettingsSection,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		Github,
		Gitlab,
		Web,
		ContentSave,
		Delete,
	},

	data() {
		return {
			loading: false,
			saving: false,
			githubToken: '',
			gitlabToken: '',
			gitlabUrl: '',
			originalGithubToken: '',
			originalGitlabToken: '',
			originalGitlabUrl: '',
		}
	},

	async mounted() {
		await this.loadTokens()
	},

	methods: {
		/**
		 * Load existing API tokens from the backend
		 *
		 * @return {Promise<void>}
		 */
		async loadTokens() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/api-tokens'))
				this.githubToken = response.data.github_token || ''
				this.gitlabToken = response.data.gitlab_token || ''
				this.gitlabUrl = response.data.gitlab_url || ''
				this.originalGithubToken = this.githubToken
				this.originalGitlabToken = this.gitlabToken
				this.originalGitlabUrl = this.gitlabUrl
			} catch (error) {
				console.error('Failed to load API tokens:', error)
				// Don't show error on initial load as tokens might not be set
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update GitHub token value
		 *
		 * @param {string} value New token value
		 * @return {void}
		 */
		updateGitHubToken(value) {
			this.githubToken = value
		},

		/**
		 * Update GitLab token value
		 *
		 * @param {string} value New token value
		 * @return {void}
		 */
		updateGitLabToken(value) {
			this.gitlabToken = value
		},

		/**
		 * Update GitLab URL value
		 *
		 * @param {string} value New URL value
		 * @return {void}
		 */
		updateGitLabUrl(value) {
			this.gitlabUrl = value
		},

		/**
		 * Save GitHub token to the backend
		 *
		 * @return {Promise<void>}
		 */
		async saveGitHubToken() {
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/openregister/api/settings/api-tokens'), {
					github_token: this.githubToken,
				})
				this.originalGithubToken = this.githubToken
				showSuccess(this.t('openregister', 'GitHub token saved successfully'))
			} catch (error) {
				console.error('Failed to save GitHub token:', error)
				showError(this.t('openregister', 'Failed to save GitHub token'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Save GitLab token to the backend
		 *
		 * @return {Promise<void>}
		 */
		async saveGitLabToken() {
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/openregister/api/settings/api-tokens'), {
					gitlab_token: this.gitlabToken,
				})
				this.originalGitlabToken = this.gitlabToken
				showSuccess(this.t('openregister', 'GitLab token saved successfully'))
			} catch (error) {
				console.error('Failed to save GitLab token:', error)
				showError(this.t('openregister', 'Failed to save GitLab token'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Save GitLab URL to the backend
		 *
		 * @return {Promise<void>}
		 */
		async saveGitLabUrl() {
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/openregister/api/settings/api-tokens'), {
					gitlab_url: this.gitlabUrl,
				})
				this.originalGitlabUrl = this.gitlabUrl
				showSuccess(this.t('openregister', 'GitLab URL saved successfully'))
			} catch (error) {
				console.error('Failed to save GitLab URL:', error)
				showError(this.t('openregister', 'Failed to save GitLab URL'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Clear GitHub token
		 *
		 * @return {Promise<void>}
		 */
		async clearGitHubToken() {
			this.githubToken = ''
			await this.saveGitHubToken()
		},

		/**
		 * Clear GitLab token
		 *
		 * @return {Promise<void>}
		 */
		async clearGitLabToken() {
			this.gitlabToken = ''
			await this.saveGitLabToken()
		},
	},
}
</script>

<style scoped>
.settings-section {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 16px 0;
}

.token-field {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-background-hover);
}

.token-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.token-header h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: 600;
}

.mt-2 {
	margin-top: 8px;
}

ul {
	margin: 8px 0;
	padding-left: 20px;
}

ul li {
	margin: 4px 0;
}
</style>

