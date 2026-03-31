<template>
	<CnCard
		:title="item.name"
		:description="item.description"
		:title-tooltip="item.description"
		:labels="applicationLabels"
		:stats="applicationStats">
		<template #icon>
			<ApplicationOutline :size="20" />
		</template>
		<template #actions>
			<NcActions :primary="true" menu-name="Actions">
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click
					@click="applicationStore.setItem(item); navigationStore.setModal('editApplication')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcActionButton>
				<NcActionButton close-after-click
					@click="applicationStore.setItem(item); navigationStore.setDialog('deleteApplication')">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					Delete
				</NcActionButton>
			</NcActions>
		</template>
	</CnCard>
</template>

<script setup>
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import { CnCard } from '@conduction/nextcloud-vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ApplicationCard',
	components: {
		CnCard,
		NcActions,
		NcActionButton,
		ApplicationOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
	},
	props: {
		item: {
			type: Object,
			required: true,
		},
	},
	computed: {
		applicationLabels() {
			if (this.item.active === undefined) return []
			return [{
				text: this.item.active ? 'Active' : 'Inactive',
				variant: this.item.active ? 'success' : 'default',
			}]
		},
		applicationStats() {
			return [
				this.item.version
					? { label: t('openregister', 'Version'), value: this.item.version }
					: null,
				this.item.configurations
					? { label: t('openregister', 'Configurations'), value: this.item.configurations.length }
					: null,
				this.item.registers
					? { label: t('openregister', 'Registers'), value: this.item.registers.length }
					: null,
				this.item.schemas
					? { label: t('openregister', 'Schemas'), value: this.item.schemas.length }
					: null,
			].filter(Boolean)
		},
	},
}
</script>
