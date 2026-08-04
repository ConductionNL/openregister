<!--
  The flow controls, in Nextcloud's app sidebar. Shares `useFlowStore` with the
  canvas, which is why neither needs props from the other.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnFlowSidebar @save="onSave" @run="onRun" />
</template>

<script>
import { CnFlowSidebar, useFlowStore } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailSidebar',

	components: { CnFlowSidebar },

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
