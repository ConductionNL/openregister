<template>
	<div class="goldenRecordPanel">
		<div class="goldenRecordHeader">
			<h2>{{ t('openregister', 'Golden record') }}</h2>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('openregister', 'Close') }}
			</NcButton>
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
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import AccountBoxOutline from 'vue-material-design-icons/AccountBoxOutline.vue'

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
	},

	props: {
		object: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * Expose the l10n translate helper to the template.
		 *
		 * @spec exclude UI plumbing — template translation helper
		 * @return {Function}
		 */
		t() {
			return t
		},

		/**
		 * Defensive read of `attributeProvenance` — tolerates absence,
		 * non-object shapes, and per-attribute entries that are either a
		 * plain source string or a `{source, confidence, timestamp}` object.
		 *
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#requirement-master-entity-list-with-golden-record-detail
		 * @return {Array<object>}
		 */
		provenanceEntries() {
			const provenance = this.object?.attributeProvenance
			if (!provenance || typeof provenance !== 'object') return []
			return Object.entries(provenance).map(([attribute, value]) => {
				if (value && typeof value === 'object') {
					return {
						attribute,
						source: value.source ?? value.winningSource ?? '—',
						confidence: value.confidence,
						timestamp: value.timestamp ?? value.at,
					}
				}
				return { attribute, source: value, confidence: undefined, timestamp: undefined }
			})
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
