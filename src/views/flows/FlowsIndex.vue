<!--
  Flows — OpenRegister's index over the one flow store.

  Passes NO `app` filter, deliberately: OpenRegister owns the store, so this is
  the surface that shows every app's flows. A leaf app's own Flows page passes
  its app id and sees only its own.

  Rendered on CnIndexPage per ADR-096 — a flow list is an ordinary index
  surface. The SOURCE is external (`:objects` from useFlowStore) because a flow
  is not an OpenRegister object: flow-storage/spec.md forbids storing one as
  one, so there is no register/schema pair for a `type:index` page to bind.
  Both built-in row actions are replaced: the built-in Edit opens the
  schema-driven form dialog (nothing to render without a schema), and a flow
  has no read-only detail page for View — the canvas IS the flow.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnIndexPage
		:title="t('openregister', 'Flows')"
		:description="
			t(
				'openregister',
				'A flow runs a series of steps when something happens — an object changes, a schedule fires, or you run it yourself. This list shows every app\'s flows.',
			)
		"
		:columns="columns"
		:objects="rows"
		:loading="store.loading"
		:selectable="false"
		:showAdd="false"
		:showViewAction="false"
		:showEditAction="false"
		:actions="rowActions"
		rowClickToView
		@rowClick="openFlow">
		<template #header-actions>
			<NcButton variant="primary" @click="createFlow">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('openregister', 'New flow') }}
			</NcButton>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, useFlowStore } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'FlowsIndex',

	components: {
		CnIndexPage,
		NcButton,
		// Pencil is deliberately NOT registered: it is passed as an icon
		// COMPONENT in `rowActions`, never used as a tag in this template.
		Plus,
	},

	/**
	 * Share the one flow store with the editor pages.
	 *
	 * @return {object} The setup bindings.
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	setup() {
		return { store: useFlowStore() }
	},

	computed: {
		/**
		 * @return {Array<object>} The row-action menu: Edit, and only Edit.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 */
		rowActions() {
			return [
				{
					label: this.t('openregister', 'Edit'),
					icon: Pencil,
					handler: (row) => this.openFlow(row),
				},
			]
		},

		/**
		 * @return {Array<object>} The column definitions.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('openregister', 'Name') },
				{ key: 'description', label: this.t('openregister', 'Description') },
				{ key: 'trigger', label: this.t('openregister', 'Trigger') },
				{ key: 'cron', label: this.t('openregister', 'Schedule') },
				{ key: 'app', label: this.t('openregister', 'App') },
				{ key: 'statusLabel', label: this.t('openregister', 'Status') },
			]
		},

		/**
		 * The flows with the status rendered for display.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 */
		rows() {
			return (this.store.flows || []).map((flow) => ({
				...flow,
				statusLabel: this.statusLabel(flow),
			}))
		},
	},

	created() {
		this.store.load({})
	},

	methods: {
		/**
		 * Enabled and dispatchable are NOT the same thing: a trigger fires with
		 * no acting user, so a flow with no owner has no identity to run as and
		 * will not start however enabled it looks.
		 *
		 * @param {object} flow The flow.
		 * @return {string} The label.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 */
		statusLabel(flow) {
			if (!flow.enabled) {
				return this.t('openregister', 'Disabled')
			}
			if (!flow.owner) {
				return this.t(
					'openregister',
					'Enabled, but has no owner — it will not start',
				)
			}

			return this.t('openregister', 'Enabled')
		},

		/**
		 * @param {object} flow The activated flow.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 * @return {void}
		 */
		openFlow(flow) {
			const id = flow?.id || flow?.uuid
			if (!id) {
				return
			}

			this.$router.push(`/flows/${id}`)
		},

		/**
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 * @return {void}
		 */
		createFlow() {
			this.$router.push('/flows/new')
		},
	},
}
</script>
