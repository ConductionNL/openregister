<script setup>
import { configurationStore, navigationStore, searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="searchListHeader">
				<NcTextField
					:value.sync="searchStore.search"
					:show-trailing-button="searchStore.search !== ''"
					label="Search"
					class="searchField"
					trailing-button-icon="close"
					@trailing-button-click="configurationStore.refreshConfigurationList()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton close-after-click @click="configurationStore.refreshConfigurationList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(null); navigationStore.setModal('editConfiguration')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add Configuration
					</NcActionButton>
					<NcActionButton close-after-click @click="navigationStore.setModal('importConfiguration')">
						<template #icon>
							<Upload :size="20" />
						</template>
						Import Configuration
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="configurationStore.configurationList && configurationStore.configurationList.length > 0">
				<NcListItem v-for="(configuration, i) in configurationStore.configurationList"
					:key="`${configuration}${i}`"
					:name="configuration.title"
					:active="configurationStore.configurationItem?.id === configuration?.id"
					:force-display-actions="true"
					@click="configurationStore.setConfigurationItem(configuration)">
					<template #icon>
						<CogOutline :class="configurationStore.configurationItem?.id === configuration.id && 'selectedConfigurationIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ configuration?.description }}
					</template>
					<template #actions>
						<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setModal('editConfiguration')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setDialog('deleteConfiguration')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!configurationStore.configurationList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading configurations" />

		<div v-if="configurationStore.configurationList.length === 0">
			No configurations have been defined yet.
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'ConfigurationsList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		CogOutline,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
		Upload,
	},
	mounted() {
		configurationStore.refreshConfigurationList()
	},
}
</script>

<style>
.searchListHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px;
}

.searchField {
	flex-grow: 1;
	margin-right: 8px;
}

.loadingIcon {
	margin: 20px auto;
	display: block;
}

.selectedConfigurationIcon {
	color: var(--color-primary);
}
</style>
