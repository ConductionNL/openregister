<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<SettingsSection
		id="api-tokens"
		:name="t('openregister', 'API Token Configuration')"
		:description="
			t(
				'openregister',
				'Configure API tokens for external service integrations',
			)
		"
		:loading="loading"
		:loadingMessage="t('openregister', 'Loading API tokens...')">
		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				API tokens enable
				<strong
					>discovering, importing, and publishing OpenRegister
					configurations</strong
				>
				to GitHub and GitLab repositories. With the appropriate token scopes,
				you can search for configurations published by the community, import
				them into your system, and publish your own configurations back to
				repositories for sharing and version control.
			</p>
			<p class="main-description info-note">
				<strong>🔐 Note:</strong> Tokens are <strong>optional</strong> for
				basic workflows. You can import configurations manually using direct
				URLs without tokens. However, tokens with appropriate scopes are
				required for: <strong>(1)</strong> Discovery/search features, and
				<strong>(2)</strong> Publishing configurations to repositories.
			</p>
		</div>

		<!-- Required Scopes Info -->
		<SettingsCard
			:title="t('openregister', 'Required Token Scopes')"
			icon="📋"
			:collapsible="true"
			:defaultCollapsed="true">
			<div class="scopes-info">
				<div class="scope-item">
					<div class="scope-header">
						<Github :size="20" />
						<strong>GitHub Token</strong>
					</div>
					<p><strong>Required Scope:</strong> <code>repo</code></p>
					<p class="scope-description">
						<strong>✅ Discover & Import:</strong> Search and read
						configuration files from repositories<br />
						<strong>✅ Publish & Export:</strong> Write and update
						configuration files to repositories
					</p>
					<p class="scope-note">
						The <code>repo</code> scope provides full repository access
						(read and write), enabling both discovery and publishing
						workflows.
					</p>
					<a
						href="https://github.com/settings/tokens/new"
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
						<li>
							<code>read_api</code> - For
							<strong>discovery only</strong> (read-only access)
						</li>
						<li>
							<code>api</code> - For
							<strong>discovery AND publishing</strong> (full
							read/write access)
						</li>
					</ul>
					<p class="scope-description">
						<strong>🔍 Discovery Only:</strong> Use
						<code>read_api</code> to search and import configurations<br />
						<strong>📤 Discovery + Publishing:</strong> Use
						<code>api</code> to also export and update configurations
					</p>
					<p class="scope-note">
						If you plan to publish configurations back to GitLab, select
						the <code>api</code> scope when creating your token.
					</p>
					<a
						href="https://gitlab.com/-/user_settings/personal_access_tokens"
						target="_blank"
						rel="noopener noreferrer"
						class="external-link">
						Create GitLab Personal Access Token →
					</a>
				</div>

				<div class="documentation-link">
					<InformationOutline :size="20" />
					<a
						href="https://docs.openregister.nl/user-guide/configuration/api-tokens"
						target="_blank"
						rel="noopener noreferrer">
						View complete documentation on obtaining and configuring API
						tokens
					</a>
				</div>
			</div>
		</SettingsCard>

		<SettingsCard
			v-for="token in tokenCards"
			:key="token.key"
			:title="token.title"
			icon="🔐"
			:collapsible="false">
			<template #icon>
				<component :is="token.icon" :size="20" />
			</template>

			<div class="token-config">
				<div class="token-field-group">
					<label :for="token.id">{{ token.label }}</label>
					<div class="token-input-row">
						<NcPasswordField
							:id="token.id"
							:modelValue="token.value"
							:placeholder="token.placeholder"
							autocomplete="off"
							@update:modelValue="token.update">
							<template #trailing-button-icon>
								<component :is="token.icon" :size="20" />
							</template>
						</NcPasswordField>

						<div class="token-actions">
							<NcButton
								variant="primary"
								:disabled="
									saving
									|| !token.value
									|| token.value === token.original
								"
								@click="token.save">
								<template #icon>
									<NcLoadingIcon v-if="saving" :size="20" />
									<ContentSave v-else :size="20" />
								</template>
								Save Token
							</NcButton>
							<NcButton
								variant="secondary"
								:disabled="token.testing || !token.value"
								@click="token.test">
								<template #icon>
									<NcLoadingIcon v-if="token.testing" :size="20" />
									<TestTube v-else :size="20" />
								</template>
								Test Token
							</NcButton>
							<NcButton
								variant="error"
								:disabled="saving || !token.value"
								@click="token.clear">
								<template #icon>
									<Delete :size="20" />
								</template>
								Clear Token
							</NcButton>
							<span
								v-if="
									token.value
									&& token.value === token.original
									&& !token.result
								"
								class="saved-indicator">
								<CheckCircle :size="20" /> Token saved
							</span>
							<span
								v-if="token.result && token.result.success"
								class="test-result-success">
								<CheckCircle :size="20" />
								{{ token.result.message }}
							</span>
							<span
								v-if="token.result && !token.result.success"
								class="test-result-error">
								<AlertCircle :size="20" />
								{{ token.result.message }}
							</span>
						</div>
					</div>

					<p v-if="token.key === 'github'" class="field-hint">
						<LockOutline :size="16" /> Optional: Required for discovering
						and publishing configurations to/from GitHub
					</p>
					<p v-else class="field-hint">
						<LockOutline :size="16" /> Optional: Use
						<code>read_api</code> for discovery, or <code>api</code> for
						discovery + publishing
					</p>
				</div>

				<!-- GitLab Instance URL -->
				<div v-if="token.key === 'gitlab'" class="gitlab-url-section">
					<h5>🌐 Custom GitLab Instance (Optional)</h5>
					<div class="token-field-group">
						<label for="gitlab-url">GitLab API Base URL</label>
						<NcTextField
							id="gitlab-url"
							v-model="gitlabUrl"
							placeholder="https://gitlab.com/api/v4"
							@update:modelValue="updateGitLabUrl">
							<template #trailing-button-icon>
								<Web :size="20" />
							</template>
						</NcTextField>
						<p class="field-hint">
							<InformationOutline :size="16" /> Leave empty to use
							GitLab.com. For self-hosted GitLab instances, enter your
							API URL
						</p>
					</div>

					<div class="token-actions">
						<NcButton
							v-if="gitlabUrl && gitlabUrl !== originalGitlabUrl"
							variant="primary"
							:disabled="saving"
							@click="saveGitLabUrl">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="20" />
								<ContentSave v-else :size="20" />
							</template>
							Save URL
						</NcButton>
						<span
							v-if="gitlabUrl && gitlabUrl === originalGitlabUrl"
							class="saved-indicator">
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
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcPasswordField,
	NcTextField,
} from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Github from 'vue-material-design-icons/Github.vue'
import Gitlab from 'vue-material-design-icons/Gitlab.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import Web from 'vue-material-design-icons/Web.vue'
import SettingsCard from '../../../components/shared/SettingsCard.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import { saveMessageMethods } from './saveMessage.js'

/**
 * The API token settings endpoint.
 *
 * @return {string} The URL
 */
function tokenSettingsUrl() {
	return generateUrl('/apps/openregister/api/settings/api-tokens')
}

/**
 * Save part of the API token settings.
 *
 * @param {object} body The settings to change
 * @return {Promise<object>} The axios response
 */
function postTokenSettings(body) {
	return axios.post(tokenSettingsUrl(), body)
}

/**
 * Ask the backend whether a provider's token works.
 *
 * @param {string} provider 'github' or 'gitlab'
 * @param {object} body The token, and for GitLab its instance URL
 * @return {Promise<object>} The test endpoint's answer
 */
async function postTokenTest(provider, body) {
	const response = await axios.post(
		generateUrl(`/apps/openregister/api/settings/api-tokens/test/${provider}`),
		body,
	)
	return response.data
}

/**
 * The reason a token test failed: the server's message, else the error's own.
 *
 * @param {object} error The axios error
 * @return {string} The reason
 */
function failureMessage(error) {
	return error.response?.data?.message || error.message || 'Unknown error'
}

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

	computed: {
		/**
		 * The GitHub and GitLab token cards: their texts, current and stored
		 * token, test state and handlers, for display.
		 *
		 * @spec exclude UI plumbing — token card layout over local fields.
		 * @return {Array<object>}
		 */
		tokenCards() {
			return [
				{
					key: 'github',
					id: 'github-token',
					label: 'GitHub Token',
					title: this.t('openregister', 'GitHub Personal Access Token'),
					icon: Github,
					placeholder: 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
					...this.tokenState('github', 'GitHub'),
				},
				{
					key: 'gitlab',
					id: 'gitlab-token',
					label: 'GitLab Token',
					title: this.t('openregister', 'GitLab Personal Access Token'),
					icon: Gitlab,
					placeholder: 'glpat-xxxxxxxxxxxxxxxxxxxx',
					...this.tokenState('gitlab', 'GitLab'),
				},
			]
		},
	},

	async mounted() {
		await this.loadTokens()
	},

	methods: {
		...saveMessageMethods,

		/**
		 * The current and stored token, test state and handlers of a provider.
		 *
		 * @spec exclude UI plumbing — binds a token card to its local fields.
		 * @param {string} field The prefix of its fields, such as `github`
		 * @param {string} name The suffix of its handlers, such as `GitHub`
		 * @return {object} The token state
		 */
		tokenState(field, name) {
			const suffix = field.charAt(0).toUpperCase() + field.slice(1)
			return {
				value: this[`${field}Token`],
				original: this[`original${suffix}Token`],
				testing: this[`testing${suffix}`],
				result: this[`${field}TestResult`],
				update: (value) => {
					this[`${field}Token`] = value
					this[`update${name}Token`](value)
				},

				save: () => this[`save${name}Token`](),
				test: () => this[`test${name}Token`](),
				clear: () => this[`clear${name}Token`](),
			}
		},

		/**
		 * Load existing API tokens from the backend
		 *
		 * @spec exclude UI plumbing — admin-settings load hydrating local fields.
		 * @return {Promise<void>}
		 */
		async loadTokens() {
			this.loading = true
			try {
				const response = await axios.get(tokenSettingsUrl())
				this.applyLoadedTokens(response.data)
			} catch (error) {
				console.error('Failed to load API tokens:', error)
				// Don't show error on initial load as tokens might not be set
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show the loaded token settings and remember them as saved.
		 *
		 * @spec exclude UI plumbing — admin-settings load hydrating local fields.
		 * @param {object} data The token settings
		 * @return {void}
		 */
		applyLoadedTokens(data) {
			this.githubToken = data.github_token || ''
			this.gitlabToken = data.gitlab_token || ''
			this.gitlabUrl = data.gitlab_url || ''
			this.originalGithubToken = this.githubToken
			this.originalGitlabToken = this.gitlabToken
			this.originalGitlabUrl = this.gitlabUrl
		},

		/**
		 * Update GitHub token value
		 *
		 * @spec exclude UI plumbing — local field setter for two-way binding.
		 * @param {string} value New token value
		 * @return {void}
		 */
		updateGitHubToken(value) {
			this.githubToken = value
		},

		/**
		 * Update GitLab token value
		 *
		 * @spec exclude UI plumbing — local field setter for two-way binding.
		 * @param {string} value New token value
		 * @return {void}
		 */
		updateGitLabToken(value) {
			this.gitlabToken = value
		},

		/**
		 * Update GitLab URL value
		 *
		 * @spec exclude UI plumbing — local field setter for two-way binding.
		 * @param {string} value New URL value
		 * @return {void}
		 */
		updateGitLabUrl(value) {
			this.gitlabUrl = value
		},

		/**
		 * Save GitHub token to the backend
		 *
		 * @spec exclude UI plumbing — admin-settings save + toast.
		 * @return {Promise<void>}
		 */
		async saveGitHubToken() {
			this.saving = true
			try {
				await postTokenSettings({ github_token: this.githubToken })
				this.originalGithubToken = this.githubToken
				showSuccess(
					this.t('openregister', 'GitHub token saved successfully'),
				)
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
		 * @spec exclude UI plumbing — admin-settings save + toast.
		 * @return {Promise<void>}
		 */
		async saveGitLabToken() {
			this.saving = true
			try {
				await postTokenSettings({ gitlab_token: this.gitlabToken })
				this.originalGitlabToken = this.gitlabToken
				showSuccess(
					this.t('openregister', 'GitLab token saved successfully'),
				)
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
		 * @spec exclude UI plumbing — admin-settings save + toast.
		 * @return {Promise<void>}
		 */
		async saveGitLabUrl() {
			this.saving = true
			try {
				await postTokenSettings({ gitlab_url: this.gitlabUrl })
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
		 * @spec exclude UI plumbing — resets field then delegates to saveGitHubToken.
		 * @return {Promise<void>}
		 */
		async clearGitHubToken() {
			this.githubToken = ''
			await this.saveGitHubToken()
		},

		/**
		 * Clear GitLab token
		 *
		 * @spec exclude UI plumbing — resets field then delegates to saveGitLabToken.
		 * @return {Promise<void>}
		 */
		async clearGitLabToken() {
			this.gitlabToken = ''
			await this.saveGitLabToken()
		},

		/**
		 * Test GitHub token validity
		 *
		 * @spec exclude UI plumbing — thin POST + result display; token validation contract owned by backend.
		 * @return {Promise<void>}
		 */
		async testGitHubToken() {
			this.testingGithub = true
			this.githubTestResult = null
			try {
				// Send the current token value for testing
				const data = await postTokenTest('github', { token: this.githubToken })
				this.githubTokenPassed(data)
			} catch (error) {
				this.githubTokenFailed(failureMessage(error))
			} finally {
				this.testingGithub = false
			}
		},

		/**
		 * Show a passed GitHub token test.
		 *
		 * @spec exclude UI plumbing — token test result display.
		 * @param {object} data The test endpoint's answer
		 * @return {void}
		 */
		githubTokenPassed(data) {
			this.githubTestResult = {
				success: true,
				message: data.message,
				username: data.username,
				scopes: data.scopes,
			}
			showSuccess(
				this.t('openregister', 'GitHub token is valid! Username: {username}', {
					username: data.username,
				}),
			)
		},

		/**
		 * Show a failed GitHub token test.
		 *
		 * @spec exclude UI plumbing — token test result display.
		 * @param {string} message Why it failed
		 * @return {void}
		 */
		githubTokenFailed(message) {
			this.githubTestResult = {
				success: false,
				message,
			}
			showError(
				this.t('openregister', 'GitHub token test failed: {message}', {
					message,
				}),
			)
		},

		/**
		 * Test GitLab token validity
		 *
		 * @spec exclude UI plumbing — thin POST + result display; token validation contract owned by backend.
		 * @return {Promise<void>}
		 */
		async testGitLabToken() {
			this.testingGitlab = true
			this.gitlabTestResult = null
			try {
				// Send the current token value for testing
				const data = await postTokenTest('gitlab', {
					token: this.gitlabToken,
					url: this.gitlabUrl,
				})
				this.gitlabTokenPassed(data)
			} catch (error) {
				this.gitlabTokenFailed(failureMessage(error))
			} finally {
				this.testingGitlab = false
			}
		},

		/**
		 * Show a passed GitLab token test.
		 *
		 * @spec exclude UI plumbing — token test result display.
		 * @param {object} data The test endpoint's answer
		 * @return {void}
		 */
		gitlabTokenPassed(data) {
			this.gitlabTestResult = {
				success: true,
				message: data.message,
				username: data.username,
				instance: data.instance,
			}
			showSuccess(
				this.t('openregister', 'GitLab token is valid! Username: {username}', {
					username: data.username,
				}),
			)
		},

		/**
		 * Show a failed GitLab token test.
		 *
		 * @spec exclude UI plumbing — token test result display.
		 * @param {string} message Why it failed
		 * @return {void}
		 */
		gitlabTokenFailed(message) {
			this.gitlabTestResult = {
				success: false,
				message,
			}
			showError(
				this.t('openregister', 'GitLab token test failed: {message}', {
					message,
				}),
			)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/views.scss' as *;

/* Section Description */
@include section-description-full-rules;

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

.scope-item code,
.scope-list code {
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

.saved-indicator,
.test-result-success {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-success);
	font-weight: 500;
	font-size: 14px;
}

.test-result-success {
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
@include save-message-rules;

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
