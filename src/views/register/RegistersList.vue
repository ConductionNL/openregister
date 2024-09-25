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
						Ververs
					</NcActionButton>
					<NcActionButton @click="registerStore.setRegisterItem(null); navigationStore.setModal('editRegister')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Register toevoegen
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="registerStore.registerList && registerStore.registerList.length > 0">
				<NcListItem v-for="(register, i) in registerStore.registerList"
					:key="`${register}${i}`"
					:name="register.name"
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
							Bewerken
						</NcActionButton>
						<NcActionButton @click="registerStore.setRegisterItem(register); navigationStore.setDialog('deleteRegister')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Verwijderen
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!registerStore.registerList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Registers aan het laden" />

		<div v-if="registerStore.registerList.length === 0">
			Er zijn nog geen registers gedefinieerd.
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
	},
	mounted() {
		registerStore.refreshRegisterList()
	},
}
</script>

<style>
/* Styles remain the same */
</style>
