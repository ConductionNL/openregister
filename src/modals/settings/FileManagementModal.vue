<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'File Vectorization')"
		size="large"
		@closing="$emit('closing')">
		<div class="file-config-content">
			<!-- Using Pre-Generated Chunks -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<div>
					<h4>{{ t('openregister', 'Using Pre-Generated Chunks') }}</h4>
					<p>
						{{
							t(
								'openregister',
								'Text chunks are generated during file extraction and stored in the database. Vectorization reads these pre-chunked files and converts them to embeddings.',
							)
						}}
					</p>
					<p>
						<strong>{{
							t(
								'openregister',
								'To adjust chunk size or strategy, go to file configuration → processing limits.',
							)
						}}</strong>
					</p>
				</div>
			</div>

			<!-- Vectorization Settings -->
			<div class="config-section">
				<h3>{{ t('openregister', '🔢 Vectorization Settings') }}</h3>

				<div class="form-group">
					<NcCheckboxRadioSwitch
						v-model="config.vectorizationEnabled"
						type="switch">
						{{
							t('openregister', 'Enable automatic file vectorization')
						}}
					</NcCheckboxRadioSwitch>
					<small>{{
						t(
							'openregister',
							'Automatically generate vector embeddings from text chunks when files are uploaded and processed',
						)
					}}</small>
				</div>
			</div>

			<!-- Batch Processing -->
			<div v-if="config.vectorizationEnabled" class="config-section">
				<h3>{{ t('openregister', '⚡ Batch Processing') }}</h3>

				<div class="form-group">
					<label for="batch-size">{{
						t('openregister', 'Batch Size')
					}}</label>
					<input
						id="batch-size"
						v-model.number="config.batchSize"
						type="number"
						min="1"
						max="100"
						step="1"
						class="input-field" />
					<small>{{
						t(
							'openregister',
							'Number of chunks to vectorize in one API call. Higher = faster but more memory. Recommended: 10-50.',
						)
					}}</small>
				</div>

				<div class="form-group">
					<NcCheckboxRadioSwitch v-model="config.autoRetry" type="switch">
						{{ t('openregister', 'Auto-retry failed vectorizations') }}
					</NcCheckboxRadioSwitch>
					<small>{{
						t(
							'openregister',
							'Automatically retry failed vectorization attempts (max 3 retries)',
						)
					}}</small>
				</div>
			</div>
		</div>

		<!-- Dialog Actions -->
		<template #actions>
			<NcButton @click="$emit('closing')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving"
				@click="saveConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{
					saving
						? t('openregister', 'Saving...')
						: t('openregister', 'Save Configuration')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
} from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'FileManagementModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		InformationOutline,
		ContentSave,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			saving: false,

			config: {
				vectorizationEnabled: true,
				batchSize: 25,
				autoRetry: true,
			},
		}
	},

	mounted() {
		this.loadConfiguration()
	},

	methods: {
		/**
		 * @spec exclude Modal data-load plumbing — loads file-vectorization config (stubbed).
		 */
		async loadConfiguration() {
			try {
				// TODO: Load vectorization config from backend
				this.loading = false
			} catch (error) {
				console.error('Failed to load configuration:', error)
				this.loading = false
			}
		},

		/**
		 * @spec exclude Modal save plumbing — posts file-vectorization config to the settings endpoint.
		 */
		async saveConfiguration() {
			this.saving = true

			try {
				await axios.post(
					generateUrl(
						'/apps/openregister/api/settings/file-vectorization',
					),
					this.config,
				)
				showSuccess(
					this.t(
						'openregister',
						'File vectorization configuration saved successfully',
					),
				)
				this.$emit('closing')
			} catch (error) {
				showError(
					this.t('openregister', 'Failed to save configuration: {error}', {
						error: error.response?.data?.error || error.message,
					}),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.file-config-content {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

@include info-box;

.config-section {
	margin-bottom: 32px;

	h3 {
		margin: 0 0 8px 0;
		font-size: 16px;
		font-weight: 600;
	}

	h4 {
		margin: 0 0 12px 0;
		font-size: 14px;
		font-weight: 500;
		color: var(--color-text-maxcontrast);
	}

	.section-description {
		margin: 0 0 16px 0;
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		line-height: 1.5;
	}
}

@include form-group;

.option-with-desc {
	display: flex;
	flex-direction: column;
	gap: 4px;

	strong {
		font-size: 14px;
	}

	small {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
	}
}

@include info-grid-rules;

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 16px;
}

.stat-card {
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	text-align: center;

	.stat-value {
		font-size: 32px;
		font-weight: 700;
		color: var(--color-primary-element);
		margin-bottom: 8px;
	}

	.stat-label {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		margin-bottom: 4px;
	}

	.stat-note {
		display: block;
		font-size: 11px;
		color: var(--color-text-lighter);
		font-style: italic;
		margin-top: 4px;
	}

	&.highlight {
		background: var(--color-primary-element-light);
		border: 2px solid var(--color-primary-element);

		.stat-value {
			color: var(--color-primary-element);
			font-size: 36px;
		}
	}
}

.model-breakdown {
	margin-top: 20px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: 8px;

	h4 {
		margin: 0 0 12px 0;
		font-size: 14px;
		font-weight: 600;
		color: var(--color-main-text);
	}

	.model-list {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.model-item {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 8px 12px;
		background: var(--color-background-hover);
		border-radius: 6px;

		.model-name {
			font-size: 13px;
			font-weight: 500;
			color: var(--color-main-text);
		}

		.model-count {
			font-size: 12px;
			color: var(--color-text-maxcontrast);
			font-weight: 600;
		}
	}
}

/* Actions layout */
@include deep-dialog-actions;

.actions-left {
	display: flex;
	gap: 8px;
	align-items: center;
}

@include actions-right;

@include media-max-width-768px;
</style>
