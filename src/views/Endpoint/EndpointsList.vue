<script setup>
import { endpointStore, navigationStore, searchStore } from '../../store/store.js'
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
					@trailing-button-click="endpointStore.refreshEndpointList()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton close-after-click @click="endpointStore.refreshEndpointList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="endpointStore.setEndpointItem({}); navigationStore.setModal('editEndpoint')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add endpoint
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="endpointStore.endpointList && endpointStore.endpointList.length > 0">
				<NcListItem v-for="(endpoint, i) in endpointStore.endpointList"
					:key="`${endpoint}${i}`"
					:name="endpoint.name"
					:active="endpointStore.endpointItem?.id === endpoint?.id"
					:force-display-actions="true"
					@click="endpointStore.setEndpointItem(endpoint)">
					<template #icon>
						<Api :class="endpointStore.endpointItem?.id === endpoint.id && 'selectedEndpointIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ endpoint?.method }} {{ endpoint?.endpoint }} ({{ endpoint?.targetType }})
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setModal('editEndpoint')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="endpointStore.setEndpointItem(endpoint); navigationStore.setDialog('deleteEndpoint')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!endpointStore.endpointList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading endpoints" />

		<div v-if="!endpointStore.endpointList.length" class="emptyListHeader">
			No endpoints defined
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Api from 'vue-material-design-icons/Api.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'EndpointsList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		Api,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
	},
	mounted() {
		endpointStore.refreshEndpointList()
	},
}
</script>

<style>
/* Styles remain the same */
</style>

