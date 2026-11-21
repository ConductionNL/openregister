<template>
	<NcModal :name="t('openregister', 'Scope an app password')" @close="$emit('close')">
		<div class="token-grant-modal">
			<h3>{{ title }}</h3>

			<div v-if="token === null" class="token-grant-modal__find">
				<p>{{ t('openregister', 'Paste the app password once. It is only used to find the token and is not stored.') }}</p>
				<label class="token-grant-modal__field">
					<span>{{ t('openregister', 'App password') }}</span>
					<input
						v-model="password"
						type="password"
						autocomplete="off"
						data-field="password"
						@keyup.enter="find">
				</label>
				<NcButton variant="primary" :disabled="busy || password.trim() === ''" data-action="find" @click="find">
					{{ t('openregister', 'Find token') }}
				</NcButton>
			</div>

			<TokenGrantEditor
				v-else
				:grant="token.grant"
				:busy="busy"
				:removable="token.grant !== null"
				:previewResult="previewResult"
				previewable
				@save="save"
				@remove="remove"
				@preview="preview" />

			<p v-if="error" class="token-grant-modal__error" role="alert">
				{{ error }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal } from '@nextcloud/vue'
import TokenGrantEditor from '../../components/tokens/TokenGrantEditor.vue'

export default {
	name: 'TokenGrantModal',
	components: { NcButton, NcModal, TokenGrantEditor },

	props: {
		/** A token that already carries a grant (`{tokenId, label, grant}`), or null to find one. */
		granted: { type: Object, default: null },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			password: '',
			token: this.granted === null
				? null
				: { tokenId: this.granted.tokenId, name: this.granted.label || '', grant: this.granted.grant },

			busy: false,
			error: '',
			previewResult: null,
		}
	},

	computed: {
		title() {
			if (this.token === null) return t('openregister', 'Scope an app password')
			return this.token.name || `${t('openregister', 'App password')} ${this.token.tokenId}`
		},
	},

	methods: {
		t,
		/**
		 * Resolve the pasted app password to the caller's token; the password is
		 * dropped from memory as soon as the answer arrives.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @return {Promise<void>}
		 */
		async find() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/openregister/api/tokens/grants/resolve'), { password: this.password.trim() })
				this.token = { tokenId: data.tokenId, name: data.name || '', grant: data.grant ? data.grant.grant : null }
			} catch {
				this.error = t('openregister', 'This is not one of your app passwords')
			} finally {
				this.password = ''
				this.busy = false
			}
		},

		/**
		 * Store the grant on the token.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
		 * @param {object} grant - the grant from the editor
		 * @return {Promise<void>}
		 */
		async save(grant) {
			await this.run(async () => {
				await axios.put(generateUrl(`/apps/openregister/api/tokens/${this.token.tokenId}/grant`), { grant, label: this.token.name })
				this.$emit('saved', t('openregister', 'Grant saved'))
			})
		},

		/**
		 * Take the grant off the token.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @return {Promise<void>}
		 */
		async remove() {
			await this.run(async () => {
				await axios.delete(generateUrl(`/apps/openregister/api/tokens/${this.token.tokenId}/grant`))
				this.$emit('saved', t('openregister', 'Grant removed'))
			})
		},

		/**
		 * Ask what the grant would let the token do on one object.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @param {{grant: object, register: string, schema: string, object: string}} question - the grant and the object
		 * @return {Promise<void>}
		 */
		async preview(question) {
			await this.run(async () => {
				const { data } = await axios.post(generateUrl('/apps/openregister/api/tokens/grants/preview'), question)
				this.previewResult = data
			})
		},

		/**
		 * Run one request with the busy flag, showing the server's reason on failure.
		 *
		 * @spec exclude UI plumbing — busy flag and error text around the grant requests.
		 * @param {() => Promise<void>} request - the request
		 * @return {Promise<void>}
		 */
		async run(request) {
			this.busy = true
			this.error = ''
			try {
				await request()
			} catch (e) {
				this.error = e?.response?.data?.error || t('openregister', 'Failed to save grant')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.token-grant-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.token-grant-modal__find,
.token-grant-modal__field {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.token-grant-modal__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error));
}
</style>
