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
			<CnRowActions
				:actions="actions"
				:row="item"
				:primary="true"
				menu-name="Actions" />
		</template>
	</CnCard>
</template>

<script>
import { CnCard, CnRowActions } from '@conduction/nextcloud-vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'

export default {
	name: 'OrganisationCard',
	components: {
		CnCard,
		CnRowActions,
		OfficeBuilding,
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
		actions: {
			type: Array,
			default: () => [],
		},
	},
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
