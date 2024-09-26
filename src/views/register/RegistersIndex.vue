<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<RegistersList />
		</template>
		<template #default>
			<NcEmptyContent v-if="!registerStore.registerItem || navigationStore.selected != 'registers'"
				class="detailContainer"
				name="Geen register"
				description="Nog geen register geselecteerd">
				<template #icon>
					<DatabaseOutline />
				</template>
				<template #action>
					<NcButton type="primary" @click="registerStore.setRegisterItem(null); navigationStore.setModal('editRegister')">
						Register toevoegen
					</NcButton>
				</template>
			</NcEmptyContent>
			<RegisterDetails v-if="registerStore.registerItem && navigationStore.selected === 'registers'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import RegistersList from './RegistersList.vue'
import RegisterDetails from './RegisterDetails.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'

export default {
	name: 'RegistersIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		RegistersList,
		RegisterDetails,
		DatabaseOutline,
	},
}
</script>
