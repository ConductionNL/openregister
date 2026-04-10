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
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- No extraction data -->
		<NcEmptyContent v-else-if="status.extractionStatus === 'none'"
			:name="t('openregister', 'No extraction data available for this file')">
			<template #icon>
				<FileSearchOutline :size="44" />
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
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import FileSearchOutline from 'vue-material-design-icons/FileSearchOutline.vue'

export default {
	name: 'ExtractionTab',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
		FileSearchOutline,
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
