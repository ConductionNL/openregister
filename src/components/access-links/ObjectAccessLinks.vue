<template>
	<div class="object-access-links">
		<p class="object-access-links__intro">
			{{
				t(
					'openregister',
					'A link gives someone without an account access to this object. It expires on the chosen date and every use is recorded.',
				)
			}}
		</p>

		<form class="object-access-links__form" @submit.prevent="create">
			<NcTextField v-model="form.label" :label="t('openregister', 'Label')" />
			<fieldset class="object-access-links__capabilities">
				<legend>{{ t('openregister', 'The holder may') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="capability in capabilities"
					:key="capability"
					v-model="form.capabilities"
					:value="capability"
					name="access-link-capabilities">
					{{ capabilityLabel(capability) }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextField
				v-model="form.expiresOn"
				type="date"
				:min="today"
				:label="t('openregister', 'Expires on')"
				required />
			<NcPasswordField
				v-model="form.password"
				:label="t('openregister', 'Password')"
				autocomplete="new-password" />
			<NcButton type="submit" variant="primary" :disabled="!canCreate || busy">
				{{ t('openregister', 'Create link') }}
			</NcButton>
		</form>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="created" type="success">
			<p>
				{{
					t(
						'openregister',
						'Link created. Copy it and send it to the person it is for.',
					)
				}}
			</p>
			<div class="object-access-links__created">
				<NcTextField
					:modelValue="created.pageUrl || created.url"
					:label="t('openregister', 'Link')"
					readonly />
				<NcButton @click="copy(created.pageUrl || created.url)">
					{{ t('openregister', 'Copy link') }}
				</NcButton>
			</div>
		</NcNoteCard>

		<h3 class="object-access-links__heading">
			{{ t('openregister', 'Access links') }}
		</h3>
		<NcLoadingIcon v-if="loading" />
		<p v-else-if="links.length === 0">
			{{ t('openregister', 'No links to this object yet.') }}
		</p>
		<ul v-else class="object-access-links__list">
			<li
				v-for="link in links"
				:key="link.id"
				class="object-access-links__item">
				<div class="object-access-links__summary">
					<strong>{{ link.label || t('openregister', 'Link') }}</strong>
					<span
						class="object-access-links__state"
						:class="'object-access-links__state--' + stateOf(link)">
						{{ stateLabel(stateOf(link)) }}
					</span>
					<span>{{
						link.capabilities.map(capabilityLabel).join(', ')
					}}</span>
					<span>{{
						t('openregister', 'Expires {date}', {
							date: formatDate(link.expiresAt),
						})
					}}</span>
					<span v-if="link.hasPassword">{{
						t('openregister', 'Password protected')
					}}</span>
				</div>
				<div class="object-access-links__actions">
					<NcButton
						v-if="stateOf(link) !== 'expired'"
						@click="copy(link.pageUrl || link.url)">
						{{ t('openregister', 'Copy link') }}
					</NcButton>
					<NcButton
						v-if="stateOf(link) === 'active'"
						:disabled="busy"
						@click="toggle(link, true)">
						{{ t('openregister', 'Disable') }}
					</NcButton>
					<NcButton
						v-if="stateOf(link) === 'off'"
						:disabled="busy"
						@click="toggle(link, false)">
						{{ t('openregister', 'Enable') }}
					</NcButton>
					<NcButton variant="error" :disabled="busy" @click="revoke(link)">
						{{ t('openregister', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcPasswordField,
	NcTextField,
} from '@nextcloud/vue'
import {
	CAPABILITIES,
	linksForSubject,
	linkState,
	listAccessLinks,
	mintAccessLink,
	mintPayload,
	revokeAccessLink,
	setAccessLinkDisabled,
} from '../../services/accessLinks.js'

/**
 * Share one object by link with someone who has no account: create a link,
 * see the links already made to this object, switch one off or revoke it.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
export default {
	name: 'ObjectAccessLinks',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
	},

	props: {
		/** The uuid of the object the links open. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			capabilities: CAPABILITIES,
			form: {
				label: '',
				capabilities: ['read'],
				expiresOn: '',
				password: '',
			},

			allLinks: [],
			created: null,
			loading: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {Array<object>} The caller's links to this object.
		 */
		links() {
			return linksForSubject(this.allLinks, 'object', this.objectId)
		},

		/**
		 * @spec exclude UI display helper: the earliest date the picker allows.
		 * @return {string} Today as YYYY-MM-DD.
		 */
		today() {
			return new Date().toISOString().slice(0, 10)
		},

		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {boolean} Whether the form holds enough to mint a link.
		 */
		canCreate() {
			return this.form.capabilities.length > 0 && this.form.expiresOn !== ''
		},
	},

	watch: {
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				this.allLinks = await listAccessLinks()
			} catch {
				this.error = t('openregister', 'That did not work. Try again later.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {Promise<void>}
		 */
		async create() {
			if (!this.canCreate) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				this.created = await mintAccessLink(
					mintPayload({
						subjectType: 'object',
						subjectId: this.objectId,
						...this.form,
					}),
				)
				this.form.password = ''
				this.form.label = ''
				await this.load()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('openregister', 'That did not work. Try again later.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @param {object} link The link.
		 * @param {boolean} disabled True to switch it off.
		 * @return {Promise<void>}
		 */
		async toggle(link, disabled) {
			this.busy = true
			try {
				await setAccessLinkDisabled(link.id, disabled)
				await this.load()
			} catch {
				showError(t('openregister', 'That did not work. Try again later.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @param {object} link The link.
		 * @return {Promise<void>}
		 */
		async revoke(link) {
			this.busy = true
			try {
				await revokeAccessLink(link.id)
				if (this.created && this.created.id === link.id) {
					this.created = null
				}
				await this.load()
			} catch {
				showError(t('openregister', 'That did not work. Try again later.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @spec exclude UI display helper: copies a link to the clipboard.
		 * @param {string} url The link.
		 * @return {Promise<void>}
		 */
		async copy(url) {
			try {
				await navigator.clipboard.writeText(url)
				showSuccess(t('openregister', 'Copied'))
			} catch {
				showError(t('openregister', 'That did not work. Try again later.'))
			}
		},

		/**
		 * @spec exclude UI display helper: the state a link is in.
		 * @param {object} link The link.
		 * @return {string} The state.
		 */
		stateOf(link) {
			return linkState(link)
		},

		/**
		 * @spec exclude UI display helper: the label for a state.
		 * @param {string} state The state.
		 * @return {string} The label.
		 */
		stateLabel(state) {
			return (
				{
					active: t('openregister', 'Active'),
					off: t('openregister', 'Disabled'),
					expired: t('openregister', 'Expired'),
				}[state] || state
			)
		},

		/**
		 * @spec exclude UI display helper: the label for a capability.
		 * @param {string} capability The capability.
		 * @return {string} The label.
		 */
		capabilityLabel(capability) {
			return (
				{
					read: t('openregister', 'Read'),
					comment: t('openregister', 'Comment'),
					upload: t('openregister', 'Upload'),
				}[capability] || capability
			)
		},

		/**
		 * @spec exclude UI display helper: a readable date.
		 * @param {string} value An ISO date.
		 * @return {string} The date.
		 */
		formatDate(value) {
			return value ? new Date(value).toLocaleDateString() : ''
		},
	},
}
</script>

<style scoped>
.object-access-links {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
}

.object-access-links__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.object-access-links__capabilities {
	border: none;
	padding: 0;
	margin: 0;
}

.object-access-links__created {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.object-access-links__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	padding: 0;
}

.object-access-links__item {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.object-access-links__summary {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	align-items: baseline;
}

.object-access-links__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.object-access-links__state--active {
	color: var(--color-success-text);
}

.object-access-links__state--off,
.object-access-links__state--expired {
	color: var(--color-text-maxcontrast);
}
</style>
