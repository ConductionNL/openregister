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

				<VectorizationSwitchField
					v-model="config.vectorizationEnabled"
					:label="t('openregister', 'Enable automatic file vectorization')"
					:hint="
						t(
							'openregister',
							'Automatically generate vector embeddings from text chunks when files are uploaded and processed',
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
						'Number of chunks to vectorize in one API call. Higher = faster but more memory. Recommended: 10-50.',
					)
				" />
		</div>

		<template #actions>
			<VectorizationSaveActions
				:saving="saving"
				@cancel="$emit('closing')"
				@save="saveConfiguration" />
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcDialog } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import VectorizationBatchSection from './vectorization/VectorizationBatchSection.vue'
import VectorizationSaveActions from './vectorization/VectorizationSaveActions.vue'
import VectorizationSwitchField from './vectorization/VectorizationSwitchField.vue'
import { saveSettings } from './vectorization/vectorizationSettings.js'

export default {
	name: 'FileManagementModal',

	components: {
		NcDialog,
		InformationOutline,
		VectorizationBatchSection,
		VectorizationSaveActions,
		VectorizationSwitchField,
	},

	props: {
		show: { type: Boolean, required: true },
	},

	data() {
		return {
			saving: false,

			config: {
				vectorizationEnabled: true,
				batchSize: 25,
				autoRetry: true,
			},
		}
	},

	methods: {
		/**
		 * @spec exclude Modal save plumbing — posts file-vectorization config to the settings endpoint.
		 */
		saveConfiguration() {
			return saveSettings(this, {
				send: () =>
					axios.post(
						generateUrl(
							'/apps/openregister/api/settings/file-vectorization',
						),
						this.config,
					),
				done: this.t(
					'openregister',
					'File vectorization configuration saved successfully',
				),
			})
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

@include config-section;

@include form-group;

/* Actions layout */
@include deep-dialog-actions;

@include media-max-width-768px;
</style>
