<!--
  Every content-altering step of the anonymisation pipeline and its state,
  read from GET /api/admin/anonymisation/pipeline. A step that is off names
  the setting that switched it off.

  @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="setting-item pipeline-list">
		<p class="setting-description">
			{{ t('openregister', 'Steps that change the content of a document') }}
		</p>
		<NcLoadingIcon v-if="loading" :size="20" />
		<p v-else-if="error" class="setting-description pipeline-error" role="alert">
			{{ t('openregister', 'Could not load the anonymisation pipeline') }}
		</p>
		<ul v-else class="pipeline-steps">
			<li
				v-for="step in steps"
				:key="step.step"
				class="pipeline-step"
				:data-step="step.step">
				<span class="pipeline-step-label">{{ stepLabel(step) }}</span>
				<span
					class="pipeline-step-state"
					:class="'pipeline-step-state--' + step.state">
					{{ stateLabel(step) }}
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'

/**
 * The steps of the anonymisation pipeline and whether each is on.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */
export default {
	name: 'AnonymisationPipelineList',

	components: {
		NcLoadingIcon,
	},

	props: {
		/** Replaced after every settings save (the resolved backend state), so the list is read again. */
		refreshKey: { type: [Boolean, Object, String, Number], default: null },
	},

	data() {
		return {
			steps: [],
			loading: false,
			error: false,
		}
	},

	watch: {
		refreshKey() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the pipeline report.
		 *
		 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/admin/anonymisation/pipeline'))
				this.steps = Array.isArray(response.data?.steps) ? response.data.steps : []
			} catch {
				this.steps = []
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * The translated name of a step; a step this page does not know keeps the server's label.
		 *
		 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
		 * @param {object} step A report entry
		 * @return {string} The label
		 */
		stepLabel(step) {
			const labels = {
				anonymiser: t('openregister', 'Anonymiser'),
				'pdf-text-replacer': t('openregister', 'PDF text replacer'),
				'pdf-metadata-sanitiser': t('openregister', 'PDF metadata sanitiser'),
				'docx-sanitiser': t('openregister', 'DOCX sanitiser'),
				'odt-sanitiser': t('openregister', 'ODT sanitiser'),
			}

			return labels[step.step] ?? step.label ?? step.step
		},

		/**
		 * The state of a step; off names the setting that switched it off.
		 *
		 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
		 * @param {object} step A report entry
		 * @return {string} The state label
		 */
		stateLabel(step) {
			if (step.state === 'always-on') {
				return t('openregister', 'Always on')
			}

			if (step.state === 'on') {
				return t('openregister', 'On')
			}

			if (!step.setting) {
				return t('openregister', 'Off')
			}

			const settings = {
				entityRecognitionEnabled: t('openregister', 'Entity Recognition'),
			}

			return t('openregister', 'Off (setting: {setting})', {
				setting: settings[step.setting] ?? step.setting,
			})
		},
	},
}
</script>

<style scoped>
.pipeline-steps {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pipeline-step {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.pipeline-step-state {
	color: var(--color-text-maxcontrast);
}

.pipeline-step-state--on,
.pipeline-step-state--always-on {
	color: var(--color-success-text);
}

.pipeline-step-state--off {
	color: var(--color-warning-text);
}

.pipeline-error {
	color: var(--color-error-text);
}
</style>
