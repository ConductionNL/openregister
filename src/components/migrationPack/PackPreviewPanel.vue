<!--
  Test the pack as it stands in the editor, saved or not, against one sample
  row: type a value per source column and see the fields the import would
  write, or the row's errors.

  Runs on the server's own mapping engine (POST /api/migration-packs/preview),
  so what it shows is what an import does. Runs 400 ms after the last change;
  an answer to an older request is dropped when a newer one is on its way.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<section class="pack-preview" :aria-labelledby="headingId">
		<h3 :id="headingId">
			{{ t('openregister', 'Test with a sample row') }}
		</h3>
		<p class="pack-preview__intro">
			{{
				t(
					'openregister',
					'Type values as they appear in the source. The result uses the mapping above, including changes you have not saved.',
				)
			}}
		</p>

		<p v-if="columns.length === 0" class="pack-preview__empty">
			{{
				t(
					'openregister',
					'Add a field mapping with a source column to test it.',
				)
			}}
		</p>
		<table v-else class="pack-preview__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('openregister', 'Source column') }}
					</th>
					<th scope="col">
						{{ t('openregister', 'Sample value') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="column in columns" :key="column">
					<td>{{ column }}</td>
					<td>
						<input
							:value="sample[column] || ''"
							type="text"
							:aria-label="
								t('openregister', 'Sample value for {column}', {
									column,
								})
							"
							@input="setValue(column, $event.target.value)" />
					</td>
				</tr>
			</tbody>
		</table>

		<div class="pack-preview__actions">
			<NcButton :disabled="!definition || columns.length === 0" @click="run">
				{{ t('openregister', 'Test') }}
			</NcButton>
			<NcLoadingIcon v-if="running" :size="20" />
		</div>

		<NcNoteCard v-if="!definition" type="warning">
			{{
				blockedText
				|| t(
					'openregister',
					'Fix the problems in the pack first; then it can be tested.',
				)
			}}
		</NcNoteCard>

		<div aria-live="polite">
			<NcNoteCard v-if="runError" type="error">
				<p>{{ runError.message }}</p>
				<ul v-if="runError.details.length > 0">
					<li v-for="detail in runError.details" :key="detail">
						{{ detail }}
					</li>
				</ul>
			</NcNoteCard>

			<template v-if="result">
				<h4>{{ t('openregister', 'Fields the import would write') }}</h4>
				<table v-if="resultFields.length > 0" class="pack-preview__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('openregister', 'Field') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Value') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="field in resultFields" :key="field.name">
							<td>{{ field.name }}</td>
							<td>
								<code>{{ field.value }}</code>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="pack-preview__empty">
					{{
						t(
							'openregister',
							'No fields: every mapping was empty or failed.',
						)
					}}
				</p>

				<template v-if="result.errors.length > 0">
					<h4>{{ t('openregister', 'Problems in this row') }}</h4>
					<table class="pack-preview__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Source column') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Target field') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Problem') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="(problem, index) in result.errors"
								:key="index">
								<td>{{ problem.source || '-' }}</td>
								<td>{{ problem.target || '-' }}</td>
								<td>{{ problem.message }}</td>
							</tr>
						</tbody>
					</table>
				</template>
			</template>
		</div>
	</section>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { explainError, previewPack } from '../../services/migrationPacks.js'
import { t } from '../../views/migrationPack/l10n.js'
import { sampleRowFrom } from '../../views/migrationPack/packDraft.js'

/** Milliseconds between the last change and the automatic test run. */
export const PREVIEW_DEBOUNCE_MS = 400

let panelCounter = 0

export default {
	name: 'PackPreviewPanel',
	components: { NcButton, NcLoadingIcon, NcNoteCard },
	props: {
		/** The definition to test, or null while the editor holds problems. */
		definition: { type: Object, default: null },
		/** The source columns to ask a value for. */
		columns: { type: Array, default: () => [] },
		/** Why there is no definition to test, when it is not a problem in the pack. */
		blockedText: { type: String, default: '' },
	},

	data() {
		panelCounter += 1
		return {
			sample: {},
			result: null,
			runError: null,
			running: false,
			runSequence: 0,
			timer: null,
			headingId: `migration-pack-preview-${panelCounter}`,
		}
	},

	computed: {
		/**
		 * The mapped fields of the result, values shown as JSON.
		 *
		 * @return {Array<{name: string, value: string}>} The fields.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		resultFields() {
			return Object.entries(this.result?.data || {}).map(([name, value]) => ({
				name,
				value: JSON.stringify(value),
			}))
		},
	},

	watch: {
		/**
		 * Test again shortly after the pack changes in the editor.
		 *
		 * Without a definition, the last result belonged to an older pack: it
		 * is cleared, and an answer still on its way is dropped.
		 *
		 * @param {object|null} definition The new definition.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		definition(definition) {
			if (definition === null) {
				this.clear()
				return
			}
			this.schedule()
		},
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		t,
		/**
		 * Forget the last result and drop any request still on its way.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		clear() {
			clearTimeout(this.timer)
			this.runSequence += 1
			this.result = null
			this.runError = null
			this.running = false
		},

		/**
		 * Set one sample value and test again shortly.
		 *
		 * @param {string} column The source column.
		 * @param {string} value The value.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		setValue(column, value) {
			this.sample = { ...this.sample, [column]: value }
			this.schedule()
		},

		/**
		 * Test once the user stops typing, if there is anything to test.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		schedule() {
			clearTimeout(this.timer)
			if (
				!this.definition
				|| Object.values(this.sample).every((v) => v === '')
			) {
				return
			}
			this.timer = setTimeout(() => this.run(), PREVIEW_DEBOUNCE_MS)
		},

		/**
		 * Map the sample row with the current definition.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		async run() {
			clearTimeout(this.timer)
			if (!this.definition) {
				return
			}
			this.runSequence += 1
			const sequence = this.runSequence
			const row = sampleRowFrom(this.columns, this.sample)
			this.running = true
			try {
				const rows = await previewPack(this.definition, [row])
				if (sequence !== this.runSequence) {
					return
				}
				const first = rows[0] || {}
				this.result = {
					data: first.data || {},
					errors: Array.isArray(first.errors) ? first.errors : [],
				}
				this.runError = null
			} catch (error) {
				if (sequence !== this.runSequence) {
					return
				}
				this.result = null
				this.runError = explainError(
					error,
					t('openregister', 'The test could not run.'),
				)
			} finally {
				if (sequence === this.runSequence) {
					this.running = false
				}
			}
		},
	},
}
</script>

<style scoped>
.pack-preview__intro,
.pack-preview__empty {
	color: var(--color-text-maxcontrast);
}

.pack-preview__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: 8px;
}

.pack-preview__table th,
.pack-preview__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.pack-preview__table input {
	width: 100%;
	margin: 0;
}

.pack-preview__actions {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
