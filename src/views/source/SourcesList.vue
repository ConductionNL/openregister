<script setup>
import { sourceStore, navigationStore, searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="listHeader">
				<NcTextField
					:value.sync="searchStore.search"
					:show-trailing-button="searchStore.search !== ''"
					label="Search"
					class="searchField"
					trailing-button-icon="close"
					@trailing-button-click="sourceStore.refreshSourceList()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton @click="sourceStore.refreshSourceList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Ververs
					</NcActionButton>
					<NcActionButton @click="sourceStore.setSourceItem({}); navigationStore.setModal('editSource')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Bron toevoegen
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="sourceStore.sourceList && sourceStore.sourceList.length > 0">
				<NcListItem v-for="(source, i) in sourceStore.sourceList"
					:key="`${source}${i}`"
					:name="source.name"
					:active="sourceStore.sourceItem?.id === source?.id"
					:force-display-actions="true"
					@click="sourceStore.setSourceItem(source)">
					<template #icon>
						<DatabaseArrowRightOutline :class="sourceStore.sourceItem?.id === source.id && 'selectedSourceIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ source?.description }}
					</template>
					<template #actions>
						<NcActionButton @click="sourceStore.setSourceItem(source); navigationStore.setModal('editSource')">
							<template #icon>
								<Pencil />
							</template>
							Bewerken
						</NcActionButton>
						<NcActionButton @click="sourceStore.setSourceItem(source); navigationStore.setDialog('deleteSource')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Verwijderen
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!sourceStore.sourceList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Bronnen aan het laden" />

		<div v-if="sourceStore.sourceList.length === 0">
			Er zijn nog geen bronnen gedefinieerd.
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'SourcesList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		DatabaseArrowRightOutline,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
	},
	mounted() {
		sourceStore.refreshSourceList()
	},
}
</script>

<style>
/* Styles remain the same */
</style>
