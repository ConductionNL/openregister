<!--
  The flow canvas. Controls live in FlowDetailSidebar, rendered into
  Nextcloud's app sidebar by the manifest's `sidebarComponent`, so the canvas
  keeps the full width.

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnFlowDetail :id="$route.params.id" />
</template>

<script>
import { CnFlowDetail } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailPage',
	components: { CnFlowDetail },
}
</script>
