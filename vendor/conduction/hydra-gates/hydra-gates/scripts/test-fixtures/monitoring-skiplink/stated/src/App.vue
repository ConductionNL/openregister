<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAppRoot
		app-id="fixture"
		:manifest="manifest"
		:registry="registry" />
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'

export default {
	name: 'App',
	components: { CnAppRoot },
}
</script>
