<template>
	<div class="extraction-tab">
		<!-- Loading state -->
		<div v-if="loading" class="extraction-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<NcEmptyContent v-else-if="error"
			:name="t('openregister', 'Failed to load extraction data')"
			:description="errorMessage">
			<template #icon>
				<span class="material-design-icon" v-html="alertCircleIcon" />
			</template>
		</NcEmptyContent>

		<!-- No extraction data -->
		<NcEmptyContent v-else-if="status.extractionStatus === 'none'"
			:name="t('openregister', 'No extraction data available for this file')">
			<template #icon>
				<span class="material-design-icon" v-html="fileSearchIcon" />
			</template>
			<template #action>
				<NcButton :disabled="extracting"
					type="primary"
					@click="triggerExtraction">
					<template v-if="extracting" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('openregister', 'Extract Now') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- Extraction data display -->
		<div v-else class="extraction-tab__content">
			<!-- Status badge -->
			<div class="extraction-tab__row">
				<span class="extraction-tab__label">
					{{ t('openregister', 'Status') }}
				</span>
				<span class="extraction-tab__value">
					<span class="extraction-tab__badge extraction-tab__badge--status">
						{{ statusLabel }}
					</span>
				</span>
			</div>

			<!-- Chunk count -->
			<div class="extraction-tab__row">
				<span class="extraction-tab__label">
					{{ t('openregister', 'Text chunks') }}
				</span>
				<span class="extraction-tab__value">
					{{ status.chunkCount }}
				</span>
			</div>

			<!-- Entity count (expandable) -->
			<div class="extraction-tab__row extraction-tab__row--expandable">
				<button class="extraction-tab__expand-button"
					:aria-expanded="String(entitiesExpanded)"
					@click="entitiesExpanded = !entitiesExpanded">
					<span class="extraction-tab__label">
						{{ t('openregister', 'Entities detected') }}
					</span>
					<span class="extraction-tab__value">
						{{ status.entityCount }}
						<span class="extraction-tab__chevron" :class="{ 'extraction-tab__chevron--open': entitiesExpanded }">
							&#9656;
						</span>
					</span>
				</button>

				<!-- Entity type breakdown -->
				<ul v-if="entitiesExpanded && status.entities.length > 0" class="extraction-tab__entity-list">
					<li v-for="entity in status.entities"
						:key="entity.type"
						class="extraction-tab__entity-item">
						<span class="extraction-tab__entity-type">{{ entity.type }}</span>
						<span class="extraction-tab__entity-count">{{ entity.count }}</span>
					</li>
				</ul>
			</div>

			<!-- Risk level -->
			<div class="extraction-tab__row">
				<span class="extraction-tab__label">
					{{ t('openregister', 'Risk level') }}
				</span>
				<span class="extraction-tab__value">
					<span class="extraction-tab__badge"
						:class="riskBadgeClass"
						:title="riskLabel">
						{{ riskLabel }}
					</span>
				</span>
			</div>

			<!-- Extracted at -->
			<div v-if="status.extractedAt" class="extraction-tab__row">
				<span class="extraction-tab__label">
					{{ t('openregister', 'Extracted at') }}
				</span>
				<span class="extraction-tab__value">
					{{ formattedDate }}
				</span>
			</div>

			<!-- Anonymization status -->
			<div class="extraction-tab__row">
				<span class="extraction-tab__label">
					{{ t('openregister', 'Anonymized') }}
				</span>
				<span class="extraction-tab__value">
					<span v-if="status.anonymized" class="extraction-tab__badge extraction-tab__badge--success">
						{{ t('openregister', 'Yes') }}
					</span>
					<span v-else class="extraction-tab__badge extraction-tab__badge--neutral">
						{{ t('openregister', 'No') }}
					</span>
				</span>
			</div>

			<!-- Re-extract button for failed extractions -->
			<div v-if="status.extractionStatus === 'failed'" class="extraction-tab__actions">
				<NcButton :disabled="extracting"
					type="primary"
					@click="triggerExtraction">
					<template v-if="extracting" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('openregister', 'Extract Now') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

// alert-circle-outline SVG
const alertCircleIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11,15H13V17H11V15M11,7H13V13H11V7M12,2C6.47,2 2,6.5 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20Z" /></svg>'

// file-search-outline SVG
const fileSearchIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M14 2H6C4.89 2 4 2.89 4 4V20C4 21.11 4.89 22 6 22H13.81C13.28 21.09 13 20.05 13 19C13 15.69 15.69 13 19 13C19.34 13 19.67 13.03 20 13.08V8L14 2M13 9V3.5L18.5 9H13M20.31 18.9C20.75 18.21 21 17.38 21 16.5C21 14.57 19.43 13 17.5 13S14 14.57 14 16.5 15.57 20 17.5 20C18.37 20 19.19 19.75 19.88 19.32L23 22.39L24.39 21L21.32 17.88Z" /></svg>'

export default {
	name: 'ExtractionTab',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		fileId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			extracting: false,
			error: false,
			errorMessage: '',
			entitiesExpanded: false,
			status: {
				fileId: 0,
				extractionStatus: 'none',
				chunkCount: 0,
				entityCount: 0,
				riskLevel: 'none',
				extractedAt: null,
				entities: [],
				anonymized: false,
				anonymizedAt: null,
				anonymizedFileId: null,
			},
			alertCircleIcon,
			fileSearchIcon,
		}
	},

	computed: {
		/**
		 * Human-readable extraction status label.
		 *
		 * @return {string}
		 */
		statusLabel() {
			const labels = {
				none: t('openregister', 'Not extracted'),
				pending: t('openregister', 'Pending'),
				processing: t('openregister', 'Processing'),
				completed: t('openregister', 'Completed'),
				failed: t('openregister', 'Failed'),
			}
			return labels[this.status.extractionStatus] || this.status.extractionStatus
		},

		/**
		 * Human-readable risk level label.
		 *
		 * @return {string}
		 */
		riskLabel() {
			const labels = {
				none: t('openregister', 'None'),
				low: t('openregister', 'Low'),
				medium: t('openregister', 'Medium'),
				high: t('openregister', 'High'),
				very_high: t('openregister', 'Very high'),
			}
			return labels[this.status.riskLevel] || this.status.riskLevel
		},

		/**
		 * CSS class for risk level badge.
		 *
		 * @return {string}
		 */
		riskBadgeClass() {
			const classes = {
				none: 'extraction-tab__badge--neutral',
				low: 'extraction-tab__badge--success',
				medium: 'extraction-tab__badge--warning',
				high: 'extraction-tab__badge--error',
				very_high: 'extraction-tab__badge--critical',
			}
			return classes[this.status.riskLevel] || 'extraction-tab__badge--neutral'
		},

		/**
		 * Formatted extraction date.
		 *
		 * @return {string}
		 */
		formattedDate() {
			if (!this.status.extractedAt) {
				return ''
			}
			try {
				return new Date(this.status.extractedAt).toLocaleString()
			} catch {
				return this.status.extractedAt
			}
		},
	},

	watch: {
		fileId: {
			handler(newVal) {
				if (newVal) {
					this.fetchExtractionStatus()
				}
			},
			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Fetch extraction status from the API.
		 */
		async fetchExtractionStatus() {
			this.loading = true
			this.error = false
			this.errorMessage = ''

			try {
				const url = generateUrl('/apps/openregister/api/files/{fileId}/extraction-status', {
					fileId: this.fileId,
				})
				const response = await axios.get(url)

				if (response.data?.success) {
					this.status = response.data.data
				} else {
					this.error = true
					this.errorMessage = response.data?.error || t('openregister', 'Unknown error')
				}
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message
				console.error('[ExtractionTab] Failed to fetch extraction status:', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger text extraction for this file.
		 */
		async triggerExtraction() {
			this.extracting = true

			try {
				const url = generateUrl('/apps/openregister/api/files/{fileId}/extract', {
					fileId: this.fileId,
				})
				await axios.post(url)

				// Refresh the extraction status after triggering extraction.
				await this.fetchExtractionStatus()
			} catch (err) {
				console.error('[ExtractionTab] Extraction failed:', err)
			} finally {
				this.extracting = false
			}
		},
	},
}
</script>

<style scoped>
.extraction-tab {
	padding: 10px;
}

.extraction-tab__loading {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 100px;
}

.extraction-tab__content {
	display: flex;
	flex-direction: column;
	gap: 0;
}

.extraction-tab__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.extraction-tab__row--expandable {
	flex-direction: column;
	align-items: stretch;
}

.extraction-tab__expand-button {
	display: flex;
	justify-content: space-between;
	align-items: center;
	width: 100%;
	background: none;
	border: none;
	padding: 0;
	cursor: pointer;
	color: var(--color-main-text);
	font: inherit;
}

.extraction-tab__expand-button:hover {
	color: var(--color-primary-element);
}

.extraction-tab__expand-button:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: var(--border-radius, 3px);
}

.extraction-tab__chevron {
	display: inline-block;
	transition: transform 0.2s ease;
	margin-left: 4px;
}

.extraction-tab__chevron--open {
	transform: rotate(90deg);
}

.extraction-tab__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.extraction-tab__value {
	text-align: right;
}

.extraction-tab__entity-list {
	list-style: none;
	margin: 8px 0 0 0;
	padding: 0 0 0 16px;
}

.extraction-tab__entity-item {
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.extraction-tab__entity-type {
	font-family: monospace;
}

.extraction-tab__entity-count {
	font-weight: 600;
}

/* Badge styles using CSS variables — no hardcoded colors */
.extraction-tab__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 0.85em;
	font-weight: 600;
	line-height: 1.4;
}

.extraction-tab__badge--neutral {
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.extraction-tab__badge--status {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

.extraction-tab__badge--success {
	background-color: var(--color-success);
	color: var(--color-primary-element-text, #fff);
}

.extraction-tab__badge--warning {
	background-color: var(--color-warning);
	color: var(--color-warning-text, #000);
}

.extraction-tab__badge--error {
	background-color: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}

.extraction-tab__badge--critical {
	background-color: var(--color-error);
	color: var(--color-primary-element-text, #fff);
	border: 2px solid currentColor;
}

.extraction-tab__actions {
	padding: 16px 12px;
	display: flex;
	justify-content: center;
}

.material-design-icon {
	display: inline-flex;
}

.material-design-icon :deep(svg) {
	width: 64px;
	height: 64px;
	fill: currentColor;
}
</style>
