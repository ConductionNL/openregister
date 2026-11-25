<!--
  Action rights: which groups may do what with flows, and which groups may put
  a powerful step (e-mail, notification, Talk message, deleting objects) into a
  flow.

  The rights are grants to a person, not verbs on an object, so they are listed
  apart from the object permissions. Administrators always pass; a right with
  no group chosen is held by administrators alone; "Every signed-in user" is
  the matrix's explicit open grant and is shown, never dropped unseen.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
-->
<template>
	<div class="action-rights">
		<h3>{{ t('openregister', 'Action rights') }}</h3>
		<p class="action-rights__intro">
			{{
				t(
					'openregister',
					'Who may create, change, run and read flows, and who may put a step that sends e-mail, notifications or Talk messages, or deletes objects, into a flow. Administrators always hold every right; a right with no group chosen is held by administrators alone.',
				)
			}}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="saved" type="success">
			{{ t('openregister', 'The action rights are saved.') }}
		</NcNoteCard>

		<section v-for="area in areas" :key="area.id" class="action-rights__area">
			<h4>{{ area.label }}</h4>
			<div
				v-for="right in area.rights"
				:key="right.action"
				class="action-rights__right">
				<div class="action-rights__name">
					<code>{{ right.action }}</code>
					<span
						v-if="right.description"
						class="action-rights__description">
						{{ right.description }}
					</span>
					<span
						v-if="right.nodeTypes.length > 0"
						class="action-rights__description">
						{{
							t('openregister', 'Needed by: {types}', {
								types: right.nodeTypes.join(', '),
							})
						}}
					</span>
				</div>
				<NcSelect
					v-model="selected[right.action]"
					:options="options"
					:multiple="true"
					label="label"
					:inputLabel="t('openregister', 'Groups')"
					:disabled="loading || saving" />
			</div>
		</section>

		<NcButton
			variant="primary"
			:disabled="loading || saving || actions.length === 0"
			@click="save">
			{{ saving ? t('openregister', 'Saving…') : t('openregister', 'Save') }}
		</NcButton>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { loadNextcloudGroupsInto } from '../../../services/groupSearch.js'

/**
 * The matrix value that grants an action to every signed-in user
 * (GenericActionAuthService::EVERYONE).
 *
 * @type {string}
 */
export const EVERYONE = '@authenticated'

/**
 * The endpoint the rights are read from and written to.
 *
 * @return {string} The URL.
 */
function rightsUrl() {
	return generateUrl('/apps/openregister/api/settings/action-rights')
}

/**
 * The area a right is listed under: flow steps, flows, objects, or the first
 * segment of its name.
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 * @param {string} action The right, e.g. `flow.node.send-email`.
 * @return {{id: string, label: string}} The area.
 */
export function areaOf(action) {
	if (action.startsWith('flow.node.')) {
		return { id: 'flow.node', label: t('openregister', 'Flow steps') }
	}
	const prefix = action.split('.')[0]
	if (prefix === 'flow') {
		return { id: 'flow', label: t('openregister', 'Flows') }
	}
	if (prefix === 'object') {
		return { id: 'object', label: t('openregister', 'Objects') }
	}
	return { id: 'other', label: t('openregister', 'Other') }
}

/**
 * The rights grouped by area, in the order flows, flow steps, objects, other;
 * rights keep the order the server lists them in.
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 * @param {Array<object>} actions The rights as `GET /api/settings/action-rights` lists them.
 * @return {Array<{id: string, label: string, rights: Array<object>}>} The areas that hold a right.
 */
export function groupByArea(actions) {
	const order = ['flow', 'flow.node', 'object', 'other']
	const areas = {}
	for (const action of actions) {
		const area = areaOf(action.action)
		areas[area.id] = areas[area.id] || { ...area, rights: [] }
		areas[area.id].rights.push({ ...action, nodeTypes: action.nodeTypes || [] })
	}
	return order.filter((id) => areas[id]).map((id) => areas[id])
}

/**
 * The picker options for the groups of a right. A group the instance no
 * longer has is kept under its id, so saving does not drop it unseen.
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 * @param {Array<string>} ids The group ids the right holds.
 * @param {Array<object>} options The known options.
 * @return {Array<object>} The chosen options.
 */
export function chosenOptions(ids, options) {
	return (ids || []).map(
		(id) => options.find((option) => option.id === id) || { id, label: id },
	)
}

export default {
	name: 'ActionRights',

	components: {
		NcButton,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			saving: false,
			saved: false,
			error: null,
			actions: [],
			nextcloudGroups: [],
			// Per right, the chosen options.
			selected: {},
		}
	},

	computed: {
		/**
		 * Every group, with "every signed-in user" first.
		 *
		 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		options() {
			return [
				{ id: EVERYONE, label: t('openregister', 'Every signed-in user') },
				...this.nextcloudGroups.map((group) => ({
					id: group.id,
					label: group.name,
				})),
			]
		},

		/**
		 * @spec exclude UI plumbing — the grouped rights for the template.
		 * @return {Array<object>} The areas.
		 */
		areas() {
			return groupByArea(this.actions)
		},
	},

	async mounted() {
		await loadNextcloudGroupsInto(this)
		await this.load()
	},

	methods: {
		/**
		 * Read the rights and the groups that hold each.
		 *
		 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(rightsUrl())
				this.applyRights(data)
				this.error = null
			} catch {
				this.error = t(
					'openregister',
					'The action rights could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take over the rights an answer lists.
		 *
		 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
		 * @param {object} data The answer of `GET` or `PUT /api/settings/action-rights`.
		 * @return {void}
		 */
		applyRights(data) {
			this.actions = Array.isArray(data?.actions) ? data.actions : []
			const selected = {}
			for (const action of this.actions) {
				selected[action.action] = chosenOptions(action.groups, this.options)
			}
			this.selected = selected
		},

		/**
		 * Store the groups of every right. The node map is not sent, so the
		 * administrator's overrides stay as they are.
		 *
		 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.saved = false
			const groups = {}
			for (const action of this.actions) {
				groups[action.action] = (this.selected[action.action] || []).map(
					(option) => option.id,
				)
			}
			try {
				const { data } = await axios.put(rightsUrl(), { groups })
				this.applyRights(data)
				this.error = null
				this.saved = true
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('openregister', 'The action rights could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.action-rights__intro,
.action-rights__description {
	color: var(--color-text-maxcontrast);
}

.action-rights__intro {
	margin-block: 4px 12px;
}

.action-rights__right {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
	align-items: center;
	justify-content: space-between;
	padding-block: 8px;
	border-block-end: 1px solid var(--color-border);
}

.action-rights__name {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1 1 280px;
}

.action-rights > * {
	margin-block-end: 12px;
}
</style>
