<template>
	<div class="goldenRecordPanel">
		<div class="goldenRecordHeader">
			<h2>{{ t('openregister', 'Golden record') }}</h2>
			<div class="goldenRecordHeader__actions">
				<NcButton v-if="object"
					variant="secondary"
					data-testid="mdm-resolve-conflicts"
					@click="openConflicts">
					{{ t('openregister', 'Resolve conflicts') }}
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('openregister', 'Close') }}
				</NcButton>
			</div>
		</div>

		<template v-if="!object">
			<NcEmptyContent :name="t('openregister', 'No master entity selected')">
				<template #icon>
					<AccountBoxOutline :size="48" />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else>
			<div class="goldenRecordMeta">
				<span><strong>{{ t('openregister', 'Id') }}:</strong> {{ object.id }}</span>
				<span><strong>{{ t('openregister', 'Quality score') }}:</strong> {{ object.qualityScore ?? '—' }}</span>
				<span><strong>{{ t('openregister', 'Quality status') }}:</strong> {{ object.qualityStatus ?? '—' }}</span>
			</div>

			<template v-if="provenanceEntries.length === 0">
				<NcEmptyContent :name="t('openregister', 'No golden-record provenance')" :description="t('openregister', 'This object has no materialised attribute-provenance map.')">
					<template #icon>
						<AccountBoxOutline :size="48" />
					</template>
				</NcEmptyContent>
			</template>

			<table v-else class="provenanceTable" data-testid="provenance-table">
				<thead>
					<tr>
						<th>{{ t('openregister', 'Attribute') }}</th>
						<th>{{ t('openregister', 'Winning source') }}</th>
						<th>{{ t('openregister', 'Confidence') }}</th>
						<th>{{ t('openregister', 'Timestamp') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in provenanceEntries" :key="entry.attribute">
						<td>{{ entry.attribute }}</td>
						<td>{{ entry.source }}</td>
						<td>{{ entry.confidence ?? '—' }}</td>
						<td>{{ entry.timestamp ?? '—' }}</td>
					</tr>
				</tbody>
			</table>
		</template>

		<MdmConflictResolutionModal
			v-if="showConflictResolution && object"
			:object="objectForModal"
			@close="showConflictResolution = false"
			@saved="handleConflictsResolved" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import AccountBoxOutline from 'vue-material-design-icons/AccountBoxOutline.vue'
import MdmConflictResolutionModal from '../../modals/mdm/MdmConflictResolutionModal.vue'
import { qualityStore } from '../../store/store.js'

/**
 * Golden-record detail panel — NOT a modal/dialog (hydra-gate-modal-isolation).
 * Renders the object's materialised `attributeProvenance` map defensively:
 * whatever provenance keys the merged survivorship engine produced are
 * rendered, and a missing map degrades to an explicit empty state rather
 * than erroring.
 */
export default {
	name: 'GoldenRecordDetail',

	components: {
		NcButton,
		NcEmptyContent,
		AccountBoxOutline,
		MdmConflictResolutionModal,
	},

	props: {
		object: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	data() {
		return {
			showConflictResolution: false,
			resolvedSources: [],
		}
	},

	computed: {
		/**
		 * The object handed to the conflict-resolution modal, augmented with the
		 * server-resolved `sources` (embedded or reverse-FK). The modal computes
		 * per-attribute conflicts from `object.sources`; a reverse-FK master has
		 * no embedded source array, so we attach the fetched sources here.
		 *
		 * @return {object|null}
		 */
		objectForModal() {
			if (!this.object) return null
			return { ...this.object, sources: this.resolvedSources }
		},

		/**
		 * Defensive read of `attributeProvenance` — tolerates absence,
		 * non-object shapes, and per-attribute entries that are either a
		 * plain source string or a `{source, confidence, timestamp}` object.
		 * An overridden attribute (`override: true`) is labelled with its
		 * `overriddenBy` actor rather than a trust-tier source, so a steward
		 * reading the golden record sees WHY an attribute took a manual value
		 * (design.md D3).
		 *
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-master-entity-list-with-golden-record-detail
		 * @spec openspec/specs/mdm-survivorship/spec.md#requirement-per-object-attribute-overrides-are-materialised-and-preserved
		 * @return {Array<object>}
		 */
		provenanceEntries() {
			const provenance = this.object?.attributeProvenance
			if (!provenance || typeof provenance !== 'object') return []
			return Object.entries(provenance).map(([attribute, value]) => {
				if (value && typeof value === 'object') {
					if (value.override === true) {
						return {
							attribute,
							source: t('openregister', 'Manual override ({actor})', { actor: value.overriddenBy || t('openregister', 'unknown') }),
							confidence: value.rationale,
							timestamp: undefined,
						}
					}
					return {
						attribute,
						source: value.source ?? value.winningSource ?? value.sourceSystem ?? '—',
						confidence: value.confidence,
						timestamp: value.timestamp ?? value.at,
					}
				}
				return { attribute, source: value, confidence: undefined, timestamp: undefined }
			})
		},
	},

	methods: {
		/**
		 * Fetch the master's resolved source records, then open the
		 * conflict-resolution modal. The modal derives per-attribute conflicts
		 * from `object.sources`; a reverse-FK master carries none inline, so we
		 * resolve them server-side first (embedded masters resolve to their own
		 * inline sources, so this is correct for both modes).
		 *
		 * @return {Promise<void>}
		 */
		async openConflicts() {
			const id = this.object?.id
			this.resolvedSources = id ? await qualityStore.fetchMasterSources(id) : []
			this.showConflictResolution = true
		},

		/**
		 * Refresh the golden record after the conflict-resolution modal saves.
		 *
		 * @spec openspec/specs/mdm-conflict-resolution-ui/spec.md#requirement-steward-chooses-persistent-rule-or-one-off-outcome
		 * @return {Promise<void>}
		 */
		async handleConflictsResolved() {
			this.showConflictResolution = false
			const register = this.object?.['@self']?.register ?? this.object?.register ?? qualityStore.selectedRegister
			const schema = this.object?.['@self']?.schema ?? this.object?.schema ?? qualityStore.selectedSchema
			const id = this.object?.id
			if (register && schema && id) {
				await qualityStore.fetchGoldenRecord(register, schema, id)
			}
		},
	},
}
</script>

<style scoped>
.goldenRecordPanel {
	border-left: 1px solid var(--color-border);
	padding: 16px;
	min-width: 320px;
}

.goldenRecordHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.goldenRecordHeader__actions {
	display: flex;
	gap: 8px;
}

.goldenRecordMeta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 12px 0;
}

.provenanceTable {
	width: 100%;
	border-collapse: collapse;
	margin-top: 12px;
}

.provenanceTable th,
.provenanceTable td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
