<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<SearchSideBar v-if="navigationStore.selected === 'tableSearch'" />
</template>

<script>
import SearchSideBar from './search/SearchSideBar.vue'

export default {
	name: 'SideBars',
	components: {
		SearchSideBar,
	},
}
</script>
