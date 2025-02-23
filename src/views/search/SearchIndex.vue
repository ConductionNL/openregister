<script setup>
import { searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Search objects
		</h2>

		<NcNoteCard v-if="!searchStore.searchObjectsResult?.length && !searchStore.searchObjectsLoading" type="info">
			<p>There are no objects that match your search</p>
		</NcNoteCard>

		<NcLoadingIcon v-if="searchStore.searchObjectsLoading"
			:size="64"
			class="loadingIcon"
			appearance="dark"
			name="Objects loading" />

		<SearchList v-if="searchStore.searchObjectsResult?.length" />
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
