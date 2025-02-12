<script setup>
import { searchStore, navigationStore } from '../../store/store.js'
import _ from 'lodash'
</script>

<template>
	<ul>
		<li v-for="(result, i) in searchStore.searchObjectsResult" :key="i">
			{{ result.uuid }}
		</li>
	</ul>
</template>
<script>
import { NcListItem, NcActionButton } from '@nextcloud/vue'

// Icons
import ListBoxOutline from 'vue-material-design-icons/ListBoxOutline.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'SearchList',
	components: {
		NcListItem,
		NcActionButton,
		// Icons
		ListBoxOutline,
		OpenInNew,
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
