<template>
	<div class="section">
		<h2>{{ t('openregister', 'API Tokens') }}</h2>
		<div class="tokens-section">
			<NcButton type="primary" @click="showCreateModal = true">
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
					<NcButton type="error" @click="revokeToken(token.id)">
						{{ t('openregister', 'Revoke') }}
					</NcButton>
				</li>
			</ul>
			<p v-if="tokens.length === 0 && !loading">
				{{ t('openregister', 'No API tokens.') }}
			</p>
		</div>

		<NcModal v-if="showCreateModal" @close="showCreateModal = false">
			<div class="tokens-section__modal">
				<h3>{{ t('openregister', 'Create API Token') }}</h3>
				<div class="section__field">
					<label for="token-name">{{ t('openregister', 'Token name') }}</label>
					<NcTextField id="token-name"
						v-model="newTokenName"
						:label="t('openregister', 'Token name')" />
				</div>
				<div class="section__field">
					<label for="token-expires">{{ t('openregister', 'Expires in (e.g., 90d)') }}</label>
					<NcTextField id="token-expires"
						v-model="newTokenExpires"
						:label="t('openregister', 'Expiration')" />
				</div>
				<NcButton type="primary"
					:disabled="!newTokenName"
					@click="createToken">
					{{ t('openregister', 'Create') }}
				</NcButton>
			</div>
		</NcModal>

		<NcModal v-if="createdToken" @close="createdToken = null">
			<div class="tokens-section__modal">
				<h3>{{ t('openregister', 'Token Created') }}</h3>
				<p class="tokens-section__warning">
					{{ t('openregister', 'This token will only be shown once. Copy it now.') }}
				</p>
				<div class="tokens-section__token-display">
					<code>{{ createdToken }}</code>
					<NcButton @click="copyToken">
						{{ t('openregister', 'Copy to clipboard') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<p v-if="message" :class="{ 'section__error': isError, 'section__success': !isError }">
			{{ message }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

export default {
	name: 'TokensSection',
	components: { NcButton, NcModal, NcTextField },
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
.tokens-section__modal { padding: 24px; }
.tokens-section__warning { color: var(--color-warning); font-weight: bold; margin-bottom: 12px; }
.tokens-section__token-display { display: flex; gap: 8px; align-items: center; }
.tokens-section__token-display code { background: var(--color-background-dark); padding: 8px; border-radius: 4px; word-break: break-all; flex: 1; }
</style>
