<template>
	<SettingsSection 
		:name="title"
		:description="description"
		:doc-url="docUrl"
		:loading="loading"
		loading-message="Loading version information...">
		<!-- Actions slot -->
		<template #actions>
			<!-- Update Button -->
			<NcButton
				v-if="showUpdateButton"
				:type="updateButtonType"
				:disabled="updateButtonDisabled"
				@click="handleUpdateClick">
				<template #icon>
					<NcLoadingIcon v-if="updating" :size="20" />
					<Check v-else-if="isUpToDate" :size="20" />
					<Update v-else :size="20" />
				</template>
				{{ updateButtonText }}
			</NcButton>

			<!-- Additional Actions (slot) -->
			<slot name="actions" />
		</template>

		<!-- Main content (only shown when not loading) -->
		<div v-if="!loading" class="version-info">
			<!-- Version card with gray background and emoji heading -->
			<div class="version-card">
				<h4>{{ cardTitle }}</h4>
				<div class="version-details">
					<!-- Application Name -->
					<div class="version-item">
						<span class="version-label">{{ labels.appName }}:</span>
						<span class="version-value">{{ appName }}</span>
					</div>

					<!-- Version -->
					<div class="version-item">
						<span class="version-label">{{ labels.version }}:</span>
						<span class="version-value">{{ appVersion }}</span>
					</div>

					<!-- Configured Version (if provided) -->
					<div v-if="configuredVersion" class="version-item">
						<span class="version-label">{{ labels.configuredVersion }}:</span>
						<span class="version-value">{{ configuredVersion }}</span>
					</div>

					<!-- Additional items -->
					<div v-for="item in additionalItems" :key="item.label" class="version-item">
						<span class="version-label">{{ item.label }}:</span>
						<span class="version-value" :class="item.statusClass">{{ item.value }}</span>
					</div>
					
					<!-- Optional additional items slot -->
					<slot name="additional-items" />
				</div>

				<!-- Optional footer slot -->
				<slot name="footer" />
			</div>

			<!-- Optional extra cards slot -->
			<slot name="extra-cards" />
		</div>
	</SettingsSection>
</template>

<script>
import SettingsSection from './SettingsSection.vue'
import { NcLoadingIcon, NcButton } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Update from 'vue-material-design-icons/Update.vue'

/**
 * @class VersionInfoCard
 * @module Components/Shared
 * @package OpenRegister
 * 
 * Reusable version information card component for displaying application information
 * across all Conduction Nextcloud apps (OpenRegister, OpenConnector, OpenCatalogi, SoftwareCatalog).
 * 
 * Features:
 * - Displays app name, version, and status
 * - Conditional update button (error style if needs update, disabled if up to date)
 * - Actions slot for additional buttons (e.g., Reset Auto-Config, Load Schemas)
 * - Clean, left-aligned layout matching Software Catalog style
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.conduction.nl
 */
export default {
	name: 'VersionInfoCard',

	components: {
		SettingsSection,
		NcLoadingIcon,
		NcButton,
		Check,
		Update,
	},

	props: {
		/**
		 * Section title
		 * @default 'Version Information'
		 */
		title: {
			type: String,
			default: 'Version Information',
		},

		/**
		 * Section description
		 */
		description: {
			type: String,
			default: 'Information about the current application installation',
		},

		/**
		 * Documentation URL (shows info icon next to title)
		 */
		docUrl: {
			type: String,
			default: '',
		},

		/**
		 * Card title/heading with emoji
		 * @default '📦 Application Information'
		 */
		cardTitle: {
			type: String,
			default: '📦 Application Information',
		},

		/**
		 * Application name
		 */
		appName: {
			type: String,
			required: true,
		},

		/**
		 * Application version
		 */
		appVersion: {
			type: String,
			required: true,
		},

		/**
		 * Configured version (optional, for apps that track configuration versions)
		 */
		configuredVersion: {
			type: String,
			default: '',
		},

		/**
		 * Whether the app is up to date
		 */
		isUpToDate: {
			type: Boolean,
			default: true,
		},

		/**
		 * Show update button
		 */
		showUpdateButton: {
			type: Boolean,
			default: false,
		},

		/**
		 * Update button text
		 */
		updateButtonText: {
			type: String,
			default: 'Update',
		},

		/**
		 * Updating state
		 */
		updating: {
			type: Boolean,
			default: false,
		},

		/**
		 * Additional version items to display
		 * Format: [{ label: 'Label', value: 'Value', statusClass: 'status-ok' (optional) }]
		 */
		additionalItems: {
			type: Array,
			default: () => [],
		},

		/**
		 * Loading state
		 */
		loading: {
			type: Boolean,
			default: false,
		},

		/**
		 * Custom labels for standard fields
		 */
		labels: {
			type: Object,
			default: () => ({
				appName: 'Application Name',
				version: 'Version',
				configuredVersion: 'Configured Version',
			}),
		},
	},

	emits: ['update'],

	computed: {

		/**
		 * Update button type based on status
		 * 
		 * @return {string}
		 */
		updateButtonType() {
			if (this.isUpToDate) {
				return 'success'
			}
			return 'error'
		},

		/**
		 * Update button should be disabled if up to date or updating
		 * 
		 * @return {boolean}
		 */
		updateButtonDisabled() {
			return this.isUpToDate || this.updating
		},
	},

	methods: {
		/**
		 * Handle update button click
		 * 
		 * @return {void}
		 */
		handleUpdateClick() {
			if (!this.updateButtonDisabled) {
				this.$emit('update')
			}
		},
	},
}
</script>

<style scoped>
/* Version info section - no need for action button styles, SettingsSection handles that */
.version-info {
	/* Content styling only */
}

/* Version card with gray background */
.version-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.version-card h4 {
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

/* Version details list */
.version-details {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

/* Individual version item - two columns layout */
.version-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.version-item:last-child {
	border-bottom: none;
}

.version-label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.version-value {
	font-family: 'Courier New', Courier, monospace;
	font-weight: 600;
	color: var(--color-main-text);
	font-size: 14px;
}

/* Status classes for version values */
.status-ok {
	color: var(--color-success);
	font-weight: 600;
}

.status-warning {
	color: var(--color-warning);
	font-weight: 600;
}

.status-error {
	color: var(--color-error);
	font-weight: 600;
}

/* Responsive design */
@media (max-width: 768px) {
	.version-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
	}

	.version-label {
		font-weight: 600;
	}

	.version-value {
		word-break: break-all;
	}
}
</style>

