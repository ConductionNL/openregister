<template>
	<SettingsSection
		id="api-tokens"
		name="API Token Configuration"
		description="Configure API tokens for external service integrations"
		:loading="loading"
		loading-message="Loading API tokens...">
		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				API tokens enable <strong>discovering, importing, and publishing OpenRegister configurations</strong> to GitHub and GitLab repositories.
				With the appropriate token scopes, you can search for configurations published by the community, import them into your system,
				and publish your own configurations back to repositories for sharing and version control.
			</p>
			<p class="main-description info-note">
				<strong>🔐 Note:</strong> Tokens are <strong>optional</strong> for basic workflows. You can import configurations manually using direct URLs
				without tokens. However, tokens with appropriate scopes are required for:
				<strong>(1)</strong> Discovery/search features, and
				<strong>(2)</strong> Publishing configurations to repositories.
			</p>
		</div>

		<!-- Required Scopes Info -->
		<SettingsCard
			title="Required Token Scopes"
			icon="📋"
			:collapsible="true"
			:default-collapsed="true">
			<div class="scopes-info">
				<div class="scope-item">
					<div class="scope-header">
						<Github :size="20" />
						<strong>GitHub Token</strong>
					</div>
					<p><strong>Required Scope:</strong> <code>repo</code></p>
					<p class="scope-description">
						<strong>✅ Discover & Import:</strong> Search and read configuration files from repositories<br>
						<strong>✅ Publish & Export:</strong> Write and update configuration files to repositories
					</p>
					<p class="scope-note">
						The <code>repo</code> scope provides full repository access (read and write), enabling both discovery and publishing workflows.
					</p>
					<a href="https://github.com/settings/tokens/new"
						target="_blank"
						rel="noopener noreferrer"
						class="external-link">
						Create GitHub Personal Access Token →
					</a>
				</div>

				<div class="scope-item">
					<div class="scope-header">
						<Gitlab :size="20" />
						<strong>GitLab Token</strong>
					</div>
					<p><strong>Required Scopes:</strong></p>
					<ul class="scope-list">
						<li><code>read_api</code> - For <strong>discovery only</strong> (read-only access)</li>
						<li><code>api</code> - For <strong>discovery AND publishing</strong> (full read/write access)</li>
					</ul>
					<p class="scope-description">
						<strong>🔍 Discovery Only:</strong> Use <code>read_api</code> to search and import configurations<br>
						<strong>📤 Discovery + Publishing:</strong> Use <code>api</code> to also export and update configurations
					</p>
					<p class="scope-note">
						If you plan to publish configurations back to GitLab, select the <code>api</code> scope when creating your token.
					</p>
					<a href="https://gitlab.com/-/user_settings/personal_access_tokens"
						target="_blank"
						rel="noopener noreferrer"
						class="external-link">
						Create GitLab Personal Access Token →
					</a>
				</div>

				<div class="documentation-link">
					<InformationOutline :size="20" />
					<a href="https://docs.openregister.nl/user-guide/configuration/api-tokens" target="_blank" rel="noopener noreferrer">
						View complete documentation on obtaining and configuring API tokens
					</a>
				</div>
			</div>
		</SettingsCard>

		<!-- GitHub Token Configuration -->
		<SettingsCard
			title="GitHub Personal Access Token"
			icon="🔐"
			:collapsible="false">
			<template #icon>
				<Github :size="20" />
			</template>

			<div class="token-config">
				<div class="token-field-group">
					<label for="github-token">GitHub Token</label>
					<div class="token-input-row">
						<NcPasswordField
							id="github-token"
							v-model="githubToken"
							placeholder="ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
							autocomplete="off"
							@update:value="updateGitHubToken">
							<template #trailing-button-icon>
								<Github :size="20" />
							</template>
						</NcPasswordField>

						<div class="token-actions">
							<NcButton
								type="primary"
								:disabled="saving || !githubToken || githubToken === originalGithubToken"
								@click="saveGitHubToken">
								<template #icon>
									<NcLoadingIcon v-if="saving" :size="20" />
									<ContentSave v-else :size="20" />
								</template>
								Save Token
							</NcButton>
							<NcButton
								type="secondary"
								:disabled="testingGithub || !githubToken"
								@click="testGitHubToken">
								<template #icon>
									<NcLoadingIcon v-if="testingGithub" :size="20" />
									<TestTube v-else :size="20" />
								</template>
								Test Token
							</NcButton>
							<NcButton
								type="error"
								:disabled="saving || !githubToken"
								@click="clearGitHubToken">
								<template #icon>
									<Delete :size="20" />
								</template>
								Clear Token
							</NcButton>
							<span v-if="githubToken && githubToken === originalGithubToken && !githubTestResult" class="saved-indicator">
								<CheckCircle :size="20" /> Token saved
							</span>
							<span v-if="githubTestResult && githubTestResult.success" class="test-result-success">
								<CheckCircle :size="20" /> {{ githubTestResult.message }}
							</span>
							<span v-if="githubTestResult && !githubTestResult.success" class="test-result-error">
								<AlertCircle :size="20" /> {{ githubTestResult.message }}
							</span>
						</div>
					</div>

					<p class="field-hint">
						<LockOutline :size="16" /> Optional: Required for discovering and publishing configurations to/from GitHub
					</p>
				</div>
			</div>
		</SettingsCard>

		<!-- GitLab Token Configuration -->
		<SettingsCard
			title="GitLab Personal Access Token"
			icon="🔐"
			:collapsible="false">
			<template #icon>
				<Gitlab :size="20" />
			</template>

			<div class="token-config">
				<div class="token-field-group">
					<label for="gitlab-token">GitLab Token</label>
					<div class="token-input-row">
						<NcPasswordField
							id="gitlab-token"
							v-model="gitlabToken"
							placeholder="glpat-xxxxxxxxxxxxxxxxxxxx"
							autocomplete="off"
							@update:value="updateGitLabToken">
							<template #trailing-button-icon>
								<Gitlab :size="20" />
							</template>
						</NcPasswordField>

						<div class="token-actions">
							<NcButton
								type="primary"
								:disabled="saving || !gitlabToken || gitlabToken === originalGitlabToken"
								@click="saveGitLabToken">
								<template #icon>
									<NcLoadingIcon v-if="saving" :size="20" />
									<ContentSave v-else :size="20" />
								</template>
								Save Token
							</NcButton>
							<NcButton
								type="secondary"
								:disabled="testingGitlab || !gitlabToken"
								@click="testGitLabToken">
								<template #icon>
									<NcLoadingIcon v-if="testingGitlab" :size="20" />
									<TestTube v-else :size="20" />
								</template>
								Test Token
							</NcButton>
							<NcButton
								type="error"
								:disabled="saving || !gitlabToken"
								@click="clearGitLabToken">
								<template #icon>
									<Delete :size="20" />
								</template>
								Clear Token
							</NcButton>
							<span v-if="gitlabToken && gitlabToken === originalGitlabToken && !gitlabTestResult" class="saved-indicator">
								<CheckCircle :size="20" /> Token saved
							</span>
							<span v-if="gitlabTestResult && gitlabTestResult.success" class="test-result-success">
								<CheckCircle :size="20" /> {{ gitlabTestResult.message }}
							</span>
							<span v-if="gitlabTestResult && !gitlabTestResult.success" class="test-result-error">
								<AlertCircle :size="20" /> {{ gitlabTestResult.message }}
							</span>
						</div>
					</div>

					<p class="field-hint">
						<LockOutline :size="16" /> Optional: Use <code>read_api</code> for discovery, or <code>api</code> for discovery + publishing
					</p>
				</div>

				<!-- GitLab Instance URL -->
				<div class="gitlab-url-section">
					<h5>🌐 Custom GitLab Instance (Optional)</h5>
					<div class="token-field-group">
						<label for="gitlab-url">GitLab API Base URL</label>
						<NcTextField
							id="gitlab-url"
							v-model="gitlabUrl"
							placeholder="https://gitlab.com/api/v4"
							@update:value="updateGitLabUrl">
							<template #trailing-button-icon>
								<Web :size="20" />
							</template>
						</NcTextField>
						<p class="field-hint">
							<InformationOutline :size="16" /> Leave empty to use GitLab.com. For self-hosted GitLab instances, enter your API URL
						</p>
					</div>

					<div class="token-actions">
						<NcButton
							v-if="gitlabUrl && gitlabUrl !== originalGitlabUrl"
							type="primary"
							:disabled="saving"
							@click="saveGitLabUrl">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="20" />
								<ContentSave v-else :size="20" />
							</template>
							Save URL
						</NcButton>
						<span v-if="gitlabUrl && gitlabUrl === originalGitlabUrl" class="saved-indicator">
							<CheckCircle :size="20" /> URL saved
						</span>
					</div>
				</div>
			</div>
		</SettingsCard>

		<!-- Save Status Message -->
		<div v-if="saveMessage" class="save-message" :class="saveMessageType">
			{{ saveMessage }}
		</div>
	</SettingsSection>
</template>

<script>
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import SettingsCard from '../../../components/shared/SettingsCard.vue'
import { NcPasswordField, NcTextField, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Github from 'vue-material-design-icons/Github.vue'
import Gitlab from 'vue-material-design-icons/Gitlab.vue'
import Web from 'vue-material-design-icons/Web.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

/**
 * API Token Configuration Component
 *
 * Manages GitHub and GitLab API tokens for configuration discovery
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license  EUPL-1.2
 */
export default {
	name: 'ApiTokenConfiguration',

	components: {
		SettingsSection,
		SettingsCard,
		NcPasswordField,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		Github,
		Gitlab,
		Web,
		ContentSave,
		Delete,
		CheckCircle,
		LockOutline,
		InformationOutline,
		TestTube,
		AlertCircle,
	},

	data() {
		return {
			loading: false,
			saving: false,
			testingGithub: false,
			testingGitlab: false,
			githubToken: '',
			gitlabToken: '',
			gitlabUrl: '',
			originalGithubToken: '',
			originalGitlabToken: '',
			originalGitlabUrl: '',
			saveMessage: '',
			saveMessageType: 'success',
			githubTestResult: null,
			gitlabTestResult: null,
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

		/**
		 * Show save message
		 *
		 * @param {string} message - The message to show
		 * @param {string} type - The type of message ('success' or 'error')
		 * @return {void}
		 */
		showSaveMessage(message, type = 'success') {
			this.saveMessage = message
			this.saveMessageType = type
			setTimeout(() => {
				this.saveMessage = ''
			}, 3000)
		},

		/**
		 * Test GitHub token validity
		 *
		 * @return {Promise<void>}
		 */
		async testGitHubToken() {
			this.testingGithub = true
			this.githubTestResult = null
			try {
				// Send the current token value for testing
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/api-tokens/test/github'),
					{ token: this.githubToken },
				)
				this.githubTestResult = {
					success: true,
					message: response.data.message,
					username: response.data.username,
					scopes: response.data.scopes,
				}
				showSuccess(this.t('openregister', 'GitHub token is valid! Username: {username}', {
					username: response.data.username,
				}))
			} catch (error) {
				const message = error.response?.data?.message || error.message || 'Unknown error'
				this.githubTestResult = {
					success: false,
					message,
				}
				showError(this.t('openregister', 'GitHub token test failed: {message}', {
					message,
				}))
			} finally {
				this.testingGithub = false
			}
		},

		/**
		 * Test GitLab token validity
		 *
		 * @return {Promise<void>}
		 */
		async testGitLabToken() {
			this.testingGitlab = true
			this.gitlabTestResult = null
			try {
				// Send the current token value for testing
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/api-tokens/test/gitlab'),
					{ token: this.gitlabToken, url: this.gitlabUrl },
				)
				this.gitlabTestResult = {
					success: true,
					message: response.data.message,
					username: response.data.username,
					instance: response.data.instance,
				}
				showSuccess(this.t('openregister', 'GitLab token is valid! Username: {username}', {
					username: response.data.username,
				}))
			} catch (error) {
				const message = error.response?.data?.message || error.message || 'Unknown error'
				this.gitlabTestResult = {
					success: false,
					message,
				}
				showError(this.t('openregister', 'GitLab token test failed: {message}', {
					message,
				}))
			} finally {
				this.testingGitlab = false
			}
		},
	},
}
</script>

<style scoped>
/* Section Description */
.section-description-full {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.main-description {
	color: var(--color-text-light);
	font-size: 14px;
	line-height: 1.6;
	margin: 0 0 16px 0;
}

.main-description:last-child {
	margin-bottom: 0;
}

.main-description.info-note {
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-primary-element);
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-top: 16px;
	margin-bottom: 0;
}

.main-description.info-note strong {
	color: var(--color-primary-element);
}

/* Scopes Info */
.scopes-info {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.scope-item {
	padding: 16px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.scope-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.scope-header strong {
	font-size: 15px;
	color: var(--color-main-text);
}

.scope-item p {
	margin: 8px 0;
	font-size: 14px;
	line-height: 1.5;
}

.scope-item code {
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 3px;
	font-family: monospace;
	color: var(--color-primary-element);
	font-weight: 600;
}

.scope-description {
	color: var(--color-text-maxcontrast);
	font-size: 13px !important;
	line-height: 1.6;
}

.scope-list {
	margin: 12px 0 12px 20px;
	padding: 0;
	list-style: disc;
}

.scope-list li {
	margin: 6px 0;
	font-size: 14px;
	line-height: 1.5;
}

.scope-list code {
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 3px;
	font-family: monospace;
	color: var(--color-primary-element);
	font-weight: 600;
}

.scope-note {
	margin-top: 12px;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-left: 3px solid var(--color-warning);
	border-radius: var(--border-radius);
	font-size: 13px !important;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.external-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-primary-element);
	text-decoration: none;
	font-weight: 500;
	margin-top: 8px;
}

.external-link:hover {
	text-decoration: underline;
}

.documentation-link {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	background: var(--color-primary-element-light);
	border-left: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius);
}

.documentation-link a {
	color: var(--color-primary-element);
	text-decoration: none;
	font-weight: 500;
}

.documentation-link a:hover {
	text-decoration: underline;
}

/* Token Configuration */
.token-config {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.token-field-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.token-field-group label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-size: 14px;
}

.token-input-row {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	width: 100%;
}

.token-input-row > :first-child {
	flex: 0 1 400px;
	max-width: 400px;
}

.field-hint {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.field-hint code {
	background: var(--color-background-dark);
	padding: 2px 5px;
	border-radius: 3px;
	font-family: monospace;
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 600;
}

.token-actions {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	flex-wrap: wrap;
	flex: 1;
}

.saved-indicator {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-success);
	font-weight: 500;
	font-size: 14px;
}

.test-result-success {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-success);
	font-weight: 500;
	font-size: 14px;
	padding: 8px 12px;
	background-color: var(--color-success-light);
	border-radius: var(--border-radius);
}

.test-result-error {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-error);
	font-weight: 500;
	font-size: 14px;
	padding: 8px 12px;
	background-color: var(--color-error-light);
	border-radius: var(--border-radius);
}

/* GitLab URL Section */
.gitlab-url-section {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

.gitlab-url-section h5 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 15px;
	font-weight: 500;
}

/* Save Message */
.save-message {
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-top: 16px;
	text-align: center;
	font-weight: 500;
}

.save-message.success {
	background: var(--color-success);
	color: white;
}

.save-message.error {
	background: var(--color-error);
	color: white;
}

@media (max-width: 768px) {
	.scopes-info {
		gap: 16px;
	}

	.token-input-row {
		flex-direction: column;
	}

	.token-input-row > :first-child {
		flex: 1;
		max-width: 100%;
	}

	.token-actions {
		flex-direction: column;
		align-items: stretch;
		width: 100%;
	}
}
</style>
