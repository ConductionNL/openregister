<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->

<!--
  RecordsTreeView: a saved view of a schema with a hierarchy, shown as a tree
  (records-tree-view D-5).

  It loads one level at a time: the roots first (`_roots=true`, which also
  returns readable records whose parent the viewer cannot read, marked
  `@self.parentHidden`), and a branch when it is opened. Each request is paged
  and asks for `@self.childCount`, so a node with children can be opened
  without loading them first. Choosing a record opens it. The open branches
  are remembered per view, so going back from a record shows the tree as it
  was.

  @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
-->
<template>
	<div class="records-tree" data-testid="records-tree">
		<NcNoteCard v-if="!parentField" type="warning">
			<p>{{ t('openregister', 'This schema declares no hierarchy, so its objects cannot be shown as a tree.') }}</p>
		</NcNoteCard>
		<NcNoteCard v-else-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="hasHiddenParents" type="info">
			<p>{{ t('openregister', 'Some objects are shown at the top because you cannot see their parent.') }}</p>
		</NcNoteCard>

		<p v-if="loadingRoots" class="records-tree__loading">
			{{ t('openregister', 'Loading…') }}
		</p>
		<CnTreeView
			v-else-if="parentField"
			:nodes="nodes"
			:expandedIds="expandedIds"
			:selectedId="selectedId"
			idKey="id"
			labelKey="label"
			childrenKey="children"
			:focusableRows="true"
			:emptyLabel="t('openregister', 'No objects found')"
			@update:expandedIds="onExpandedIds"
			@select="onSelect">
			<template #label="{ node }">
				<span v-if="node.placeholder" class="records-tree__placeholder">
					{{ t('openregister', 'Loading…') }}
				</span>
				<span v-else class="records-tree__node">
					<span class="records-tree__label">{{ node.label }}</span>
					<span
						v-if="node.childCount > 0"
						class="records-tree__count">
						{{ node.childCount }}
					</span>
					<span
						v-if="node.parentHidden"
						class="records-tree__hidden-parent">
						{{ t('openregister', 'Parent not visible to you') }}
					</span>
					<span
						v-for="detail in node.details"
						:key="detail.field"
						class="records-tree__detail">
						{{ detail.value }}
					</span>
				</span>
			</template>
		</CnTreeView>
		<div v-if="hasMoreRoots && !loadingRoots" class="records-tree__more">
			<NcButton :disabled="loadingMore" @click="loadRoots(rootPage + 1)">
				{{ t('openregister', 'Load more') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { CnTreeView } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	branchParams,
	findNode,
	hierarchyParentField,
	LEVEL_LIMIT,
	needsBranch,
	newlyOpened,
	recordId,
	rememberBranches,
	rememberedBranches,
	rootParams,
	treeNodes,
	withChildren,
} from '../../services/recordsTree.js'

export default {
	name: 'RecordsTreeView',

	components: { CnTreeView, NcButton, NcNoteCard },

	props: {
		/** The register id or slug of the view. */
		register: {
			type: [String, Number],
			required: true,
		},

		/** The schema of the view, with its configuration. */
		schema: {
			type: Object,
			default: null,
		},

		/** The view's tree presentation: `labelField` and optional `fields`. */
		config: {
			type: Object,
			default: () => ({}),
		},

		/** What the open branches are remembered under. */
		viewKey: {
			type: String,
			default: '',
		},
	},

	emits: ['open'],

	data() {
		return {
			nodes: [],
			expandedIds: [],
			selectedId: null,
			loadingRoots: false,
			loadingMore: false,
			rootPage: 1,
			hasMoreRoots: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The parent property the schema's hierarchy declares.
		 *
		 * @return {string|null}
		 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
		 */
		parentField() {
			return hierarchyParentField(this.schema)
		},

		/**
		 * Whether a listed root is there because its parent is hidden.
		 *
		 * @return {boolean}
		 */
		hasHiddenParents() {
			return this.nodes.some((node) => node.parentHidden)
		},
	},

	mounted() {
		this.start()
	},

	methods: {
		t,

		/**
		 * Load the roots, then reopen what was open on this view before.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
		 */
		async start() {
			if (!this.parentField) {
				return
			}
			await this.loadRoots(1)
			for (const id of rememberedBranches(this.viewKey)) {
				if (findNode(this.nodes, id)) {
					await this.openBranch(id)
					this.expandedIds = [...this.expandedIds, id]
				}
			}
		},

		/**
		 * Load one page of roots.
		 *
		 * @param {number} page The page, from 1.
		 * @return {Promise<void>}
		 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
		 */
		async loadRoots(page) {
			const first = page === 1
			this.loadingRoots = first
			this.loadingMore = !first
			this.error = ''
			try {
				const objects = await this.list(rootParams(page))
				const nodes = treeNodes(objects, this.config)
				this.nodes = first ? nodes : [...this.nodes, ...nodes]
				this.rootPage = page
				this.hasMoreRoots = objects.length === LEVEL_LIMIT
			} catch {
				this.error = t('openregister', 'Part of the tree could not be loaded.')
			} finally {
				this.loadingRoots = false
				this.loadingMore = false
			}
		},

		/**
		 * Load a branch the first time it is opened.
		 *
		 * @param {string} id The node.
		 * @return {Promise<void>}
		 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
		 */
		async openBranch(id) {
			const node = findNode(this.nodes, id)
			if (!node || !needsBranch(node)) {
				return
			}
			try {
				const objects = await this.list(branchParams(this.parentField, id, 1))
				this.nodes = withChildren(this.nodes, id, treeNodes(objects, this.config))
			} catch {
				this.error = t('openregister', 'Part of the tree could not be loaded.')
			}
		},

		/**
		 * Follow the open branches, loading the ones just opened.
		 *
		 * @param {Array<string>} ids The open ids.
		 * @return {Promise<void>}
		 */
		async onExpandedIds(ids) {
			const opened = newlyOpened(this.expandedIds, ids)
			this.expandedIds = ids
			rememberBranches(this.viewKey, ids)
			for (const id of opened) {
				await this.openBranch(id)
			}
		},

		/**
		 * Open the chosen record.
		 *
		 * @param {object} node The node.
		 * @return {void}
		 */
		onSelect(node) {
			if (!node || node.placeholder) {
				return
			}
			this.selectedId = node.id
			this.$emit('open', node.object)
		},

		/**
		 * One page of the view's records.
		 *
		 * @param {object} params The query parameters.
		 * @return {Promise<Array<object>>} The records.
		 */
		async list(params) {
			const schemaId = this.schema?.id ?? this.schema?.slug
			const { data } = await axios.get(
				generateUrl('/apps/openregister/api/objects/{register}/{schema}', { register: this.register, schema: schemaId }),
				{ params },
			)
			const results = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
			return results.filter((object) => recordId(object) !== '')
		},
	},
}
</script>

<style scoped>
.records-tree {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
}

.records-tree__node {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline) * 2);
}

.records-tree__count {
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.85em;
}

.records-tree__hidden-parent,
.records-tree__detail,
.records-tree__placeholder,
.records-tree__loading {
	color: var(--color-text-maxcontrast);
}

.records-tree__more {
	display: flex;
	justify-content: center;
}
</style>
