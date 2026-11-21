<template>
	<div class="schemaIndexesTab">
		<NcAppContentDetails>
			<h2>{{ t('openregister', 'Indexes') }}</h2>
			<p class="lead">
				{{ t('openregister', 'The database indexes on this schema\'s tables and the field setting that asked for each.') }}
			</p>

			<NcLoadingIcon v-if="loading" :size="44" />

			<p v-else-if="error" class="refusal" role="alert">
				{{ error }}
			</p>

			<p v-else-if="tables.length === 0" class="empty">
				{{ t('openregister', 'No register stores objects of this schema in its own table yet.') }}
			</p>

			<template v-else>
				<section
					v-for="table in tables"
					:key="table.register"
					class="indexTable">
					<h3>{{ t('openregister', 'Table of register {register}', { register: table.register }) }}</h3>
					<p v-if="table.indexes.length === 0" class="empty">
						{{ t('openregister', 'This table has no indexes yet.') }}
					</p>
					<table v-else>
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Field') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Source') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Name') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="index in table.indexes" :key="index.name">
								<td>{{ index.property || '—' }}</td>
								<td>{{ flagLabel(index.flag) }}</td>
								<td class="indexName">
									{{ index.name }}
								</td>
							</tr>
						</tbody>
					</table>
				</section>
			</template>
		</NcAppContentDetails>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContentDetails, NcLoadingIcon } from '@nextcloud/vue'
import { fetchSchemaIndexes } from '../../services/schemaIndexes.js'

/**
 * The indexes on a schema's tables, one table per register that stores it.
 *
 * A read only: an index is switched on or off on the field, and the table
 * follows on its next synchronisation.
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
 */
export default {
	name: 'SchemaIndexesTab',

	components: {
		NcAppContentDetails,
		NcLoadingIcon,
	},

	props: {
		/** The schema being read. */
		schema: { type: Object, required: true },
	},

	data() {
		return {
			tables: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * How the API should be asked for this schema.
		 *
		 * @return {string|number} The id, else the uuid or slug.
		 *
		 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
		 */
		schemaReference() {
			return this.schema.id || this.schema.uuid || this.schema.slug
		},
	},

	watch: {
		schemaReference: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Read the index list.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { results } = await fetchSchemaIndexes(this.schemaReference)
				this.tables = results.map((table) => ({
					register: table.register,
					indexes: Array.isArray(table.indexes) ? table.indexes : [],
				}))
			} catch {
				this.tables = []
				this.error = t('openregister', 'The index list could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The words for the field setting that asked for an index.
		 *
		 * An index no setting asked for was made by hand, and synchronisation
		 * leaves it alone.
		 *
		 * @param {string|null} flag The flag the API reports.
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
		 */
		flagLabel(flag) {
			const labels = {
				indexed: t('openregister', 'Index for filtering and sorting'),
				searchable: t('openregister', 'Index for text search'),
				facetable: t('openregister', 'Facetable'),
				relation: t('openregister', 'Relations'),
			}
			return labels[flag] || t('openregister', 'Not a field setting (kept on synchronisation)')
		},
	},
}
</script>

<style scoped>
.schemaIndexesTab .lead,
.schemaIndexesTab .empty {
	color: var(--color-text-maxcontrast);
}

.schemaIndexesTab .refusal {
	color: var(--color-error-text);
}

.indexTable {
	margin-block: calc(var(--default-grid-baseline) * 4);
}

.indexTable table {
	width: 100%;
	border-collapse: collapse;
}

.indexTable th,
.indexTable td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.indexTable th {
	color: var(--color-text-maxcontrast);
	font-weight: bold;
}

.indexName {
	font-family: var(--font-face-monospace, monospace);
	overflow-wrap: anywhere;
}
</style>
