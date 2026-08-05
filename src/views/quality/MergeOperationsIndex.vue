<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<h1>{{ t('openregister', 'Merge Operations') }}</h1>
				<p>{{ t('openregister', 'Audit trail of recent reversible merges. Reverse an operation while it is still within its reversal window.') }}</p>
			</div>

			<template v-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<template v-else-if="mergeOperations.length === 0">
				<NcEmptyContent
					:name="t('openregister', 'No merge operations found')"
					:description="t('openregister', 'Merges executed from the Duplicate Candidates view will show up here.')">
					<template #icon>
						<Merge :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<table class="mergeOperationsTable">
					<thead>
						<tr>
							<th>{{ t('openregister', 'Survivor') }}</th>
							<th>{{ t('openregister', 'Merged from') }}</th>
							<th>{{ t('openregister', 'Reason') }}</th>
							<th>{{ t('openregister', 'Merged at') }}</th>
							<th>{{ t('openregister', 'Status') }}</th>
							<th>{{ t('openregister', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="operation in mergeOperations" :key="operation.id" data-testid="mdm-merge-operation-row">
							<td>{{ operation.into }}</td>
							<td>{{ operation.from }}</td>
							<td>{{ operation.reason || '—' }}</td>
							<td>{{ operation.mergedAt || operation.created || '—' }}</td>
							<td>
								<span :class="'badge badge-status-' + (isReversible(operation) ? 'reversible' : 'final')">
									{{ isReversible(operation) ? t('openregister', 'Reversible') : t('openregister', 'Reversed / final') }}
								</span>
							</td>
							<td>
								<NcButton
									v-if="isReversible(operation)"
									variant="tertiary"
									data-testid="mdm-merge-reverse"
									:disabled="reversingId === operation.id"
									@click="reverse(operation)">
									<template #icon>
										<NcLoadingIcon v-if="reversingId === operation.id" :size="20" />
									</template>
									{{ t('openregister', 'Reverse') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<div v-if="mergeOperationsTotal > mergeOperationsLimit" class="pagination">
					<NcButton :disabled="mergeOperationsOffset === 0" @click="previousPage">
						{{ t('openregister', 'Previous') }}
					</NcButton>
					<span class="paginationInfo">
						{{ t('openregister', 'Showing {from}-{to} of {total}', {
							from: mergeOperationsOffset + 1,
							to: Math.min(mergeOperationsOffset + mergeOperationsLimit, mergeOperationsTotal),
							total: mergeOperationsTotal,
						}) }}
					</span>
					<NcButton :disabled="mergeOperationsOffset + mergeOperationsLimit >= mergeOperationsTotal" @click="nextPage">
						{{ t('openregister', 'Next') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcButton } from '@nextcloud/vue'
import Merge from 'vue-material-design-icons/Merge.vue'
import { qualityStore } from '../../store/store.js'

/**
 * Steward audit view over recent `mergeOperation` rows (ADR-045 follow-on
 * #C, design.md D2). A merge operation is an audit row, not an attribute of
 * one master entity — a merged-away object leaves the master-entity list,
 * so its detail panel is the wrong place to reverse it. This is a dedicated
 * list with an in-window "Reverse" action. Reads through
 * `fetchMergeOperations` (generic object-read surface); reversibility is
 * always re-checked by the endpoint — the UI only reflects what the server
 * returns.
 */
export default {
	name: 'MergeOperationsIndex',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		Merge,
	},

	data() {
		return {
			// Id of the operation currently being reversed, for a per-row
			// loading indicator; null when no reverse request is in flight.
			reversingId: null,
		}
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — proxies the store's loading flag, no backend contract of its own
		 */
		loading() {
			return qualityStore.isLoading
		},

		/**
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-steward-reviews-recent-merge-operations-in-a-dedicated-view
		 */
		mergeOperations() {
			return qualityStore.mergeOperations
		},

		/**
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-steward-reviews-recent-merge-operations-in-a-dedicated-view
		 */
		mergeOperationsTotal() {
			return qualityStore.mergeOperationsTotal
		},

		/**
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-steward-reviews-recent-merge-operations-in-a-dedicated-view
		 */
		mergeOperationsLimit() {
			return qualityStore.mergeOperationsLimit
		},

		/**
		 * @spec openspec/specs/mdm-merge-ui/spec.md#requirement-steward-reviews-recent-merge-operations-in-a-dedicated-view
		 */
		mergeOperationsOffset() {
			return qualityStore.mergeOperationsOffset
		},
	},

	mounted() {
		this.loadData()
	},

	methods: {
		/**
		 * Load the first page of merge operations.
		 *
		 * @spec exclude UI data-loading orchestration — delegates to fetchMergeOperations which carries the @spec tag
		 */
		async loadData() {
			await qualityStore.fetchMergeOperations({ limit: 20, offset: 0 })
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchMergeOperations
		 */
		async previousPage() {
			const offset = Math.max(0, this.mergeOperationsOffset - this.mergeOperationsLimit)
			await qualityStore.fetchMergeOperations({ limit: this.mergeOperationsLimit, offset })
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchMergeOperations
		 */
		async nextPage() {
			const offset = this.mergeOperationsOffset + this.mergeOperationsLimit
			await qualityStore.fetchMergeOperations({ limit: this.mergeOperationsLimit, offset })
		},

		/**
		 * A "Reverse" action is only shown for operations still within their
		 * reversal window — an already-reversed or expired operation shows
		 * no action. The endpoint remains the sole authority: reversing
		 * still calls through and reflects whatever it returns.
		 *
		 * @param {object} operation Merge operation row.
		 * @spec openspec/specs/mdm-merge-ui/spec.md#scenario-reverse-offered-only-within-the-window
		 * @return {boolean}
		 */
		isReversible(operation) {
			if (operation?.reversible === false) return false
			const deadline = operation?.reversalDeadline
			if (!deadline) return Boolean(operation?.reversible)
			return new Date(deadline).getTime() > Date.now()
		},

		/**
		 * Reverse a merge operation and refresh the list so it is shown as
		 * no longer reversible.
		 *
		 * @param {object} operation Merge operation row.
		 * @spec openspec/specs/mdm-merge-ui/spec.md#scenario-reversing-restores-the-objects-and-updates-the-row
		 */
		async reverse(operation) {
			this.reversingId = operation.id
			try {
				await qualityStore.reverseMerge(operation.id)
				await this.loadData()
			} finally {
				this.reversingId = null
			}
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 1200px;
}

.viewHeader h1 {
	margin-bottom: 4px;
}

.mergeOperationsTable {
	width: 100%;
	border-collapse: collapse;
	margin-top: 16px;
}

.mergeOperationsTable th,
.mergeOperationsTable td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 12px;
}

.badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
}

.badge-status-reversible {
	color: var(--color-main-text);
}

.badge-status-final {
	color: var(--color-text-maxcontrast);
}
</style>
