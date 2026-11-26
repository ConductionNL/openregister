<template>
	<SettingsSection
		id="declared-credential-providers"
		:name="t('openregister', 'Declared credential providers')"
		:description="
			t(
				'openregister',
				'Credential providers that other apps ship. One that only narrows a reviewed provider is admitted by itself; any other is unusable until an administrator approves it.',
			)
		"
		:loading="loading">
		<p v-if="!loading && providers.length === 0" class="declared-empty">
			{{ t('openregister', 'No app declares a credential provider.') }}
		</p>

		<ul v-else class="declared-list">
			<li
				v-for="provider in providers"
				:key="provider.identifier"
				class="declared-provider">
				<div class="declared-head">
					<strong>{{ provider.title }}</strong>
					<code>{{ provider.identifier }}</code>
					<span class="declared-status" :class="'declared-status--' + provider.status">
						{{ statusLabel(provider.status) }}
					</span>
				</div>
				<p class="declared-origin">
					{{ t('openregister', 'Declared by {app}', { app: provider.origin }) }}
				</p>
				<p v-if="provider.autoAdmitted" class="declared-auto">
					{{ t('openregister', 'Admitted by itself: it only narrows a reviewed provider.') }}
				</p>
				<p v-if="provider.reason" class="declared-reason">
					{{ t('openregister', 'Reason') }}: {{ provider.reason }}
				</p>

				<dl class="declared-details">
					<dt>{{ t('openregister', 'Host') }}</dt>
					<dd><code>{{ provider.host || provider.baseUrl }}</code></dd>
					<dt>{{ t('openregister', 'Rules') }}</dt>
					<dd>
						<table class="declared-rules">
							<thead>
								<tr>
									<th scope="col">
										{{ t('openregister', 'Method') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Path') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr
									v-for="(rule, index) in provider.allowRules"
									:key="provider.identifier + '-' + index">
									<td><code>{{ rule.method }}</code></td>
									<td><code>{{ rule.pathPattern }}</code></td>
								</tr>
							</tbody>
						</table>
					</dd>
				</dl>

				<div v-if="actionsFor(provider).length > 0" class="declared-actions">
					<NcButton
						v-for="action in actionsFor(provider)"
						:key="action"
						:variant="action === 'approve' ? 'primary' : 'secondary'"
						:disabled="busy === provider.identifier"
						@click="decide(provider, action)">
						{{ actionLabel(action) }}
					</NcButton>
				</div>
			</li>
		</ul>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

/**
 * The decisions each admission state offers. A narrowing declaration is admitted
 * by itself and offers none; an invalid one admits nothing and cannot be decided.
 */
const ACTIONS = {
	pending: ['approve', 'reject'],
	rejected: ['approve'],
	revoked: ['approve'],
	admitted: ['revoke'],
}

/**
 * The administrator's review of app-declared credential providers.
 *
 * Lists every declaration with its origin, status, host and full method+path
 * rule set from `GET /api/credentials/providers/declared`, labels a narrowing
 * declaration as admitted by itself, and records an approve, reject or revoke
 * through the approval routes, then reads the list again.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
 */
export default {
	name: 'DeclaredCredentialProviders',
	components: {
		NcButton,
		SettingsSection,
	},

	data() {
		return {
			providers: [],
			loading: false,
			busy: '',
		}
	},

	/**
	 * Load the declarations.
	 *
	 * @spec exclude UI plumbing: initial data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read every declaration for review.
		 *
		 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/credentials/providers/declared'),
				)
				const results = response.data?.results
				this.providers = Array.isArray(results) ? results : []
			} catch {
				this.providers = []
				showError(this.t('openregister', 'The declared providers could not be loaded.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * The decisions a declaration's state offers.
		 *
		 * @param {object} provider The declaration
		 * @return {string[]} approve, reject and/or revoke
		 */
		actionsFor(provider) {
			if (provider.autoAdmitted === true) {
				return []
			}
			return ACTIONS[provider.status] || []
		},

		/**
		 * Record a decision on a declaration, then read the list again.
		 *
		 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
		 * @param {object} provider The declaration
		 * @param {string} action approve, reject or revoke
		 * @return {Promise<void>}
		 */
		async decide(provider, action) {
			this.busy = provider.identifier
			try {
				await axios.post(
					generateUrl(
						'/apps/openregister/api/credentials/providers/{identifier}/{action}',
						{ identifier: provider.identifier, action },
					),
				)
				await this.load()
			} catch {
				showError(this.t('openregister', 'The decision could not be saved.'))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * The label of an admission state.
		 *
		 * @param {string} status The state
		 * @return {string} The label
		 */
		statusLabel(status) {
			const labels = {
				admitted: this.t('openregister', 'Admitted'),
				pending: this.t('openregister', 'Pending'),
				rejected: this.t('openregister', 'Rejected'),
				revoked: this.t('openregister', 'Revoked'),
				invalid: this.t('openregister', 'Invalid'),
			}
			return labels[status] || status
		},

		/**
		 * The label of a decision button.
		 *
		 * @param {string} action approve, reject or revoke
		 * @return {string} The label
		 */
		actionLabel(action) {
			const labels = {
				approve: this.t('openregister', 'Approve'),
				reject: this.t('openregister', 'Reject'),
				revoke: this.t('openregister', 'Revoke'),
			}
			return labels[action]
		},
	},
}
</script>

<style scoped>
.declared-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.declared-provider {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	margin-bottom: 12px;
}

.declared-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.declared-status {
	border-radius: var(--border-radius-pill);
	padding: 0 8px;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.declared-status--admitted {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.declared-status--pending {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.declared-status--rejected,
.declared-status--revoked,
.declared-status--invalid {
	background-color: var(--color-error);
	color: var(--color-primary-element-text);
}

.declared-origin,
.declared-auto,
.declared-reason,
.declared-empty {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.declared-details {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 8px 0;
}

.declared-details dt {
	font-weight: bold;
}

.declared-details dd {
	margin: 0;
}

.declared-rules th {
	text-align: start;
	padding-inline-end: 16px;
}

.declared-actions {
	display: flex;
	gap: 8px;
}
</style>
