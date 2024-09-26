<script setup>
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<SourcesList />
		</template>
		<template #default>
			<NcEmptyContent v-if="!sourceStore.sourceItem || navigationStore.selected != 'sources'"
				class="detailContainer"
				name="No source"
				description="No source selected yet">
				<template #icon>
					<DatabaseArrowRightOutline />
				</template>
				<template #action>
					<NcButton type="primary" @click="sourceStore.setSourceItem(null); navigationStore.setModal('editSource')">
						Add source
					</NcButton>
				</template>
			</NcEmptyContent>
			<SourceDetails v-if="sourceStore.sourceItem && navigationStore.selected === 'sources'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import SourcesList from './SourcesList.vue'
import SourceDetails from './SourceDetails.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'

export default {
	name: 'SourcesIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		SourcesList,
		SourceDetails,
		DatabaseArrowRightOutline,
	},
}
</script>
