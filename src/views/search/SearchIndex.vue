<script setup>
import { searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<span class="pageHeaderContainer">
			<h2 class="pageHeader">
				Objects table
			</h2>

			<NcLoadingIcon v-if="searchStore.searchObjectsLoading && !!searchStore.searchObjectsResult?.results?.length"
				:size="24"
				class="loadingIcon"
				appearance="dark"
				name="Objects loading" />
		</span>

		<NcNoteCard v-if="!searchStore.searchObjectsResult?.results?.length && !searchStore.searchObjectsLoading" type="info">
			<p>There are no objects that match this filter</p>
		</NcNoteCard>

		<NcLoadingIcon v-if="searchStore.searchObjectsLoading && !searchStore.searchObjectsResult?.results?.length"
			:size="64"
			class="loadingIcon"
			appearance="dark"
			name="Objects loading" />

		<SearchList v-if="searchStore.searchObjectsResult?.results?.length" />
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import SearchList from './SearchList.vue'

export default {
	name: 'SearchIndex',
	components: {
		NcAppContent,
		NcNoteCard,
		NcLoadingIcon,
		SearchList,
	},
}
</script>

<style scoped>
.pageHeaderContainer {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.pageHeaderContainer > .loadingIcon {
    margin-inline-end: 1rem;
}
</style>
