<template>
	<div class="section">
		<h2>{{ t('openregister', 'API Tokens') }}</h2>
		<div class="tokens-section">
			<NcButton variant="primary" @click="showCreateModal = true">
				{{ t('openregister', 'Create new token') }}
			</NcButton>

			<div v-if="loading" class="section__loading">
				{{ t('openregister', 'Loading tokens...') }}
			</div>
			<ul v-else class="tokens-section__list">
				<li v-for="token in tokens" :key="token.id" class="tokens-section__item">
					<div class="tokens-section__info">
						<strong>{{ token.name }}</strong>
						<span class="tokens-section__preview">{{ token.preview }}</span>
						<span v-if="token.expires" class="tokens-section__expires">
							{{ t('openregister', 'Expires') }}: {{ formatDate(token.expires) }}
						</span>
					</div>
					<NcButton variant="error" @click="revokeToken(token.id)">
						{{ t('openregister', 'Revoke') }}
					</NcButton>
				</li>
			</ul>
			<p v-if="tokens.length === 0 && !loading">
				{{ t('openregister', 'No API tokens.') }}
			</p>
		</div>

		<CreateTokenModal
			v-if="showCreateModal"
			:token-name="newTokenName"
			:token-expires="newTokenExpires"
			@close="showCreateModal = false"
			@create="createToken"
			@update:tokenName="newTokenName = $event"
			@update:tokenExpires="newTokenExpires = $event" />

		<CreatedTokenModal
			v-if="createdToken"
			:token="createdToken"
			@close="createdToken = null"
			@copy="copyToken" />

		<p v-if="message" :class="{ 'section__error': isError, 'section__success': !isError }">
			{{ message }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import CreateTokenModal from '../../../modals/account/CreateTokenModal.vue'
import CreatedTokenModal from '../../../modals/account/CreatedTokenModal.vue'

export default {
	name: 'TokensSection',
	components: { NcButton, CreateTokenModal, CreatedTokenModal },
	data() {
		return {
			tokens: [],
			loading: false,
			showCreateModal: false,
			newTokenName: '',
			newTokenExpires: '',
			createdToken: null,
			message: '',
			isError: false,
		}
	},
	mounted() {
		this.loadTokens()
	},
	methods: {
		t,
		/**
		 * Load the signed-in user's personal API tokens. Errors during initial load
		 * are swallowed because a new user legitimately has no tokens yet.
		 *
		 * @spec openspec/specs/account-self-service/spec.md
		 * @return {Promise<void>}
		 */
		async loadTokens() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/user/me/tokens'))
				this.tokens = data || []
			} catch (e) {
				// Handle silently.
			} finally {
				this.loading = false
			}
		},
		/**
		 * Create a personal API token and surface the one-time secret.
		 *
		 * @spec exclude UI plumbing — POST + reveal-modal glue around the account-self-service token list contract.
		 * @return {Promise<void>}
		 */
		async createToken() {
			try {
				const payload = { name: this.newTokenName }
				if (this.newTokenExpires) payload.expiresIn = this.newTokenExpires
				const { data } = await axios.post(
					generateUrl('/apps/openregister/api/user/me/tokens'),
					payload,
				)
				this.createdToken = data.token
				this.showCreateModal = false
				this.newTokenName = ''
				this.newTokenExpires = ''
				await this.loadTokens()
			} catch (e) {
				this.message = e.response?.data?.error || t('openregister', 'Failed to create token')
				this.isError = true
			}
		},
		/**
		 * Revoke a personal API token by id.
		 *
		 * @spec exclude UI plumbing — thin DELETE + list refresh; token contract owned by account-self-service.
		 * @param {string|number} id - token identifier
		 * @return {Promise<void>}
		 */
		async revokeToken(id) {
			try {
				await axios.delete(generateUrl(`/apps/openregister/api/user/me/tokens/${id}`))
				this.message = t('openregister', 'Token revoked')
				this.isError = false
				await this.loadTokens()
			} catch (e) {
				this.message = e.response?.data?.error || t('openregister', 'Failed to revoke token')
				this.isError = true
			}
		},
		/**
		 * Copy the one-time token to the clipboard.
		 *
		 * @spec exclude UI plumbing — clipboard write + toast, no observable contract.
		 * @return {Promise<void>}
		 */
		async copyToken() {
			try {
				await navigator.clipboard.writeText(this.createdToken)
				this.message = t('openregister', 'Token copied to clipboard')
				this.isError = false
			} catch (e) {
				this.message = t('openregister', 'Failed to copy token')
				this.isError = true
			}
		},
		/**
		 * Format a token expiry date for display.
		 *
		 * @spec exclude UI plumbing — pure display formatter, no observable contract.
		 * @param {string} dateStr - ISO date string
		 * @return {string} localized date
		 */
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.section { margin-bottom: 32px; padding: 16px; border-bottom: 1px solid var(--color-border); }
.section__loading { color: var(--color-text-maxcontrast); }
.section__field { margin-bottom: 12px; }
.section__field label { display: block; margin-bottom: 4px; font-weight: bold; }
.section__error { color: var(--color-error); margin-top: 8px; }
.section__success { color: var(--color-success); margin-top: 8px; }
.tokens-section__list { list-style: none; padding: 0; margin-top: 16px; }
.tokens-section__item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--color-border-dark); }
.tokens-section__info { display: flex; flex-direction: column; gap: 4px; }
.tokens-section__preview { font-family: monospace; color: var(--color-text-maxcontrast); }
.tokens-section__expires { font-size: 0.85em; color: var(--color-text-maxcontrast); }
</style>
