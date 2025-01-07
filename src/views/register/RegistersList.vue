<script setup>
import { registerStore, navigationStore, searchStore } from '../../store/store.js'
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
					@trailing-button-click="registerStore.refreshRegisterList()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton @click="registerStore.refreshRegisterList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton @click="registerStore.setRegisterItem(null); navigationStore.setModal('uploadRegister')">
						<template #icon>
							<Upload :size="20" />
						</template>
						Upload Register
					</NcActionButton>
					<NcActionButton @click="registerStore.setRegisterItem(null); navigationStore.setModal('editRegister')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add Register
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="registerStore.registerList && registerStore.registerList.length > 0">
				<NcListItem v-for="(register, i) in registerStore.registerList"
					:key="`${register}${i}`"
					:name="register.title"
					:active="registerStore.registerItem?.id === register?.id"
					:force-display-actions="true"
					@click="registerStore.setRegisterItem(register)">
					<template #icon>
						<DatabaseOutline :class="registerStore.registerItem?.id === register.id && 'selectedRegisterIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ register?.description }}
					</template>
					<template #actions>
						<NcActionButton @click="registerStore.setRegisterItem(register); navigationStore.setModal('editRegister')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton @click="registerStore.setRegisterItem(register); navigationStore.setDialog('deleteRegister')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!registerStore.registerList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading Registers" />

		<div v-if="registerStore.registerList.length === 0">
			No registers have been defined yet.
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'RegistersList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		DatabaseOutline,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
		Upload,
	},
	mounted() {
		registerStore.refreshRegisterList()
	},
}
</script>

<style>
/* Styles remain the same */
</style>
