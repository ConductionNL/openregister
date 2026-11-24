<!--
  The dependency breakers and the database pressure, on the operations console.
  Reads GET /api/operations/dependencies and resets a breaker through
  POST /api/operations/dependencies/{key}/reset (administrator only).

  @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<section class="consoleSection dependencies">
		<h2>{{ t('openregister', 'Dependencies') }}</h2>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-if="loaded">
			<NcNoteCard v-if="perWorker" type="info" class="dependenciesPerWorker">
				{{
					t(
						'openregister',
						'This instance has no shared cache, so each PHP worker keeps its own breakers. This list shows the worker that answered.',
					)
				}}
			</NcNoteCard>

			<NcNoteCard v-if="pressureLine.attention" type="warning" class="dependenciesPressure">
				{{ pressureLine.text }}
			</NcNoteCard>
			<p v-else class="dependenciesPressure">
				{{ pressureLine.text }}
			</p>

			<p v-if="dependencies.length === 0" class="dependenciesEmpty">
				{{ t('openregister', 'No dependency has failed recently.') }}
			</p>

			<CnDataTable
				v-else
				class="consoleTable"
				:columns="columns"
				:rows="dependencies"
				rowKey="key">
				<template #column-key="{ value }">
					{{ value }}
				</template>
				<template #column-state="{ row: dependency }">
					{{ stateWords(dependency.state) }}
				</template>
				<template #column-failures="{ row: dependency }">
					{{ dependency.failures }} / {{ dependency.calls }}
				</template>
				<template #column-nextProbeAt="{ row: dependency }">
					{{ momentOf(dependency.nextProbeAt) }}
				</template>
				<template #actions-header>
					{{ t('openregister', 'Do') }}
				</template>
				<template #row-actions="{ row: dependency }">
					<NcButton
						v-if="dependency.state !== 'closed'"
						variant="secondary"
						:disabled="resetting === dependency.key"
						@click="reset(dependency.key)">
						{{ t('openregister', 'Reset') }}
					</NcButton>
				</template>
			</CnDataTable>
		</template>
	</section>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

/**
 * The breakers with state, the database pressure, and a reset per open breaker.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
export default {
	name: 'DependenciesSection',

	components: {
		CnDataTable,
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			loaded: false,
			error: '',
			resetting: null,
			dependencies: [],
			pressure: { enabled: true, underPressure: false },
			shared: true,
		}
	},

	computed: {
		/**
		 * The breaker table columns; the row actions add the "Do" column.
		 *
		 * @spec exclude Client-side table layout.
		 * @return {Array<object>} The CnDataTable columns
		 */
		columns() {
			return [
				{ key: 'key', label: t('openregister', 'Dependency') },
				{ key: 'state', label: t('openregister', 'State') },
				{ key: 'failures', label: t('openregister', 'Failed calls') },
				{ key: 'nextProbeAt', label: t('openregister', 'Next trial call') },
			]
		},

		/**
		 * Whether the breakers are kept per PHP worker rather than shared.
		 *
		 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
		 * @return {boolean} True without a shared cache.
		 */
		perWorker() {
			return this.shared === false
		},

		/**
		 * The database pressure in words, and whether it wants attention.
		 *
		 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
		 * @return {{text: string, attention: boolean}} The line.
		 */
		pressureLine() {
			if (this.pressure.enabled === false) {
				return {
					text: t('openregister', 'Shedding under database pressure is switched off.'),
					attention: false,
				}
			}

			if (this.pressure.underPressure === true) {
				return {
					text: t('openregister', 'The database is under pressure: heavy requests such as exports are refused for now.'),
					attention: true,
				}
			}

			return {
				text: t('openregister', 'The database is not under pressure.'),
				attention: false,
			}
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * The words for a breaker state the backend names by id.
		 *
		 * @param {string} state One of open, half-open, closed.
		 * @return {string} The words.
		 * @spec exclude UI plumbing, translates one of three known ids
		 */
		stateWords(state) {
			const words = {
				open: t('openregister', 'Refusing calls'),
				'half-open': t('openregister', 'Trying one call'),
				closed: t('openregister', 'Passing calls'),
			}

			return words[state] || state
		},

		/**
		 * A unix time in the reader's locale, or a dash when there is none.
		 *
		 * @param {number|null} seconds Seconds since the epoch.
		 * @return {string} The moment.
		 * @spec exclude UI plumbing, renders a timestamp
		 */
		momentOf(seconds) {
			if (typeof seconds !== 'number' || Number.isFinite(seconds) === false) {
				return '-'
			}

			return new Date(seconds * 1000).toLocaleString()
		},

		/**
		 * Read the breakers and the pressure.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
		 */
		async load() {
			this.error = ''

			try {
				const answer = await axios.get(generateUrl('/apps/openregister/api/operations/dependencies'))
				const data = answer.data || {}
				this.dependencies = Array.isArray(data.dependencies) ? data.dependencies : []
				this.pressure = data.pressure || { enabled: true, underPressure: false }
				this.shared = data.shared !== false
				this.loaded = true
			} catch {
				this.error = t('openregister', 'The dependencies could not be read.')
			}
		},

		/**
		 * Close one breaker, then read the list again.
		 *
		 * @param {string} key The breaker key.
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
		 */
		async reset(key) {
			this.resetting = key
			this.error = ''

			try {
				await axios.post(
					generateUrl(`/apps/openregister/api/operations/dependencies/${encodeURIComponent(key)}/reset`),
				)
				await this.load()
			} catch (exception) {
				this.error = (exception.response || {}).data?.error
					|| t('openregister', 'That did not go through.')
			} finally {
				this.resetting = null
			}
		},
	},
}
</script>

<style scoped>
.dependenciesPressure,
.dependenciesEmpty {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
