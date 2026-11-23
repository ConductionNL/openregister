<template>
	<div class="section">
		<h2>{{ t('openregister', 'API Tokens') }}</h2>
		<div class="tokens-section">
			<NcButton variant="primary" @click="showCreateModal = true">
				{{ t('openregister', 'Create new token') }}
			</NcButton>

			<div v-if="loading" class="section__loading">
				{{ t('openregister', 'Loading tokens…') }}
			</div>
			<ul v-else class="tokens-section__list">
				<li
					v-for="token in tokens"
					:key="token.id"
					class="tokens-section__item">
					<div class="tokens-section__info">
						<strong>{{ token.name }}</strong>
						<span class="tokens-section__preview">{{
							token.preview
						}}</span>
						<span v-if="token.expires" class="tokens-section__expires">
							{{ t('openregister', 'Expires') }}:
							{{ formatDate(token.expires) }}
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

		<div class="tokens-section--scoped">
			<h3>{{ t('openregister', 'Scoped app passwords') }}</h3>
			<NcButton data-action="scope" @click="openGrant(null)">
				{{ t('openregister', 'Scope an app password') }}
			</NcButton>
			<ul class="tokens-section__granted-list">
				<li
					v-for="granted in grantedTokens"
					:key="granted.tokenId"
					class="tokens-section__granted"
					:data-granted="granted.tokenId">
					<div class="tokens-section__info">
						<strong>{{ granted.label || `${t('openregister', 'App password')} ${granted.tokenId}` }}</strong>
						<span v-if="granted.grant" class="tokens-section__preview">{{ (granted.grant.verbs || []).join(', ') }}</span>
						<span v-if="granted.grant && granted.grant.expiresAt" class="tokens-section__expires">
							{{ t('openregister', 'Expires') }}:
							{{ formatDate(granted.grant.expiresAt) }}
						</span>
					</div>
					<NcButton @click="openGrant(granted)">
						{{ t('openregister', 'Edit') }}
					</NcButton>
				</li>
			</ul>
			<p v-if="grantedTokens.length === 0">
				{{ t('openregister', 'No app password carries a grant yet.') }}
			</p>
		</div>

		<TokenGrantModal
			v-if="grantModalOpen"
			:granted="grantTarget"
			@close="grantModalOpen = false"
			@saved="grantSaved" />

		<CreateTokenModal
			v-if="showCreateModal"
			:tokenName="newTokenName"
			:tokenExpires="newTokenExpires"
			@close="showCreateModal = false"
			@create="createToken"
			@update:tokenName="newTokenName = $event"
			@update:tokenExpires="newTokenExpires = $event" />

		<CreatedTokenModal
			v-if="createdToken"
			:token="createdToken"
			@close="createdToken = null"
			@copy="copyToken" />

		<SectionStatus :message="message" :isError="isError" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import SectionStatus from '../../../components/shared/SectionStatus.vue'
import CreatedTokenModal from '../../../modals/account/CreatedTokenModal.vue'
import CreateTokenModal from '../../../modals/account/CreateTokenModal.vue'
import TokenGrantModal from '../../../modals/account/TokenGrantModal.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'

export default {
	name: 'TokensSection',
	components: { NcButton, CreateTokenModal, CreatedTokenModal, SectionStatus, TokenGrantModal },

	mixins: [sectionStatus],

	data() {
		return {
			tokens: [],
			loading: false,
			showCreateModal: false,
			newTokenName: '',
			newTokenExpires: '',
			createdToken: null,
			grantedTokens: [],
			grantModalOpen: false,
			grantTarget: null,
		}
	},

	mounted() {
		this.loadTokens()
		this.loadGrantedTokens()
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
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/user/me/tokens'),
				)
				this.tokens = data || []
			} catch {
				// Handle silently.
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the signed-in user's app passwords that carry a grant.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @return {Promise<void>}
		 */
		async loadGrantedTokens() {
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/tokens/grants'))
				this.grantedTokens = data?.results || []
			} catch {
				this.grantedTokens = []
			}
		},

		/**
		 * Open the grant editor for a granted token, or to find one by its password.
		 *
		 * @spec exclude UI plumbing — opens the grant modal.
		 * @param {object|null} granted - the granted token, or null
		 * @return {void}
		 */
		openGrant(granted) {
			this.grantTarget = granted
			this.grantModalOpen = true
		},

		/**
		 * Close the grant editor after a save or removal and reload the list.
		 *
		 * @spec exclude UI plumbing — status line and list refresh.
		 * @param {string} message - what happened
		 * @return {Promise<void>}
		 */
		async grantSaved(message) {
			this.grantModalOpen = false
			this.setStatus(message)
			await this.loadGrantedTokens()
		},

		/**
		 * Create a personal API token and surface the one-time secret.
		 *
		 * @spec exclude UI plumbing — POST + reveal-modal glue around the account-self-service token list contract.
		 * @return {Promise<void>}
		 */
		async createToken() {
			try {
				const { data } = await axios.post(
					generateUrl('/apps/openregister/api/user/me/tokens'),
					this.newTokenPayload(),
				)
				this.createdToken = data.token
				this.showCreateModal = false
				this.newTokenName = ''
				this.newTokenExpires = ''
				await this.loadTokens()
			} catch (e) {
				this.setStatusError(e, t('openregister', 'Failed to create token'))
			}
		},

		/**
		 * The name and, when chosen, the expiry of the token to create.
		 *
		 * @spec exclude UI plumbing — POST + reveal-modal glue around the account-self-service token list contract.
		 * @return {object} The request body
		 */
		newTokenPayload() {
			const payload = { name: this.newTokenName }
			if (this.newTokenExpires) payload.expiresIn = this.newTokenExpires
			return payload
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
				await axios.delete(
					generateUrl(`/apps/openregister/api/user/me/tokens/${id}`),
				)
				this.setStatus(t('openregister', 'Token revoked'))
				await this.loadTokens()
			} catch (e) {
				this.setStatusError(e, t('openregister', 'Failed to revoke token'))
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
				this.setStatus(t('openregister', 'Token copied to clipboard'))
			} catch {
				this.setStatus(t('openregister', 'Failed to copy token'), true)
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

<style scoped lang="scss">
@use '../../../styles/common.scss' as *;
@use '../../../styles/views.scss' as *;

@include section;

.section__loading {
	color: var(--color-text-maxcontrast);
}

.section__field {
	margin-bottom: 12px;
}

@include section-field-label;

@include section-error-rules;

.tokens-section--scoped {
	margin-top: 24px;
}

.tokens-section__granted-list {
	list-style: none;
	padding: 0;
	margin-top: 16px;
}

.tokens-section__granted {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.tokens-section__list {
	list-style: none;
	padding: 0;
	margin-top: 16px;
}

.tokens-section__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.tokens-section__info {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.tokens-section__preview {
	font-family: monospace;
	color: var(--color-text-maxcontrast);
}

.tokens-section__expires {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
