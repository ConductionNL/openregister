<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="emails-tab">
		<!-- Loading state -->
		<div v-if="loading" class="emails-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<TabErrorState
			v-else-if="error"
			:name="t('openregister', 'Failed to load linked emails')"
			:description="errorMessage" />

		<!-- Mail app not installed (HTTP 501 graceful degradation) -->
		<NcEmptyContent
			v-else-if="mailUnavailable"
			:name="t('openregister', 'Mail integration is not available')"
			:description="
				t(
					'openregister',
					'The Nextcloud Mail app is not installed or enabled on this server.',
				)
			">
			<template #icon>
				<EmailOffOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="emails.length === 0"
			:name="t('openregister', 'No emails linked to this object')"
			:description="
				t(
					'openregister',
					'Link an email from the Mail app sidebar to associate it with this object.',
				)
			">
			<template #icon>
				<EmailOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked emails list -->
		<ul v-else class="emails-tab__list">
			<li v-for="email in emails" :key="email.id" class="emails-tab__item">
				<div class="emails-tab__icon">
					<EmailOutline :size="20" />
				</div>
				<div class="emails-tab__content">
					<div class="emails-tab__subject">
						{{ email.subject || t('openregister', '(no subject)') }}
					</div>
					<div class="emails-tab__meta">
						<span v-if="email.fromEmail" class="emails-tab__from">
							{{
								email.fromName
									? `${email.fromName} <${email.fromEmail}>`
									: email.fromEmail
							}}
						</span>
						<span v-if="email.receivedAt" class="emails-tab__separator"
							>&middot;</span
						>
						<span v-if="email.receivedAt" class="emails-tab__date">
							{{ formatDate(email.receivedAt) }}
						</span>
					</div>
				</div>
				<NcButton
					variant="tertiary"
					:aria-label="t('openregister', 'Unlink email')"
					@click="unlinkEmail(email)">
					<template #icon>
						<CloseCircleOutline :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import EmailOffOutline from 'vue-material-design-icons/EmailOffOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import TabErrorState from '../shared/TabErrorState.vue'
import { relationTab } from './relationTab.js'

/**
 * EmailsTab — display + manage emails linked to an OpenRegister object.
 *
 * Reads from the per-object endpoint `GET /api/objects/{register}/{schema}/{id}/emails`
 * (provided by EmailsController; ADR-008 endpoint family). Falls back to a
 * "Mail not available" empty state when the backend returns HTTP 501 (Mail app
 * not installed). Unlink is wired through `DELETE …/emails/{emailId}` and emits
 * `emails-changed` so the parent (ViewObject) can refresh its counters.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/email-relations/spec.md
 */
export default {
	name: 'EmailsTab',

	components: {
		TabErrorState,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		EmailOutline,
		EmailOffOutline,
		CloseCircleOutline,
	},

	mixins: [relationTab],

	data() {
		return {
			loading: false,
			mailUnavailable: false,
			emails: [],
		}
	},

	methods: {
		/**
		 * @spec exclude API passthrough loading linked emails; email-relations contract owned by integration-email capability
		 */
		async load() {
			this.loading = true
			this.error = false
			this.mailUnavailable = false
			this.errorMessage = ''

			const url = this.objectApiUrl('/emails')

			try {
				const response = await axios.get(url)
				this.emails = response.data?.results || response.data || []
			} catch (err) {
				if (err.response && err.response.status === 501) {
					this.mailUnavailable = true
				} else {
					this.showFailure(err)
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} email
		 * @spec exclude API passthrough unlinking email + change emit; email-relations contract owned by integration-email capability
		 */
		async unlinkEmail(email) {
			const url = this.objectApiUrl('/emails/{emailId}', { emailId: email.id })

			try {
				await axios.delete(url)
				this.emails = this.emails.filter((e) => e.id !== email.id)
				this.$emit('emails-changed', this.emails.length)
			} catch (err) {
				this.showFailure(err)
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

@include relation-tab-list('emails-tab', 'subject', 'from', $toolbar: false);
</style>
