<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'Object Vectorization')"
		size="large"
		@closing="$emit('closing')">
		<div class="object-config-content">
			<!-- Info box -->
			<div class="info-box">
				<InformationOutline :size="20" />
				<p>
					{{
						t(
							'openregister',
							'Configure how database objects are converted into vector embeddings for semantic search. Objects are directly vectorized without needing text extraction.',
						)
					}}
				</p>
			</div>

			<!-- Important Note -->
			<div class="info-box warning">
				<AlertCircle :size="20" />
				<div>
					<strong>{{ t('openregister', 'Prerequisites') }}</strong>
					<p>
						{{
							t(
								'openregister',
								'Before object vectorization can work:',
							)
						}}
					</p>
					<ul>
						<li>
							{{
								t(
									'openregister',
									'LLM must be enabled with an embedding provider configured',
								)
							}}
						</li>
						<li>
							{{
								t(
									'openregister',
									'Objects will be serialized as JSON text before vectorization',
								)
							}}
						</li>
					</ul>
				</div>
			</div>

			<!-- Vectorization Settings -->
			<div class="config-section">
				<h3>{{ t('openregister', '🔢 Vectorization Settings') }}</h3>

				<VectorizationSwitchField
					v-model="config.vectorizationEnabled"
					:label="
						t('openregister', 'Enable automatic object vectorization')
					"
					:hint="
						t(
							'openregister',
							'Automatically generate vector embeddings when objects are created or updated',
						)
					" />

				<div v-if="config.vectorizationEnabled" class="trigger-settings">
					<h4>{{ t('openregister', 'Vectorization Triggers') }}</h4>
					<VectorizationSwitchField
						v-model="config.vectorizeOnCreate"
						:label="t('openregister', 'Vectorize on object creation')"
						:hint="
							t(
								'openregister',
								'Generate vectors immediately when new objects are created',
							)
						" />

					<VectorizationSwitchField
						v-model="config.vectorizeOnUpdate"
						:label="t('openregister', 'Re-vectorize on object update')"
						:hint="
							t(
								'openregister',
								'Update vectors when object data changes (recommended for accurate search)',
							)
						" />
				</div>
			</div>

			<!-- View-Specific Settings (Cost Optimization) -->
			<div v-if="config.vectorizationEnabled" class="config-section">
				<h3>
					{{ t('openregister', '💰 View Selection (Cost Optimization)') }}
				</h3>
				<p class="section-description">
					{{
						t(
							'openregister',
							'Control which object views should be vectorized to reduce API costs. Only vectorize views that benefit from semantic search.',
						)
					}}
				</p>

				<VectorizationSwitchField
					v-model="config.vectorizeAllViews"
					:label="t('openregister', 'Vectorize all views')"
					:hint="
						t(
							'openregister',
							'Enable vectorization for all existing and future views (may increase costs)',
						)
					" />

				<div v-if="!config.vectorizeAllViews" class="view-selection">
					<div class="selection-header">
						<label>{{
							t('openregister', 'Select views to vectorize:')
						}}</label>
						<div class="selection-stats">
							<span class="stat-badge"
								>{{ selectedViewsCount }} / {{ views.length }}
								{{ t('openregister', 'views selected') }}</span
							>
							<NcButton
								v-if="config.enabledViews.length > 0"
								variant="tertiary"
								@click="config.enabledViews = []">
								{{ t('openregister', 'Clear all') }}
							</NcButton>
						</div>
					</div>

					<div v-if="views.length > 0" class="view-list">
						<div v-for="view in views" :key="view.id" class="view-item">
							<NcCheckboxRadioSwitch
								v-model="config.enabledViews"
								:value="view.id"
								type="checkbox">
								<span class="view-name">{{ view.name }}</span>
							</NcCheckboxRadioSwitch>
							<span v-if="view.description" class="view-description">{{
								view.description
							}}</span>
						</div>
					</div>

					<div v-else class="no-views">
						<AlertCircle :size="24" />
						<p>
							{{
								t(
									'openregister',
									'No views found. Create views first before configuring vectorization.',
								)
							}}
						</p>
					</div>

					<div class="cost-note">
						<InformationOutline :size="16" />
						<small>{{
							t(
								'openregister',
								'Tip: only enable views that need semantic search to minimize embedding costs. Simple lookup tables rarely need vectorization.',
							)
						}}</small>
					</div>
				</div>
			</div>

			<!-- Object Serialization Settings -->
			<div v-if="config.vectorizationEnabled" class="config-section">
				<h3>{{ t('openregister', '📄 Object Serialization') }}</h3>
				<p class="section-description">
					{{
						t(
							'openregister',
							'Configure how objects are converted to text before vectorization. These settings affect search quality and context.',
						)
					}}
				</p>

				<VectorizationSwitchField
					v-model="config.includeMetadata"
					:label="
						t('openregister', 'Include schema and register metadata')
					"
					:hint="
						t(
							'openregister',
							'Add schema titles, descriptions, and register information to provide richer context for search',
						)
					" />

				<div class="form-group">
					<label for="max-depth">{{
						t('openregister', 'Maximum Nesting Depth')
					}}</label>
					<input
						id="max-depth"
						v-model.number="config.maxNestingDepth"
						type="number"
						min="1"
						max="20"
						class="input-field" />
					<small>{{
						t(
							'openregister',
							'How deep to traverse nested object properties (1-20). Higher values capture more detail but increase vector size.',
						)
					}}</small>
				</div>

				<VectorizationSwitchField
					v-model="config.includeRelations"
					:label="t('openregister', 'Include related object references')"
					:hint="
						t(
							'openregister',
							'Include ids and names of related objects for better contextual search',
						)
					" />
			</div>

			<VectorizationBatchSection
				v-if="config.vectorizationEnabled"
				v-model:batchSize="config.batchSize"
				v-model:autoRetry="config.autoRetry"
				:hint="
					t(
						'openregister',
						'Number of objects to vectorize in one API call. Higher = faster but more memory. Recommended: 10-50.',
					)
				" />

			<!-- Embedding Provider Info -->
			<div class="config-section">
				<h3>{{ t('openregister', 'ℹ️ current configuration') }}</h3>

				<div class="info-grid">
					<div class="info-item">
						<span class="info-label">{{
							t('openregister', 'Embedding Provider')
						}}</span>
						<span class="info-value">{{ embeddingProviderName }}</span>
					</div>
					<div class="info-item">
						<span class="info-label">{{
							t('openregister', 'Embedding Model')
						}}</span>
						<span class="info-value">{{ embeddingModelName }}</span>
					</div>
					<div class="info-item">
						<span class="info-label">{{
							t('openregister', 'Vector Dimensions')
						}}</span>
						<span class="info-value">{{ vectorDimensions }}</span>
					</div>
				</div>

				<div class="info-note">
					<small>{{
						t(
							'openregister',
							'To change the embedding provider or model, go to LLM configuration.',
						)
					}}</small>
				</div>
			</div>
		</div>

		<template #actions>
			<SettingsSaveActions
				:saving="saving"
				@save="saveConfiguration"
				@cancel="$emit('closing')" />
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import SettingsSaveActions from './SettingsSaveActions.vue'
import VectorizationBatchSection from './vectorization/VectorizationBatchSection.vue'
import VectorizationSwitchField from './vectorization/VectorizationSwitchField.vue'
import {
	readVectorizeSettings,
	readViews,
	writeVectorizeSettings,
} from './vectorization/objectVectorizationApi.js'
import { saveSettings } from './vectorization/vectorizationSettings.js'

/**
 * The object vectorization settings before the stored ones are loaded.
 *
 * @return {object} The settings
 */
function defaultConfig() {
	return {
		vectorizationEnabled: true,
		vectorizeOnCreate: true,
		vectorizeOnUpdate: false,
		vectorizeAllViews: true,
		enabledViews: [],
		includeMetadata: true,
		includeRelations: true,
		maxNestingDepth: 10,
		batchSize: 25,
		autoRetry: true,
	}
}

export default {
	name: 'ObjectManagementModal',

	components: {
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		InformationOutline,
		AlertCircle,
		VectorizationBatchSection,
		SettingsSaveActions,
		VectorizationSwitchField,
	},

	props: {
		show: { type: Boolean, required: true },
	},

	data() {
		return {
			saving: false,

			config: defaultConfig(),

			views: [],
			embeddingProviderName: 'Not configured',
			embeddingModelName: 'Not configured',
			vectorDimensions: 'N/A',
		}
	},

	computed: {
		/**
		 * @spec exclude computed count of selected views
		 */
		selectedViewsCount() {
			return this.config.enabledViews.length
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook loading initial modal data
	 */
	mounted() {
		this.loadConfiguration()
		this.loadViews()
		this.loadEmbeddingProviderInfo()
	},

	methods: {
		/**
		 * @spec openspec/specs/platform-administration-modals/spec.md
		 */
		async loadConfiguration() {
			try {
				const settings = await readVectorizeSettings()
				if (settings.success) {
					this.config = { ...this.config, ...settings.data }
				}
			} catch (error) {
				console.error('Failed to load configuration:', error)
			}
		},

		/**
		 * @spec exclude form-state loader for views via API
		 */
		async loadViews() {
			this.views = await readViews()
		},

		/**
		 * @spec exclude form-state loader for embedding-provider info via API
		 */
		async loadEmbeddingProviderInfo() {
			try {
				// Load current LLM configuration to show embedding provider info
				const response = await axios.get(
					generateUrl('/apps/openregister/api/settings/llm'),
				)
				const data = response.data

				this.embeddingProviderName =
					data.embeddingProvider || 'Not configured'
				this.embeddingModelName = data.embeddingModel || 'Not configured'
				this.vectorDimensions = data.vectorDimensions || 'N/A'
			} catch (error) {
				console.error('Failed to load embedding provider info:', error)
			}
		},

		/**
		 * @spec exclude modal submit handler persisting vectorization config via API
		 */
		saveConfiguration() {
			return saveSettings(this, {
				send: () => writeVectorizeSettings(this.config),
				done: this.t(
					'openregister',
					'Object vectorization configuration saved successfully',
				),
			})
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.object-config-content {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

@include info-box;

@include config-section;

.config-section h4 {
	margin: 16px 0 8px 0;
	font-size: 14px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.trigger-settings {
	margin-top: 16px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: 8px;
}

@include form-group;

.view-selection {
	margin-top: 20px;

	.selection-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 12px;

		label {
			font-weight: 500;
			margin: 0;
		}

		.selection-stats {
			display: flex;
			gap: 12px;
			align-items: center;

			.stat-badge {
				padding: 4px 12px;
				background: var(--color-primary-element-light);
				color: var(--color-primary-element);
				border-radius: 12px;
				font-size: 12px;
				font-weight: 600;
			}
		}
	}

	.view-list {
		max-height: 300px;
		overflow-y: auto;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		padding: 12px;
		background: var(--color-background-dark);
	}

	.view-item {
		margin-bottom: 12px;
		padding: 8px;
		border-radius: 6px;
		background: var(--color-main-background);

		&:last-child {
			margin-bottom: 0;
		}

		.view-name {
			font-weight: 500;
		}

		.view-description {
			display: block;
			margin-top: 4px;
			margin-left: 32px;
			font-size: 12px;
			color: var(--color-text-maxcontrast);
		}
	}

	.no-views {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		padding: 32px;
		text-align: center;
		color: var(--color-text-maxcontrast);
		border: 2px dashed var(--color-border);
		border-radius: 8px;

		p {
			margin: 0;
		}
	}

	.cost-note {
		display: flex;
		gap: 8px;
		align-items: flex-start;
		margin-top: 12px;
		padding: 12px;
		background: var(--color-background-dark);
		border-radius: 6px;

		small {
			color: var(--color-text-maxcontrast);
			font-size: 13px;
			line-height: 1.4;
		}
	}
}

@include info-grid-rules;

/* Actions layout */
@include deep-dialog-actions;

@include media-max-width-768px;
</style>
