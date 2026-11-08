<template>
	<section class="ruleRuns">
		<h3>{{ t('openregister', 'Run log') }}</h3>
		<p class="lead">
			{{
				t(
					'openregister',
					'Every evaluation of this rule, newest first, with the operand that decided it.',
				)
			}}
		</p>

		<div class="runFilters">
			<NcSelect
				v-model="verdict"
				:options="verdictOptions"
				:inputLabel="t('openregister', 'Verdict')"
				:placeholder="t('openregister', 'Any verdict')"
				label="label"
				@update:modelValue="load" />
			<NcTextField
				id="ruleRunsSince"
				v-model="since"
				type="date"
				:label="t('openregister', 'From')"
				@update:modelValue="load" />
			<NcTextField
				id="ruleRunsUntil"
				v-model="until"
				type="date"
				:label="t('openregister', 'To')"
				@update:modelValue="load" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="runs.length === 0"
			:name="t('openregister', 'No runs match')"
			:description="
				t(
					'openregister',
					'A run is written when an object is saved. A dry run writes none, which is what makes it dry.',
				)
			">
			<template #icon>
				<HistoryIcon :size="44" />
			</template>
		</NcEmptyContent>

		<CnDataTable
			v-else
			class="runTable"
			:columns="columns"
			:rows="runs">
			<template #column-ranAt="{ row: run }">
				{{ run.ranAt || run.created }}
			</template>
			<template #column-objectUuid="{ row: run }">
				<code>{{ run.objectUuid || '' }}</code>
			</template>
			<template #column-operand="{ row: run }">
				<template v-if="run.operand">
					<code>{{ run.operand }}</code>
					<span class="operandValue"
						>{{ t('openregister', 'read as') }}
						<code>{{ run.operandValue }}</code></span
					>
				</template>
				<span v-else class="muted">{{ run.message || '' }}</span>
			</template>
		</CnDataTable>

		<p v-if="total > runs.length" class="muted">
			{{
				t('openregister', 'Showing {shown} of {total}.', {
					shown: runs.length,
					total,
				})
			}}
		</p>

		<p v-if="error" class="refusal">
			{{ error }}
		</p>
	</section>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import { fetchRuns, messageFor } from '../../services/rules.js'

/**
 * One rule's run log, filtered by verdict and period.
 *
 * The verdict options come from the published vocabulary rather than a list
 * typed here, because the endpoint refuses a verdict it does not reach and a
 * hardcoded option would produce a filter that can only return a refusal.
 */
export default {
	name: 'RuleRunsPanel',

	components: {
		CnDataTable,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		HistoryIcon,
	},

	props: {
		/** The derived rule id. */
		ruleId: { type: String, required: true },
		/** The published verdicts. */
		verdicts: { type: Array, default: () => [] },
	},

	data() {
		return {
			runs: [],
			total: 0,
			verdict: null,
			since: '',
			until: '',
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The verdicts an operator may filter on, from the vocabulary.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		verdictOptions() {
			return this.verdicts.map((row) => ({
				id: row.verdict,
				label: row.verdict,
			}))
		},

		/**
		 * The run log columns.
		 *
		 * @return {Array<object>} The CnDataTable columns
		 */
		columns() {
			return [
				{ key: 'ranAt', label: t('openregister', 'When') },
				{ key: 'verdict', label: t('openregister', 'Verdict') },
				{ key: 'objectUuid', label: t('openregister', 'Object') },
				{ key: 'operand', label: t('openregister', 'What decided it') },
			]
		},
	},

	watch: {
		ruleId: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Load the run log under the current filters.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/flow-engine/spec.md
		 */
		async load() {
			if (!this.ruleId) {
				return
			}

			this.loading = true
			this.error = ''
			try {
				const filters = {}
				if (this.verdict?.id) {
					filters.verdict = this.verdict.id
				}
				if (this.since) {
					filters.since = this.since
				}
				if (this.until) {
					filters.until = this.until
				}

				const data = await fetchRuns(this.ruleId, filters)
				this.runs = data.runs ?? []
				this.total = data.total ?? this.runs.length
			} catch (failure) {
				this.error = messageFor(
					failure,
					t('openregister', 'The run log could not be read.'),
				)
				this.runs = []
				this.total = 0
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

.ruleRuns {
	margin-bottom: 24px;
}

@include lead;

.runFilters {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 12px;
	align-items: flex-end;
}

.runTable :deep(td) {
	vertical-align: top;
}

@include rule-detail-text('.operandValue');

.refusal {
	color: var(--color-error-text, var(--color-error));
}
</style>
