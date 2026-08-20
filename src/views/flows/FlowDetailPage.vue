<!--
  The flow canvas, with its toolbar (Save / Run / Check / arrange / zoom).
  The palette and settings live in FlowDetailSidebar, rendered into
  Nextcloud's app sidebar by the manifest's `sidebarComponent`, so the canvas
  keeps the full width.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnFlowDetail :id="$route.params.id" @save="onSave" @run="onRun" />
</template>

<script>
import { CnFlowDetail, useFlowStore } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailPage',
	components: { CnFlowDetail },

	/**
	 * Share the one flow store with the toolbar's save/run handlers.
	 *
	 * @return {object} The setup bindings.
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	setup() {
		return { store: useFlowStore() }
	},

	methods: {
		/**
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 * @return {Promise<void>}
		 */
		async onSave() {
			const saved = await this.store.save()
			// A newly created flow gets its id from the server, so the route has
			// to catch up or a reload would land back on `new`.
			if (saved?.id && this.$route.params.id === 'new') {
				this.$router.replace(`/flows/${saved.id}`)
			}
		},

		/**
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 * @return {Promise<void>}
		 */
		async onRun() {
			await this.store.run({})
		},
	},
}
</script>
