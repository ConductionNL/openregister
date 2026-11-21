<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="register-model-diagram" data-testid="register-model-diagram">
		<div v-if="loading" class="register-model-diagram__state">
			<NcLoadingIcon :size="32" />
			<span>{{ t('openregister', 'Loading…') }}</span>
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ t('openregister', 'Could not load the register model') }}
		</NcNoteCard>
		<template v-else>
			<div class="register-model-diagram__toolbar">
				<NcButton data-testid="register-model-download" @click="downloadSvg">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('openregister', 'Download as SVG') }}
				</NcButton>
			</div>
			<div class="register-model-diagram__canvas">
				<CnGraphCanvas
					:nodes="nodes"
					:edges="edges"
					:fitMaxZoom="1"
					@nodesChange="onNodesChange"
					@nodeSelect="(event) => openSchema(event && event.node && event.node.id)"
					@nodeActivate="openSchema"
					@edgeSelect="(event) => openEdge(event && event.edge && event.edge.id)"
					@edgeLabelClick="openEdge">
					<template #node="{ node }">
						<div class="register-model-diagram__node"
							:class="{
								'register-model-diagram__node--external': node.data.external,
								'register-model-diagram__node--dangling': node.data.kind === 'dangling',
							}">
							<strong class="register-model-diagram__title">{{ nodeTitle(node) }}</strong>
							<span v-if="node.data.external && node.data.readable" class="register-model-diagram__note">
								{{ t('openregister', 'In another register') }}
							</span>
							<ul v-if="node.data.properties.length" class="register-model-diagram__properties">
								<li v-for="property in node.data.properties" :key="property.name">
									{{ property.name }}<span v-if="property.type" class="register-model-diagram__type">: {{ property.type }}</span>
								</li>
							</ul>
						</div>
					</template>
					<template #edge-label="{ edge }">
						<span class="register-model-diagram__edge-label">{{ edgeLabel(edge) }}</span>
					</template>
				</CnGraphCanvas>
			</div>
			<table class="register-model-diagram__table" data-testid="register-model-table">
				<caption>{{ t('openregister', 'Links between schemas') }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('openregister', 'Schema') }}
						</th>
						<th scope="col">
							{{ t('openregister', 'Property') }}
						</th>
						<th scope="col">
							{{ t('openregister', 'Links to') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="rows.length === 0">
						<td colspan="3">
							{{ t('openregister', 'This register has no links between its schemas') }}
						</td>
					</tr>
					<tr v-for="row in rows" :key="row.id">
						<td>
							<a :href="schemaHref(row.fromId)" @click.prevent="openSchema(row.fromId)">{{ row.fromTitle }}</a>
						</td>
						<td>{{ row.property }} ({{ row.marker }})<span v-if="row.inverse"> ↔ {{ row.inverse }}</span></td>
						<td>
							<a v-if="row.toId" :href="schemaHref(row.toId)" @click.prevent="openSchema(row.toId)">{{ row.toTitle }}</a>
							<span v-else-if="row.toState === 'dangling'">{{ t('openregister', 'Broken link') }}: {{ row.target }}</span>
							<span v-else>{{ t('openregister', 'A schema you cannot open') }}</span>
							<span v-if="row.toState === 'external'"> ({{ t('openregister', 'In another register') }})</span>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import {
	applyPositionChanges,
	edgeRows,
	modelLayoutKey,
	modelToSvg,
	parsePositions,
	toCanvasEdges,
	toCanvasNodes,
} from './registerModel.js'

/**
 * The register's model as a diagram: its schemas as boxes, the links between
 * them as lines, a table of the same links for keyboard and screen-reader
 * users, and an SVG download.
 *
 * Positions a user moves are kept per user and per register in the generic
 * preferences endpoint. The canvas never moves a box itself; this component
 * applies every position change and saves when a drag ends.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 */
export default {
	name: 'RegisterModelDiagram',
	components: { CnGraphCanvas, Download, NcButton, NcLoadingIcon, NcNoteCard },
	props: {
		/** The register id. */
		registerId: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			model: { nodes: [], edges: [] },
			positions: {},
		}
	},

	computed: {
		/**
		 * The boxes, with saved positions winning over the layout.
		 *
		 * @return {Array<object>} Canvas nodes.
		 */
		nodes() {
			return toCanvasNodes(this.model, this.positions)
		},

		/**
		 * The lines.
		 *
		 * @return {Array<object>} Canvas edges.
		 */
		edges() {
			return toCanvasEdges(this.model)
		},

		/**
		 * The table rows.
		 *
		 * @return {Array<object>} One row per link.
		 */
		rows() {
			return edgeRows(this.model)
		},
	},

	watch: {
		registerId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Fetch the model and the saved positions; a missing layout is no layout.
		 *
		 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = false
			const id = encodeURIComponent(String(this.registerId))
			const [model, layout] = await Promise.allSettled([
				axios.get(generateUrl(`/apps/openregister/api/registers/${id}/model`)),
				axios.get(generateUrl(`/apps/openregister/api/preferences/${modelLayoutKey(this.registerId)}`)),
			])

			if (model.status === 'fulfilled') {
				this.model = model.value.data
			} else {
				this.error = true
			}

			this.positions = layout.status === 'fulfilled' ? parsePositions(layout.value.data?.value) : {}
			this.loading = false
		},

		/**
		 * Move boxes as they are dragged, and save the layout when a drag ends.
		 *
		 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
		 * @param {Array<object>} changes Vue Flow's node changes.
		 * @return {Promise<void>}
		 */
		async onNodesChange(changes) {
			const { positions, save } = applyPositionChanges(this.positions, changes)
			this.positions = positions
			if (!save) {
				return
			}

			try {
				await axios.put(
					generateUrl(`/apps/openregister/api/preferences/${modelLayoutKey(this.registerId)}`),
					{ value: JSON.stringify(positions) },
				)
			} catch (error) {
				// The box stays where it was dropped for this visit; only the
				// next visit loses it, which is not worth interrupting for.
				// eslint-disable-next-line no-console
				console.error('Could not save the diagram layout', error)
			}
		},

		/**
		 * The title of a box.
		 *
		 * @param {object} node A canvas node.
		 * @return {string} The title.
		 */
		nodeTitle(node) {
			if (node.data.kind === 'dangling') {
				return `${t('openregister', 'Broken link')}: ${node.data.target ?? ''}`
			}
			if (node.data.readable === false) {
				return t('openregister', 'A schema you cannot open')
			}
			return node.data.title || node.data.slug || node.id
		},

		/**
		 * The label of a line: the property, its 1 or n marker and the inverse.
		 *
		 * @param {object} edge A canvas edge.
		 * @return {string} The label.
		 */
		edgeLabel(edge) {
			const inverse = edge.data.inverse ? ` ↔ ${edge.data.inverse}` : ''
			return `${edge.data.property} (${edge.data.marker})${inverse}`
		},

		/**
		 * The schema page of a schema, as a link target.
		 *
		 * @param {string} id The schema id.
		 * @return {string} The href.
		 */
		schemaHref(id) {
			return this.$router ? this.$router.resolve(`/schemas/${id}`).href : `#/schemas/${id}`
		},

		/**
		 * Open a schema's page, unless the box is not a schema the reader may open.
		 *
		 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
		 * @param {string} id The node id.
		 * @return {void}
		 */
		openSchema(id) {
			const node = this.nodes.find((candidate) => candidate.id === String(id))
			if (!node || node.data.kind !== 'schema' || node.data.readable === false) {
				return
			}
			this.$router.push(`/schemas/${node.id}`)
		},

		/**
		 * Open the schema whose property a line is: links are edited in the property editor.
		 *
		 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
		 * @param {string} id The edge id.
		 * @return {void}
		 */
		openEdge(id) {
			const edge = this.edges.find((candidate) => candidate.id === id)
			if (edge) {
				this.openSchema(edge.source)
			}
		},

		/**
		 * Save the drawn model as an SVG file.
		 *
		 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
		 * @return {void}
		 */
		downloadSvg() {
			const svg = modelToSvg(this.nodes, this.edges, {
				hidden: t('openregister', 'A schema you cannot open'),
				dangling: t('openregister', 'Broken link'),
			})
			const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }))
			const link = document.createElement('a')
			link.href = url
			link.download = `register-${String(this.registerId)}-model.svg`
			document.body.appendChild(link)
			link.click()
			link.remove()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.register-model-diagram {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 16px;
}

.register-model-diagram__state {
	display: flex;
	align-items: center;
	gap: 8px;
}

.register-model-diagram__toolbar {
	display: flex;
	justify-content: flex-end;
}

.register-model-diagram__canvas {
	height: 560px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.register-model-diagram__node {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 180px;
	padding: 8px 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.register-model-diagram__node--external,
.register-model-diagram__node--dangling {
	border-style: dashed;
}

.register-model-diagram__node--dangling {
	border-color: var(--color-error);
}

.register-model-diagram__note,
.register-model-diagram__type {
	color: var(--color-text-maxcontrast);
}

.register-model-diagram__properties {
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: var(--font-size-small, 13px);
}

.register-model-diagram__edge-label {
	font-size: var(--font-size-small, 13px);
}

.register-model-diagram__table {
	width: 100%;
	border-collapse: collapse;
}

.register-model-diagram__table caption {
	text-align: start;
	font-weight: bold;
	padding-bottom: 8px;
}

.register-model-diagram__table th,
.register-model-diagram__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
