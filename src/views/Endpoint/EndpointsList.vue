<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore, searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<div class="listHeader">
			<NcTextField
				v-model="searchStore.search"
				:showTrailingButton="searchStore.search !== ''"
				:label="t('openregister', 'Search')"
				class="searchField"
				trailingButtonIcon="close"
				@trailingButtonClick="endpointStore.refreshEndpointList()">
				<Magnify :size="20" />
			</NcTextField>
			<NcActions>
				<NcActionButton
					closeAfterClick
					@click="endpointStore.refreshEndpointList()">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('openregister', 'Refresh') }}
				</NcActionButton>
				<NcActionButton
					closeAfterClick
					@click="
						() => {
							endpointStore.setEndpointItem({})
							navigationStore.setModal('editEndpoint')
						}
					">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Add endpoint') }}
				</NcActionButton>
			</NcActions>
		</div>
		<ul
			v-if="
				endpointStore.endpointList && endpointStore.endpointList.length > 0
			">
			<NcListItem
				v-for="(endpoint, i) in endpointStore.endpointList"
				:key="`${endpoint}${i}`"
				:name="endpoint.name"
				:active="endpointStore.endpointItem?.id === endpoint?.id"
				:forceDisplayActions="true"
				@click="endpointStore.setEndpointItem(endpoint)">
				<template #icon>
					<Api
						:class="
							endpointStore.endpointItem?.id === endpoint.id
							&& 'selectedEndpointIcon'
						"
						disableMenu
						:size="44" />
				</template>
				<template #subname>
					{{ endpoint?.method }} {{ endpoint?.endpoint }} ({{
						endpoint?.targetType
					}})
				</template>
				<template #actions>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								endpointStore.setEndpointItem(endpoint)
								navigationStore.setModal('editEndpoint')
							}
						">
						<template #icon>
							<Pencil />
						</template>
						{{ t('openregister', 'Edit') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								endpointStore.setEndpointItem(endpoint)
								navigationStore.setDialog('deleteEndpoint')
							}
						">
						<template #icon>
							<TrashCanOutline />
						</template>
						{{ t('openregister', 'Delete') }}
					</NcActionButton>
				</template>
			</NcListItem>
		</ul>

		<NcLoadingIcon
			v-if="!endpointStore.endpointList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			:name="t('openregister', 'Loading endpoints')" />

		<div v-if="!endpointStore.endpointList.length" class="emptyListHeader">
			{{ t('openregister', 'No endpoints defined') }}
		</div>
	</NcAppContentList>
</template>

<script>
import {
	NcActionButton,
	NcActions,
	NcAppContentList,
	NcListItem,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import Api from 'vue-material-design-icons/Api.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
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
