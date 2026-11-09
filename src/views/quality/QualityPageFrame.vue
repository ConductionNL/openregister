<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="viewHeader">
		<h1>{{ title }}</h1>
		<p>{{ description }}</p>
	</div>
	<RegisterSchemaSelector v-if="!noSelector" />
	<slot name="toolbar" />
	<NcEmptyContent
		v-if="!noSelector && !hasSelection"
		:name="t('openregister', 'Select a register and schema')"
		:description="selectDescription">
		<template #icon>
			<slot name="icon" />
		</template>
	</NcEmptyContent>
	<NcLoadingIcon v-else-if="loading" :size="32" />
	<NcEmptyContent v-else-if="empty" :name="emptyName" :description="emptyDescription">
		<template #icon>
			<slot name="icon" />
		</template>
	</NcEmptyContent>
	<slot v-else />
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import RegisterSchemaSelector from './RegisterSchemaSelector.vue'
import { qualityStore } from '../../store/store.js'

/**
 * The frame every data quality page shares: its title and subtitle, the
 * register and schema selector, and the states before its content shows:
 * no selection yet, loading, and nothing to show. The page's content goes
 * in the default slot, its empty-state icon in `icon`, and anything between
 * the selector and the states (a sort bar) in `toolbar`.
 *
 * @spec exclude UI plumbing shared by the quality pages; the store actions carry the @spec tags
 */
export default {
	name: 'QualityPageFrame',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		RegisterSchemaSelector,
	},

	props: {
		title: { type: String, required: true },
		description: { type: String, required: true },
		// A page that reads no register and schema has no selector and no
		// "select" state.
		noSelector: { type: Boolean, default: false },
		selectDescription: { type: String, default: '' },
		empty: { type: Boolean, default: false },
		emptyName: { type: String, default: '' },
		emptyDescription: { type: String, default: undefined },
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — proxies the store's hasSelection getter, no backend contract of its own
		 */
		hasSelection() {
			return qualityStore.hasSelection
		},

		/**
		 * @spec exclude UI plumbing — proxies the store's loading flag, no backend contract of its own
		 */
		loading() {
			return qualityStore.isLoading
		},
	},
}
</script>

<style scoped lang="scss">
.viewHeader h1 {
	margin-bottom: 4px;
}
</style>
