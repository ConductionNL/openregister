<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="emails-tab">
		<!-- Loading state -->
		<div v-if="loading" class="emails-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<NcEmptyContent v-else-if="error"
			:name="t('openregister', 'Failed to load linked emails')"
			:description="errorMessage">
			<template #icon>
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Mail app not installed (HTTP 501 graceful degradation) -->
		<NcEmptyContent v-else-if="mailUnavailable"
			:name="t('openregister', 'Mail integration is not available')"
			:description="t('openregister', 'The Nextcloud Mail app is not installed or enabled on this server.')">
			<template #icon>
				<EmailOffOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent v-else-if="emails.length === 0"
			:name="t('openregister', 'No emails linked to this object')"
			:description="t('openregister', 'Link an email from the Mail app sidebar to associate it with this object.')">
			<template #icon>
				<EmailOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked emails list -->
		<ul v-else class="emails-tab__list">
			<li v-for="email in emails"
				:key="email.id"
				class="emails-tab__item">
				<div class="emails-tab__icon">
					<EmailOutline :size="20" />
				</div>
				<div class="emails-tab__content">
					<div class="emails-tab__subject">
						{{ email.subject || t('openregister', '(no subject)') }}
					</div>
					<div class="emails-tab__meta">
						<span v-if="email.fromEmail" class="emails-tab__from">
							{{ email.fromName ? `${email.fromName} <${email.fromEmail}>` : email.fromEmail }}
						</span>
						<span v-if="email.receivedAt" class="emails-tab__separator">&middot;</span>
						<span v-if="email.receivedAt" class="emails-tab__date">
							{{ formatDate(email.receivedAt) }}
						</span>
					</div>
				</div>
				<NcButton type="tertiary"
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
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EmailOffOutline from 'vue-material-design-icons/EmailOffOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'

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
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		AlertCircleOutline,
		EmailOutline,
		EmailOffOutline,
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
			loading: false,
			error: false,
			errorMessage: '',
			mailUnavailable: false,
			emails: [],
		}
	},

	watch: {
		objectId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchEmails()
				}
			},
		},
	},

	methods: {
		t,

		async fetchEmails() {
			this.loading = true
			this.error = false
			this.mailUnavailable = false
			this.errorMessage = ''

			const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/emails', {
				register: this.register,
				schema: this.schema,
				id: this.objectId,
			})

			try {
				const response = await axios.get(url)
				this.emails = response.data?.results || response.data || []
			} catch (err) {
				if (err.response && err.response.status === 501) {
					this.mailUnavailable = true
				} else {
					this.error = true
					this.errorMessage = err.response?.data?.error || err.message || ''
				}
			} finally {
				this.loading = false
			}
		},

		async unlinkEmail(email) {
			const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/emails/{emailId}', {
				register: this.register,
				schema: this.schema,
				id: this.objectId,
				emailId: email.id,
			})

			try {
				await axios.delete(url)
				this.emails = this.emails.filter(e => e.id !== email.id)
				this.$emit('emails-changed', this.emails.length)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			}
		},

		formatDate(value) {
			if (!value) {
				return ''
			}

			try {
				const d = new Date(value)
				if (Number.isNaN(d.getTime())) {
					return value
				}

				return d.toLocaleString()
			} catch (e) {
				return value
			}
		},
	},
}
</script>

<style scoped>
.emails-tab__loading {
	display: flex;
	justify-content: center;
	padding: 2em 0;
}

.emails-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.emails-tab__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.emails-tab__item:last-child {
	border-bottom: none;
}

.emails-tab__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.emails-tab__content {
	flex-grow: 1;
	min-width: 0;
}

.emails-tab__subject {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.emails-tab__meta {
	display: flex;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.emails-tab__from {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
