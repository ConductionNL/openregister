<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="referenced-by-tab">
		<div v-if="loading" class="referenced-by-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<NcEmptyContent
			v-else-if="error"
			:name="t('openregister', 'The referencing records could not be read')"
			:description="errorMessage">
			<template #icon>
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="groups.length === 0"
			:name="t('openregister', 'Nothing points here yet')"
			:description="
				t(
					'openregister',
					'When a record references this object, it shows up here with its status and when it last changed.',
				)
			">
			<template #icon>
				<LinkVariantOff :size="44" />
			</template>
		</NcEmptyContent>

		<div v-else>
			<section
				v-for="group in groups"
				:key="group.schema.id"
				class="referenced-by-tab__group">
				<h3 class="referenced-by-tab__heading">
					{{ group.schema.title || group.schema.slug }}
					<span class="referenced-by-tab__count">{{ group.total }}</span>
				</h3>
				<NcListItem
					v-for="record in group.results"
					:key="record.id"
					:name="record.title || record.id"
					:bold="false"
					:forceDisplayActions="true"
					@click="open(group, record)">
					<template #icon>
						<CubeOutline disableMenu :size="44" />
					</template>
					<template #subname>
						{{ subname(record) }}
					</template>
				</NcListItem>
				<div
					v-if="group.total > group.results.length"
					class="referenced-by-tab__more">
					{{ moreLabel(group) }}
				</div>
			</section>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcListItem, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'

/**
 * ReferencedByTab — the records that point at this object, grouped by schema.
 *
 * The reverse of the Uses tab. An address, an asset or a licence read as the
 * thing several cases hinge on: one section per schema, each record with its
 * title, its status and when it last changed. Reads
 * `GET /api/objects/{register}/{schema}/{id}/referenced-by`, which applies the
 * caller's access inside the query, so a caller who may read one of three sees
 * one of three and a total of one.
 *
 * Spec: openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */
export default {
	name: 'ReferencedByTab',

	components: {
		NcEmptyContent,
		NcListItem,
		NcLoadingIcon,
		AlertCircleOutline,
		CubeOutline,
		LinkVariantOff,
	},

	props: {
		register: {
			type: [String, Number],
			required: true,
		},

		schema: {
			type: [String, Number],
			required: true,
		},

		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			error: false,
			errorMessage: '',
			groups: [],
			total: 0,
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * @param {string} newId The object now being read.
			 * @spec exclude watcher refetching the reverse view on objectId change, UI plumbing
			 */
			handler(newId) {
				if (newId) {
					this.fetchReferencedBy()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the records that reference this object, grouped by schema.
		 *
		 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
		 * @return {Promise<void>}
		 */
		async fetchReferencedBy() {
			this.loading = true
			this.error = false
			this.errorMessage = ''

			const url = generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}/referenced-by',
				{
					register: this.register,
					schema: this.schema,
					id: this.objectId,
				},
			)

			try {
				const response = await axios.get(url)
				this.groups = response.data?.groups || []
				this.total = response.data?.total || 0
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			} finally {
				this.loading = false
			}
		},

		/**
		 * The line under a record's title: its status and when it last changed.
		 *
		 * @param {object} record One referencing record's summary.
		 * @spec exclude display formatting of a record summary, UI plumbing
		 * @return {string} The subtitle.
		 */
		subname(record) {
			const parts = []
			if (record.status) {
				parts.push(record.status)
			}

			if (record.updated) {
				parts.push(new Date(record.updated).toLocaleString())
			}

			return parts.join(' · ')
		},

		/**
		 * How many of a group's records are not on this page.
		 *
		 * @param {object} group One schema group.
		 * @spec exclude display formatting of the per-group remainder, UI plumbing
		 * @return {string} The label.
		 */
		moreLabel(group) {
			return t('openregister', 'and {count} more', {
				count: group.total - group.results.length,
			})
		},

		/**
		 * Open a referencing record in its own detail route.
		 *
		 * @param {object} group One schema group.
		 * @param {object} record The record clicked.
		 * @spec exclude navigation to a referencing record, UI plumbing
		 * @return {void}
		 */
		open(group, record) {
			this.$router?.push(
				`/objects/${group.register.slug || group.register.id}/${group.schema.slug || group.schema.id}/${record.id}`,
			)
		},
	},
}
</script>

<style scoped>
.referenced-by-tab__loading {
	display: flex;
	justify-content: center;
	padding: 2rem 0;
}

.referenced-by-tab__group {
	margin-block-end: 1.5rem;
}

.referenced-by-tab__heading {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 1rem;
	font-weight: 600;
	margin-block: 0.5rem;
}

.referenced-by-tab__count {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
	padding: 0 0.5rem;
	font-weight: 400;
}

.referenced-by-tab__more {
	color: var(--color-text-maxcontrast);
	padding-inline-start: 0.5rem;
}
</style>
