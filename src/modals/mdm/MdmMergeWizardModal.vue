<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('openregister', 'Merge objects')"
		size="large"
		:can-close="!executing"
		@closing="$emit('close')">
		<div class="mergeWizard">
			<template v-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<template v-else-if="error && !preview">
				<NcNoteCard type="error">
					{{ error }}
				</NcNoteCard>
			</template>

			<template v-else-if="preview">
				<NcNoteCard type="info">
					{{ t('openregister', 'This is a preview only — no object or merge operation is written until you confirm.') }}
				</NcNoteCard>

				<h3>{{ t('openregister', 'Post-merge golden record') }}</h3>
				<table class="mergeWizard__table">
					<thead>
						<tr>
							<th>{{ t('openregister', 'Attribute') }}</th>
							<th>{{ t('openregister', 'Winning value') }}</th>
							<th>{{ t('openregister', 'Source') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(value, key) in preview.postMergeGoldenRecord" :key="key" data-testid="mdm-merge-preview-row">
							<td>{{ key }}</td>
							<td>{{ displayValue(value) }}</td>
							<td>{{ provenanceFor(key) }}</td>
						</tr>
					</tbody>
				</table>

				<NcNoteCard v-if="preview.reversalDeadline" type="info">
					{{ t('openregister', 'This merge can be reversed until {date}.', { date: preview.reversalDeadline }) }}
				</NcNoteCard>

				<div class="mergeWizard__reason">
					<NcSelect
						v-model="reasonModel"
						data-testid="mdm-merge-reason"
						:options="reasonOptions"
						:input-label="t('openregister', 'Merge reason')"
						:placeholder="t('openregister', 'Select a reason')"
						:clearable="false"
						label="label" />
				</div>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<NcButton :disabled="executing" @click="$emit('close')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				data-testid="mdm-merge-confirm"
				:disabled="!canConfirm"
				@click="confirm">
				<template #icon>
					<NcLoadingIcon v-if="executing" :size="20" />
				</template>
				{{ t('openregister', 'Confirm merge') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { qualityStore } from '../../store/store.js'

/**
 * Pair-agnostic merge wizard: preview the projected post-merge golden
 * record + per-attribute provenance + reversal deadline, capture a reason,
 * then execute. Launched today from `DuplicatesIndex` with a candidate
 * pair's two object ids; written against `{ from, into }` props only so a
 * later manual-merge launch (e.g. from `MasterEntitiesIndex`) can reuse it
 * unchanged (design.md D3).
 *
 * All merge computation is server-authoritative (OpenRegister's
 * `MergeService`) — this modal renders the endpoint response and performs
 * no survivorship or conflict-resolution logic itself.
 */
export default {
	name: 'MdmMergeWizardModal',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/**
		 * Id of the merged-away object (loses on merge).
		 */
		from: {
			type: [String, Number],
			required: true,
		},
		/**
		 * Id of the surviving object (the merge target).
		 */
		into: {
			type: [String, Number],
			required: true,
		},
	},

	emits: ['close', 'merged'],

	data() {
		return {
			loading: true,
			executing: false,
			error: '',
			preview: null,
			reasonModel: null,
			reasonOptions: [
				{ id: 'duplicate-confirmed', label: t('openregister', 'Confirmed duplicate') },
				{ id: 'data-stewardship-review', label: t('openregister', 'Data stewardship review') },
				{ id: 'manual-bulk', label: t('openregister', 'Manual bulk cleanup') },
				{ id: 'migration', label: t('openregister', 'Migration cleanup') },
			],
		}
	},

	computed: {
		/**
		 * Confirm is disabled until the preview has loaded successfully and
		 * a reason has been chosen.
		 *
		 * @spec openspec/specs/mdm-merge-ui/spec.md#scenario-reason-is-mandatory
		 * @return {boolean}
		 */
		canConfirm() {
			return Boolean(this.preview) && Boolean(this.reasonModel) && !this.executing
		},
	},

	/**
	 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-merge-wizard-previews-the-post-merge-golden-record-before-executing
	 */
	mounted() {
		this.fetchPreview()
	},

	methods: {
		/**
		 * Request a side-effect-free merge preview for the configured pair.
		 * On a 403/404 the confirm control stays disabled and the returned
		 * error message is shown.
		 *
		 * @spec openspec/specs/mdm-merge-ui/spec.md#scenario-preview-failure-surfaces-an-error-and-blocks-confirmation
		 */
		async fetchPreview() {
			this.loading = true
			this.error = ''
			this.preview = null
			try {
				this.preview = await qualityStore.previewMerge(this.from, this.into)
			} catch (e) {
				this.error = e.response?.data?.error || t('openregister', 'The merge preview could not be generated.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve the winning source label for an attribute from the
		 * preview's `attributeProvenance` map.
		 *
		 * @param {string} key Attribute name.
		 * @spec exclude UI presentation helper — reads the already-fetched preview payload
		 * @return {string}
		 */
		provenanceFor(key) {
			const meta = this.preview?.attributeProvenance?.[key]
			if (!meta) return ''
			return meta.sourceSystem || meta.source || meta.winner || ''
		},

		/**
		 * Stringify a golden-record value for display.
		 *
		 * @param {*} value Raw attribute value.
		 * @spec exclude UI presentation helper
		 * @return {string}
		 */
		displayValue(value) {
			if (value === null || value === undefined) return t('openregister', 'N/A')
			if (typeof value === 'object') return JSON.stringify(value)
			return String(value)
		},

		/**
		 * Execute the merge with the chosen reason. On success emits
		 * `merged` (so the caller refreshes its list) then `close`. Any
		 * 403/404 error from the endpoint is surfaced inline and the
		 * modal stays open.
		 *
		 * @spec openspec/specs/mdm-merge-ui/spec.md#scenario-confirming-a-merge-executes-it-and-refreshes-candidates
		 */
		async confirm() {
			if (!this.canConfirm) return
			this.executing = true
			this.error = ''
			try {
				const result = await qualityStore.executeMerge(this.from, this.into, this.reasonModel?.id)
				this.$emit('merged', result)
				this.$emit('close')
			} catch (e) {
				this.error = e.response?.data?.error || t('openregister', 'The merge could not be completed.')
			} finally {
				this.executing = false
			}
		},
	},
}
</script>

<style scoped>
.mergeWizard {
	min-height: 160px;
}

.mergeWizard__table {
	width: 100%;
	border-collapse: collapse;
	margin: 16px 0;
}

.mergeWizard__table th,
.mergeWizard__table td {
	text-align: left;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
}

.mergeWizard__reason {
	margin-top: 16px;
	max-width: 360px;
}
</style>
