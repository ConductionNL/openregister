<!--
  Flows — OpenRegister's index over the one flow store.

  Passes NO `app` filter, deliberately: OpenRegister owns the store, so this is
  the surface that shows every app's flows. A leaf app's own Flows page passes
  its app id and sees only its own.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnFlowIndexPage @open="openFlow" @create="createFlow" />
</template>

<script>
import { CnFlowIndexPage } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowsIndex',

	components: { CnFlowIndexPage },

	methods: {
		/**
		 * @param {object} flow The activated flow.
		 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
		 * @return {void}
		 */
		openFlow(flow) {
			this.$router.push(`/flows/${flow.id}`)
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
