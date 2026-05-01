<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="contacts-tab">
		<!-- Toolbar -->
		<div v-if="!loading && !contactsUnavailable" class="contacts-tab__toolbar">
			<NcButton type="primary" @click="openCreateDialog">
				<template #icon>
					<AccountPlus :size="20" />
				</template>
				{{ t('openregister', 'Add contact') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="contacts-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<NcEmptyContent v-else-if="error"
			:name="t('openregister', 'Failed to load linked contacts')"
			:description="errorMessage">
			<template #icon>
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Contacts app missing (HTTP 501 graceful degradation) -->
		<NcEmptyContent v-else-if="contactsUnavailable"
			:name="t('openregister', 'Contacts integration is not available')"
			:description="t('openregister', 'The Nextcloud Contacts app is not installed or enabled on this server.')">
			<template #icon>
				<AccountOff :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent v-else-if="contacts.length === 0"
			:name="t('openregister', 'No contacts linked to this object')"
			:description="t('openregister', 'Add a contact from any of your address books to associate it with this object.')">
			<template #icon>
				<AccountOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked contacts list -->
		<ul v-else class="contacts-tab__list">
			<li v-for="contact in contacts"
				:key="contact.uid || contact.contactUid || contact.id"
				class="contacts-tab__item">
				<div class="contacts-tab__icon">
					<AccountOutline :size="20" />
				</div>
				<div class="contacts-tab__content">
					<div class="contacts-tab__name">
						{{ contact.fullName || contact.displayName || contact.email || t('openregister', '(unnamed)') }}
					</div>
					<div class="contacts-tab__meta">
						<span v-if="contact.email" class="contacts-tab__email">{{ contact.email }}</span>
						<span v-if="contact.role" class="contacts-tab__separator">&middot;</span>
						<span v-if="contact.role" class="contacts-tab__role">{{ contact.role }}</span>
					</div>
				</div>
				<NcButton type="tertiary"
					:aria-label="t('openregister', 'Remove contact')"
					@click="unlinkContact(contact)">
					<template #icon>
						<CloseCircleOutline :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountOff from 'vue-material-design-icons/AccountOff.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import { useContactRelationsStore } from '../../store/modules/object-relations/contacts.js'

/**
 * ContactsTab — display + manage Nextcloud contacts linked to an OpenRegister object.
 *
 * Backed by the per-object endpoint `GET /api/objects/{register}/{schema}/{id}/contacts`
 * (ContactsController). Supports both link-existing and create-new flows on POST
 * (the controller decides based on payload shape: `{addressbookId, contactUri}`
 * vs `{fullName, ...}`). Falls back to a "Contacts not available" empty state
 * when the backend returns HTTP 501. Unlink is wired through
 * `DELETE …/contacts/{contactUid}`.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/contact-relations/spec.md
 */
export default {
	name: 'ContactsTab',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		AlertCircleOutline,
		AccountOutline,
		AccountPlus,
		AccountOff,
		CloseCircleOutline,
	},

	props: {
		register: {
			type: [String, Number],
			required: true,
		},
		schema: {
			type: [String, Number],
			required: true,
		},
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			error: false,
			errorMessage: '',
			store: useContactRelationsStore(),
		}
	},

	computed: {
		key() {
			return `${this.register}:${this.schema}:${this.objectId}`
		},
		contacts() {
			return this.store.byObject[this.key] || []
		},
		loading() {
			return !!this.store.loading[this.key]
		},
		contactsUnavailable() {
			return this.store.contactsUnavailable
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchContacts()
				}
			},
		},
	},

	methods: {
		t,

		async fetchContacts() {
			this.error = false
			this.errorMessage = ''
			try {
				await this.store.fetch(this.register, this.schema, this.objectId)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			}
		},

		async unlinkContact(contact) {
			const uid = contact.uid || contact.contactUid || contact.id
			try {
				await this.store.unlink(this.register, this.schema, this.objectId, uid)
				this.$emit('contacts-changed', this.contacts.length)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			}
		},

		openCreateDialog() {
			this.$emit('add-contact')
		},
	},
}
</script>

<style scoped>
.contacts-tab__toolbar {
	display: flex;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.contacts-tab__loading {
	display: flex;
	justify-content: center;
	padding: 2em 0;
}

.contacts-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.contacts-tab__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.contacts-tab__item:last-child {
	border-bottom: none;
}

.contacts-tab__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.contacts-tab__content {
	flex-grow: 1;
	min-width: 0;
}

.contacts-tab__name {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.contacts-tab__meta {
	display: flex;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.contacts-tab__email {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
