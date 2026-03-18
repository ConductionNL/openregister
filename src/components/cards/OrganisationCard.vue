<template>
	<CnCard
		:title="item.name"
		:description="item.description"
		:active="isActive"
		active-variant="success"
		:labels="organisationLabels"
		:stats="organisationStats">
		<template #icon>
			<OfficeBuilding :size="20" />
		</template>
		<template #actions>
			<NcActions :primary="true" menu-name="Actions">
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click
					@click="$emit('view', item)">
					<template #icon>
						<Eye :size="20" />
					</template>
					Bekijken
				</NcActionButton>
				<NcActionButton v-if="!isActive"
					close-after-click
					@click="$emit('set-active', item.uuid)">
					<template #icon>
						<Check :size="20" />
					</template>
					Instellen als Actief
				</NcActionButton>
				<NcActionButton v-if="canEdit"
					close-after-click
					@click="$emit('edit', item)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Bewerken
				</NcActionButton>
				<NcActionButton v-if="item.website"
					close-after-click
					@click="$emit('go-to', item)">
					<template #icon>
						<OpenInNew :size="20" />
					</template>
					Ga naar organisatie
				</NcActionButton>
				<NcActionButton
					close-after-click
					@click="$emit('add-user', item)">
					<template #icon>
						<AccountMultiplePlus :size="20" />
					</template>
					Add User
				</NcActionButton>
				<NcActionButton v-if="canDelete"
					close-after-click
					@click="organisationStore.setOrganisationItem(item); navigationStore.setModal('deleteOrganisation')">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					Verwijderen
				</NcActionButton>
			</NcActions>
		</template>
	</CnCard>
</template>

<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import { CnCard } from '@conduction/nextcloud-vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Check from 'vue-material-design-icons/Check.vue'

export default {
	name: 'OrganisationCard',
	components: {
		CnCard,
		NcActions,
		NcActionButton,
		OfficeBuilding,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		AccountMultiplePlus,
		Eye,
		OpenInNew,
		Check,
	},
	props: {
		item: {
			type: Object,
			required: true,
		},
		isActive: {
			type: Boolean,
			default: false,
		},
		canEdit: {
			type: Boolean,
			default: false,
		},
		canDelete: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['view', 'set-active', 'edit', 'go-to', 'add-user'],
	computed: {
		organisationLabels() {
			return [
				this.item.isDefault ? { text: 'Standaard', variant: 'warning' } : null,
				this.isActive ? { text: 'Actief', variant: 'success' } : null,
			].filter(Boolean)
		},
		organisationStats() {
			return [
				{ label: 'Leden', value: this.item.users?.length || 0 },
				{ label: 'Eigenaar', value: this.item.owner || 'System' },
				this.item.created
					? { label: 'Aangemaakt', value: this.formatDate(this.item.created) }
					: null,
			].filter(Boolean)
		},
	},
	methods: {
		formatDate(dateString) {
			return new Date(dateString).toLocaleDateString({
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
			}) + ', ' + new Date(dateString).toLocaleTimeString({
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>
