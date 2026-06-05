<!--
	OpenProject Integration Widget Card

	Renders across four surfaces (ADR-019 AD-6/AD-18):
	- user-dashboard: open WPs assigned to the user across linked objects
	- app-dashboard: open WPs scoped to the current app context
	- detail-page: full WP list with status badges
	- single-entity: compact WP chip with status badge (for referenceType: 'openproject')

	@spec openspec/changes/integration-openproject/tasks.md#task-6

	SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
	SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div :class="['cn-openproject-card', `cn-openproject-card--${surface}`]">
		<!-- Auth expired: always show reconnect prompt -->
		<div v-if="authStatus === 'expired'" class="cn-openproject-card__auth-expired">
			<span class="cn-openproject-card__auth-text">
				{{ t('openregister', 'OpenProject: authorisation expired') }}
			</span>
			<a
				:href="openConnectorUrl"
				target="_blank"
				rel="noopener noreferrer"
				class="cn-openproject-card__reconnect-link">
				{{ t('openregister', 'Reconnect') }}
			</a>
		</div>

		<!-- Source unavailable -->
		<div v-else-if="authStatus === 'unavailable'" class="cn-openproject-card__unavailable">
			{{ t('openregister', 'OpenProject not configured') }}
		</div>

		<!-- single-entity surface: compact WP chip -->
		<template v-else-if="surface === 'single-entity'">
			<a
				v-if="singleWp"
				:href="singleWp.url"
				target="_blank"
				rel="noopener noreferrer"
				class="cn-openproject-card__chip">
				<Briefcase :size="16" class="cn-openproject-card__chip-icon" />
				<span class="cn-openproject-card__chip-id">#{{ singleWp.id }}</span>
				<span class="cn-openproject-card__chip-subject">{{ singleWp.subject }}</span>
				<span
					:class="['cn-openproject-card__chip-status', `cn-openproject-card__chip-status--${statusSlug(singleWp.status)}`]">
					{{ singleWp.status }}
				</span>
			</a>
			<span v-else-if="loading" class="cn-openproject-card__chip cn-openproject-card__chip--loading">
				<NcLoadingIcon :size="16" />
			</span>
			<span v-else class="cn-openproject-card__chip cn-openproject-card__chip--empty">
				{{ t('openregister', 'No work package') }}
			</span>
		</template>

		<!-- detail-page surface: full WP list with status -->
		<template v-else-if="surface === 'detail-page'">
			<NcLoadingIcon v-if="loading" :size="32" />
			<ul v-else-if="workPackages.length > 0" class="cn-openproject-card__wp-list">
				<li
					v-for="wp in workPackages"
					:key="wp.id"
					class="cn-openproject-card__wp-row">
					<Briefcase :size="16" class="cn-openproject-card__row-icon" />
					<a
						:href="wp.url"
						target="_blank"
						rel="noopener noreferrer"
						class="cn-openproject-card__row-subject">
						#{{ wp.id }} {{ wp.subject }}
					</a>
					<span
						:class="['cn-openproject-card__row-status', `cn-openproject-card__row-status--${statusSlug(wp.status)}`]">
						{{ wp.status }}
					</span>
					<span v-if="wp.percentageDone !== undefined" class="cn-openproject-card__row-progress">
						{{ wp.percentageDone }}%
					</span>
				</li>
			</ul>
			<p v-else-if="!loading" class="cn-openproject-card__empty">
				{{ t('openregister', 'No linked work packages') }}
			</p>
		</template>

		<!-- dashboard surfaces: open WPs summary -->
		<template v-else>
			<NcLoadingIcon v-if="loading" :size="32" />
			<template v-else>
				<div class="cn-openproject-card__dashboard-header">
					<Briefcase :size="20" />
					<span class="cn-openproject-card__dashboard-title">
						{{ t('openregister', 'Open Work Packages') }}
					</span>
					<span class="cn-openproject-card__dashboard-count">{{ total }}</span>
				</div>

				<ul v-if="workPackages.length > 0" class="cn-openproject-card__wp-list">
					<li
						v-for="wp in workPackages"
						:key="wp.id"
						class="cn-openproject-card__wp-row">
						<a
							:href="wp.url"
							target="_blank"
							rel="noopener noreferrer"
							class="cn-openproject-card__row-subject">
							#{{ wp.id }} {{ wp.subject }}
						</a>
						<span
							:class="['cn-openproject-card__row-status', `cn-openproject-card__row-status--${statusSlug(wp.status)}`]">
							{{ wp.status }}
						</span>
					</li>
				</ul>

				<p v-else class="cn-openproject-card__empty">
					{{ t('openregister', 'No open work packages assigned to you') }}
				</p>
			</template>
		</template>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/integration-openproject/tasks.md#task-6
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'

export default {
	name: 'CnOpenProjectCard',

	components: {
		NcLoadingIcon,
		Briefcase,
	},

	props: {
		/**
		 * Rendering surface. One of: user-dashboard, app-dashboard, detail-page, single-entity.
		 */
		surface: {
			type: String,
			required: true,
			validator: (v) => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'].includes(v),
		},
		/**
		 * The OpenRegister object UUID (required for detail-page and single-entity).
		 */
		objectId: {
			type: String,
			default: null,
		},
		/**
		 * Register slug (required for detail-page and single-entity).
		 */
		register: {
			type: String,
			default: null,
		},
		/**
		 * Schema slug (required for detail-page and single-entity).
		 */
		schema: {
			type: String,
			default: null,
		},
		/**
		 * For single-entity: the work package id to display.
		 */
		referenceId: {
			type: [String, Number],
			default: null,
		},
	},

	data() {
		return {
			workPackages: [],
			total: 0,
			loading: false,
			authStatus: 'configured',
		}
	},

	computed: {
		/** URL to OpenConnector admin for reconnecting. */
		openConnectorUrl() {
			return '/index.php/apps/openconnector'
		},

		/** First work package (for single-entity chip). */
		singleWp() {
			return this.workPackages[0] ?? null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load work packages based on surface context.
		 */
		async load() {
			this.loading = true

			try {
				if (this.surface === 'single-entity' || this.surface === 'detail-page') {
					await this.loadForObject()
				} else {
					await this.loadDashboard()
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load work packages for a specific object (detail-page / single-entity).
		 */
		async loadForObject() {
			if (!this.objectId || !this.register || !this.schema) {
				return
			}

			try {
				const { data } = await axios.get(
					`/index.php/apps/openregister/api/objects/${encodeURIComponent(this.register)}/${encodeURIComponent(this.schema)}/${encodeURIComponent(this.objectId)}/openproject`,
				)

				this.workPackages = data.items ?? []
				this.total = data.total ?? 0
				this.authStatus = data.authStatus ?? 'configured'
			} catch (error) {
				this.authStatus = error.response?.status === 401 ? 'expired' : 'unavailable'
			}
		},

		/**
		 * Load open WPs for dashboard surfaces (user-dashboard / app-dashboard).
		 */
		async loadDashboard() {
			try {
				const params = {
					surface: this.surface,
					filter: 'open',
				}

				const { data } = await axios.get(
					'/index.php/apps/openregister/api/integrations/openproject/dashboard',
					{ params },
				)

				this.workPackages = data.items ?? []
				this.total = data.total ?? 0
				this.authStatus = data.authStatus ?? 'configured'
			} catch (error) {
				this.authStatus = error.response?.status === 401 ? 'expired' : 'unavailable'
			}
		},

		/**
		 * Convert a status string to a CSS-safe slug.
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
/* ── base ── */
.cn-openproject-card {
	padding: calc(var(--default-grid-baseline, 4px) * 2);
}

/* ── auth states ── */
.cn-openproject-card__auth-expired,
.cn-openproject-card__unavailable {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	font-size: 0.85rem;
	color: var(--color-text-lighter);
}

.cn-openproject-card__reconnect-link {
	color: var(--color-primary);
	text-decoration: underline;
}

/* ── single-entity chip ── */
.cn-openproject-card__chip {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px));
	padding: 2px 8px;
	border-radius: 12px;
	border: 1px solid var(--color-border);
	background: var(--color-background-hover);
	text-decoration: none;
	color: var(--color-main-text);
	font-size: 0.85rem;
	white-space: nowrap;
	max-width: 100%;
	overflow: hidden;
}

.cn-openproject-card__chip-icon {
	flex-shrink: 0;
}

.cn-openproject-card__chip-id {
	color: var(--color-text-lighter);
	flex-shrink: 0;
}

.cn-openproject-card__chip-subject {
	overflow: hidden;
	text-overflow: ellipsis;
}

.cn-openproject-card__chip-status {
	font-size: 0.7rem;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-dark);
	flex-shrink: 0;
}

/* ── dashboard header ── */
.cn-openproject-card__dashboard-header {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
}

.cn-openproject-card__dashboard-title {
	flex: 1;
	font-weight: 600;
}

.cn-openproject-card__dashboard-count {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-radius: 50%;
	min-width: 20px;
	height: 20px;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 0.75rem;
	padding: 0 4px;
}

/* ── work package rows ── */
.cn-openproject-card__wp-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.cn-openproject-card__wp-row {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.cn-openproject-card__wp-row:last-child {
	border-bottom: none;
}

.cn-openproject-card__row-icon {
	flex-shrink: 0;
	color: var(--color-text-lighter);
}

.cn-openproject-card__row-subject {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-main-text);
	text-decoration: none;
	font-size: 0.9rem;
}

.cn-openproject-card__row-subject:hover {
	text-decoration: underline;
}

.cn-openproject-card__row-status {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-dark);
	flex-shrink: 0;
}

.cn-openproject-card__row-status--in-progress {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.cn-openproject-card__row-status--closed,
.cn-openproject-card__row-status--done {
	background: var(--color-success);
	color: var(--color-success-text);
}

.cn-openproject-card__row-progress {
	font-size: 0.75rem;
	color: var(--color-text-lighter);
	flex-shrink: 0;
}

/* ── empty state ── */
.cn-openproject-card__empty {
	color: var(--color-text-lighter);
	font-style: italic;
	text-align: center;
	padding: calc(var(--default-grid-baseline, 4px) * 4) 0;
}
</style>
