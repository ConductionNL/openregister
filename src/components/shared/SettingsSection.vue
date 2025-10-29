<template>
	<NcSettingsSection 
		:name="name"
		:description="description"
		:doc-url="docUrl">
		<!-- Action buttons positioned top-right -->
		<div v-if="$slots.actions" class="action-buttons">
			<slot name="actions" />
		</div>

		<!-- Section Description (optional detailed description box) -->
		<div v-if="$slots.description || detailedDescription" class="section-description-full">
			<slot name="description">
				<p v-if="detailedDescription" class="main-description" v-html="detailedDescription" />
			</slot>
		</div>

		<!-- Main Content -->
		<div class="section-content">
			<slot />
		</div>

			<!-- Loading State -->
			<div v-if="loading" class="loading-section">
				<NcLoadingIcon :size="32" />
				<p>{{ loadingMessage }}</p>
			</div>

			<!-- Error State -->
			<div v-if="error && !loading" class="error-section">
				<p class="error-message">
					❌ {{ errorMessage }}
				</p>
				<NcButton v-if="onRetry" type="primary" @click="onRetry">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ retryButtonText }}
				</NcButton>
			</div>

			<!-- Empty State -->
			<div v-if="empty && !loading && !error" class="empty-section">
				<slot name="empty">
					<div class="empty-content">
						<InformationOutline :size="48" />
						<p>{{ emptyMessage }}</p>
					</div>
				</slot>
			</div>

		<!-- Footer Actions -->
		<div v-if="$slots.footer" class="section-footer">
			<slot name="footer" />
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcLoadingIcon, NcButton } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

/**
 * @class SettingsSection
 * @module Components/Shared
 * @package OpenRegister
 * 
 * Reusable settings section wrapper component that provides consistent layout and functionality
 * across all Conduction Nextcloud apps (OpenRegister, OpenConnector, OpenCatalogi, SoftwareCatalog).
 * 
 * Features:
 * - Consistent action menu positioning (top-right, aligned with title)
 * - Built-in loading, error, and empty states
 * - Flexible slots for customization
 * - Responsive design
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.conduction.nl
 */
export default {
	name: 'SettingsSection',

	components: {
		NcSettingsSection,
		NcLoadingIcon,
		NcButton,
		Refresh,
		InformationOutline,
	},

	props: {
		/**
		 * Section name/title
		 */
		name: {
			type: String,
			required: true,
		},

		/**
		 * Brief section description (shown under title)
		 */
		description: {
			type: String,
			default: '',
		},

		/**
		 * Detailed description (shown in gray box)
		 */
		detailedDescription: {
			type: String,
			default: '',
		},

		/**
		 * Documentation URL
		 */
		docUrl: {
			type: String,
			default: '',
		},

		/**
		 * Loading state
		 */
		loading: {
			type: Boolean,
			default: false,
		},

		/**
		 * Loading message
		 */
		loadingMessage: {
			type: String,
			default: 'Loading...',
		},

		/**
		 * Error state
		 */
		error: {
			type: Boolean,
			default: false,
		},

		/**
		 * Error message
		 */
		errorMessage: {
			type: String,
			default: 'An error occurred',
		},

		/**
		 * Retry callback function
		 */
		onRetry: {
			type: Function,
			default: null,
		},

		/**
		 * Retry button text
		 */
		retryButtonText: {
			type: String,
			default: 'Retry',
		},

		/**
		 * Empty state
		 */
		empty: {
			type: Boolean,
			default: false,
		},

		/**
		 * Empty state message
		 */
		emptyMessage: {
			type: String,
			default: 'No data available',
		},

		/**
		 * Additional CSS classes for the wrapper
		 */
		wrapperClass: {
			type: String,
			default: '',
		},
	},

	computed: {
		// Removed hasActions - not needed anymore
	},
}
</script>

<style scoped>
/* 
 * Action buttons positioned within NcSettingsSection's settings-section__desc div
 * Using deep selector to target Nextcloud Vue's internal structure
 */
.action-buttons {
	display: flex;
	gap: 0.5rem;
	align-items: center;
	justify-content: flex-end;
	float: right;
	margin-top: -66px;
	margin-bottom: 26px;
	position: relative;
	z-index: 10;
}

/* Content area */
.section-content {
	clear: both;
}

/* Loading state */
.loading-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px 20px;
	gap: 16px;
}

.loading-section p {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

/* Error state */
.error-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 32px 20px;
	gap: 16px;
	background: var(--color-error-light);
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large);
}

.error-message {
	color: var(--color-error);
	font-weight: 500;
	margin: 0;
}

/* Empty state */
.empty-section {
	padding: 40px 20px;
}

.empty-content {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	color: var(--color-text-maxcontrast);
}

.empty-content p {
	margin: 0;
	font-size: 14px;
}

/* Footer */
.section-footer {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

/* Responsive design */
@media (max-width: 768px) {
	.section-header-inline {
		position: static;
		margin-bottom: 1rem;
		flex-direction: column;
		align-items: stretch;
	}

	.button-group {
		justify-content: center;
	}
}
</style>

