<!--
	OpenProject Integration Tab

	Renders linked OpenProject work packages for an OR object.
	Features:
	- List linked WPs with status, assignee, progress badges
	- Link by work package id or URL
	- Unlink work packages
	- Auth-expired banner (AD-3: surfaced clearly, not silent 401)

	@spec openspec/changes/integration-openproject/tasks.md#task-5

	SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
	SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="cn-openproject-tab">
		<!-- Auth-expired banner (AD-3) -->
		<NcNoteCard
			v-if="authStatus === 'expired'"
			type="error"
			class="cn-openproject-tab__auth-banner">
			{{ t('openregister', 'Authorisation expired — reconnect in OpenConnector') }}
			<template #action>
				<NcButton
					:href="openConnectorUrl"
					native-type="a"
					target="_blank"
					rel="noopener noreferrer"
					type="secondary"
					@click.stop>
					{{ t('openregister', 'Reconnect') }}
				</NcButton>
			</template>
		</NcNoteCard>

		<!-- Source missing banner -->
		<NcNoteCard
			v-else-if="authStatus === 'unavailable'"
			type="warning"
			class="cn-openproject-tab__source-banner">
			{{ t('openregister', 'OpenProject is not configured. Set up an OpenConnector source named "openproject".') }}
		</NcNoteCard>

		<!-- Main content when auth is OK -->
		<template v-else>
			<!-- Link form -->
			<div class="cn-openproject-tab__link-form">
				<h3 class="cn-openproject-tab__section-title">
					{{ t('openregister', 'Link a Work Package') }}
				</h3>

				<div class="cn-openproject-tab__link-inputs">
					<NcTextField
						v-model="linkInput"
						:label="t('openregister', 'Work Package ID or URL')"
						:placeholder="t('openregister', 'e.g. 42 or https://openproject.example.com/work_packages/42')"
						:disabled="linking" />

					<NcButton
						:disabled="!linkInput.trim() || linking"
						:loading="linking"
						type="primary"
						@click="linkWorkPackage">
						{{ t('openregister', 'Link') }}
					</NcButton>
				</div>

				<NcNoteCard
					v-if="linkError"
					type="error"
					class="cn-openproject-tab__link-error">
					{{ linkError }}
				</NcNoteCard>
			</div>

			<!-- Work packages list -->
			<div class="cn-openproject-tab__list">
				<h3 class="cn-openproject-tab__section-title">
					{{ t('openregister', 'Linked Work Packages') }}
					<span v-if="!loading" class="cn-openproject-tab__count">({{ total }})</span>
				</h3>

				<NcLoadingIcon v-if="loading" :size="32" />

				<ul
					v-else-if="workPackages.length > 0"
					class="cn-openproject-tab__wp-list">
					<li
						v-for="wp in workPackages"
						:key="wp.id"
						class="cn-openproject-tab__wp-item">
						<div class="cn-openproject-tab__wp-header">
							<span class="cn-openproject-tab__wp-id">#{{ wp.id }}</span>
							<span
								:class="['cn-openproject-tab__wp-status', `cn-openproject-tab__wp-status--${statusSlug(wp.status)}`]">
								{{ wp.status || t('openregister', 'Unknown') }}
							</span>
						</div>

						<a
							:href="wp.url"
							class="cn-openproject-tab__wp-subject"
							target="_blank"
							rel="noopener noreferrer">
							{{ wp.subject }}
						</a>

						<div class="cn-openproject-tab__wp-meta">
							<span v-if="wp.assignee" class="cn-openproject-tab__wp-assignee">
								<NcAvatar
									:display-name="wp.assignee.name"
									:size="20"
									:show-user-status="false" />
								{{ wp.assignee.name }}
							</span>

							<span v-if="wp.percentageDone !== undefined" class="cn-openproject-tab__wp-progress">
								<progress
									:value="wp.percentageDone"
									max="100"
									class="cn-openproject-tab__wp-progress-bar" />
								{{ wp.percentageDone }}%
							</span>
						</div>

						<NcButton
							:title="t('openregister', 'Unlink work package')"
							:aria-label="t('openregister', 'Unlink work package #{id}', { id: wp.id })"
							type="tertiary-no-background"
							class="cn-openproject-tab__wp-unlink"
							@click="unlinkWorkPackage(wp)">
							<template #icon>
								<LinkOff :size="20" />
							</template>
						</NcButton>
					</li>
				</ul>

				<NcEmptyContent
					v-else-if="!loading"
					:name="t('openregister', 'No linked work packages')"
					:description="t('openregister', 'Link existing OpenProject work packages using the form above.')">
					<template #icon>
						<Briefcase :size="64" />
					</template>
				</NcEmptyContent>
			</div>
		</template>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/integration-openproject/tasks.md#task-5
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'

export default {
	name: 'CnOpenProjectTab',

	components: {
		NcButton,
		NcTextField,
		NcNoteCard,
		NcLoadingIcon,
		NcEmptyContent,
		NcAvatar,
		Briefcase,
		LinkOff,
	},

	props: {
		/**
		 * The OpenRegister object UUID.
		 */
		objectId: {
			type: String,
			required: true,
		},
		/**
		 * The register slug.
		 */
		register: {
			type: String,
			required: true,
		},
		/**
		 * The schema slug.
		 */
		schema: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			workPackages: [],
			total: 0,
			loading: false,
			authStatus: 'configured',
			linkInput: '',
			linking: false,
			linkError: null,
		}
	},

	computed: {
		/** URL to OpenConnector admin for reconnecting. */
		openConnectorUrl() {
			return '/index.php/apps/openconnector'
		},
	},

	mounted() {
		this.loadWorkPackages()
	},

	methods: {
		t,

		/**
		 * Load linked work packages from the API.
		 */
		async loadWorkPackages() {
			this.loading = true
			this.linkError = null

			try {
				const { data } = await axios.get(
					`/index.php/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/openproject`,
				)

				this.workPackages = data.items ?? []
				this.total = data.total ?? 0
				this.authStatus = data.authStatus ?? 'configured'
			} catch (error) {
				if (error.response?.status === 401 || error.response?.status === 403) {
					this.authStatus = 'expired'
				} else {
					this.authStatus = 'unavailable'
				}
				this.workPackages = []
				this.total = 0
			} finally {
				this.loading = false
			}
		},

		/**
		 * Link a work package by id or URL.
		 */
		async linkWorkPackage() {
			if (!this.linkInput.trim()) {
				return
			}

			this.linking = true
			this.linkError = null

			const input = this.linkInput.trim()
			const isUrl = input.startsWith('http')
			const payload = isUrl
				? { workPackageUrl: input }
				: { workPackageId: parseInt(input, 10) }

			try {
				await axios.post(
					`/index.php/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/openproject`,
					payload,
				)

				this.linkInput = ''
				await this.loadWorkPackages()
			} catch (error) {
				if (error.response?.data?.authStatus === 'expired') {
					this.authStatus = 'expired'
				} else {
					this.linkError = error.response?.data?.message
						?? t('openregister', 'Failed to link work package')
				}
			} finally {
				this.linking = false
			}
		},

		/**
		 * Unlink a work package.
		 *
		 * @param {object} wp - The work package object with id.
		 */
		async unlinkWorkPackage(wp) {
			try {
				await axios.delete(
					`/index.php/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/openproject/${encodeURIComponent(wp.id)}`,
				)

				await this.loadWorkPackages()
			} catch (error) {
				this.linkError = t('openregister', 'Failed to unlink work package')
			}
		},

		/**
		 * Convert status string to a CSS-safe slug.
		 *
		 * @param {string} status - The status string.
		 * @return {string} CSS slug.
		 */
		statusSlug(status) {
			return (status ?? '').toLowerCase().replace(/\s+/g, '-')
		},
	},
}
</script>

<style scoped>
.cn-openproject-tab {
	padding: var(--default-grid-baseline, 4px);
}

.cn-openproject-tab__auth-banner,
.cn-openproject-tab__source-banner {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
}

.cn-openproject-tab__section-title {
	font-size: 1rem;
	font-weight: 600;
	margin: calc(var(--default-grid-baseline, 4px) * 3) 0 calc(var(--default-grid-baseline, 4px) * 2);
	color: var(--color-main-text);
}

.cn-openproject-tab__count {
	font-weight: 400;
	color: var(--color-text-lighter);
	margin-left: 4px;
}

.cn-openproject-tab__link-inputs {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: flex-end;
}

.cn-openproject-tab__link-inputs .nc-text-field {
	flex: 1;
}

.cn-openproject-tab__link-error {
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.cn-openproject-tab__wp-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.cn-openproject-tab__wp-item {
	position: relative;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
	background: var(--color-background-hover);
}

.cn-openproject-tab__wp-header {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 4px));
}

.cn-openproject-tab__wp-id {
	color: var(--color-text-lighter);
	font-size: 0.85rem;
}

.cn-openproject-tab__wp-status {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.cn-openproject-tab__wp-status--in-progress {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.cn-openproject-tab__wp-status--closed,
.cn-openproject-tab__wp-status--done {
	background: var(--color-success);
	color: var(--color-success-text);
}

.cn-openproject-tab__wp-subject {
	display: block;
	font-weight: 500;
	color: var(--color-main-text);
	text-decoration: none;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
}

.cn-openproject-tab__wp-subject:hover {
	text-decoration: underline;
}

.cn-openproject-tab__wp-meta {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	font-size: 0.85rem;
	color: var(--color-text-lighter);
}

.cn-openproject-tab__wp-assignee {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px));
}

.cn-openproject-tab__wp-progress {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.cn-openproject-tab__wp-progress-bar {
	width: 80px;
	height: 6px;
}

.cn-openproject-tab__wp-unlink {
	position: absolute;
	top: calc(var(--default-grid-baseline, 4px) * 2);
	right: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
