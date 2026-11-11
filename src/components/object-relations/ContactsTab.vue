<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="contacts-tab">
		<!-- Toolbar -->
		<div v-if="!loading && !contactsUnavailable" class="contacts-tab__toolbar">
			<NcButton variant="primary" @click="openCreateDialog">
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
		<TabErrorState
			v-else-if="error"
			:name="t('openregister', 'Failed to load linked contacts')"
			:description="errorMessage" />

		<!-- Contacts app missing (HTTP 501 graceful degradation) -->
		<NcEmptyContent
			v-else-if="contactsUnavailable"
			:name="t('openregister', 'Contacts integration is not available')"
			:description="
				t(
					'openregister',
					'The Nextcloud Contacts app is not installed or enabled on this server.',
				)
			">
			<template #icon>
				<AccountOff :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="items.length === 0"
			:name="t('openregister', 'No contacts linked to this object')"
			:description="
				t(
					'openregister',
					'Add a contact from any of your address books to associate it with this object.',
				)
			">
			<template #icon>
				<AccountOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked contacts list -->
		<ul v-else class="contacts-tab__list">
			<li
				v-for="contact in items"
				:key="contact.uid || contact.contactUid || contact.id"
				class="contacts-tab__item">
				<div class="contacts-tab__icon">
					<AccountOutline :size="20" />
				</div>
				<div class="contacts-tab__content">
					<div class="contacts-tab__name">
						{{
							contact.fullName
							|| contact.displayName
							|| contact.email
							|| t('openregister', '(unnamed)')
						}}
					</div>
					<div class="contacts-tab__meta">
						<span v-if="contact.email" class="contacts-tab__email">{{
							contact.email
						}}</span>
						<span v-if="contact.role" class="contacts-tab__separator"
							>&middot;</span
						>
						<span v-if="contact.role" class="contacts-tab__role">{{
							contact.role
						}}</span>
					</div>
				</div>
				<UnlinkButton
					:label="t('openregister', 'Remove contact')"
					@unlink="unlinkContact(contact)" />
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AccountOff from 'vue-material-design-icons/AccountOff.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import TabErrorState from '../shared/TabErrorState.vue'
import UnlinkButton from './UnlinkButton.vue'
import { useContactRelationsStore } from '../../store/modules/object-relations/contacts.js'
import { storeRelationTab } from './relationTab.js'

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
		TabErrorState,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		AccountOutline,
		AccountPlus,
		AccountOff,
		UnlinkButton,
	},

	mixins: [storeRelationTab(useContactRelationsStore)],

	computed: {
		/**
		 * @spec exclude computed read of integration-availability flag from store, UI plumbing
		 */
		contactsUnavailable() {
			return this.store.contactsUnavailable
		},
	},

	methods: {
		/**
		 * @param {object} contact
		 * @spec exclude store passthrough unlinking contact + change emit; contact-relations contract owned by integration-contacts capability
		 */
		unlinkContact(contact) {
			return this.unlink(
				contact.uid || contact.contactUid || contact.id,
				'contacts-changed',
			)
		},

		/**
		 * @spec exclude emit UI handler opening add-contact dialog, UI plumbing
		 */
		openCreateDialog() {
			this.$emit('add-contact')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

@include relation-tab-list('contacts-tab', 'name', 'email');
</style>
