<script setup>
import { searchStore } from '../../store/store.js'
</script>

<template>
	<ul>
		<li v-for="(result, i) in searchStore.searchObjectsResult" :key="i">
			{{ result.uuid }}
		</li>
	</ul>
</template>
<script>

export default {
	name: 'SearchList',
	components: {
	},
	mounted() {
		// something
	},
	methods: {
		openLink(link, type = '') {
			window.open(link, type)
		},
	},
}
</script>
