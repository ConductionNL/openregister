<template>
	<div class="orgCard"
		:class="{ 'orgCard--active': isActive }">
		<div class="cardHeader">
			<h2>
				<OfficeBuilding :size="20" />
				{{ item.name }}
				<span v-if="item.isDefault" class="defaultBadge">Standaard</span>
				<span v-if="isActive" class="activeBadge">Actief</span>
			</h2>
			<CnRowActions
				:actions="actions"
				:row="item"
				:primary="true"
				menu-name="Actions" />
		</div>

		<div class="organisationInfo">
			<p v-if="item.description" class="description">
				{{ item.description }}
			</p>
			<div class="organisationStats">
				<div class="stat">
					<span class="statLabel">Leden:</span>
					<span class="statValue">{{ item.users?.length || 0 }}</span>
				</div>
				<div class="stat">
					<span class="statLabel">Eigenaar:</span>
					<span class="statValue">{{ item.owner || 'System' }}</span>
				</div>
				<div v-if="item.created" class="stat">
					<span class="statLabel">Aangemaakt:</span>
					<span class="statValue">{{ formatDate(item.created) }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { CnRowActions } from '@conduction/nextcloud-vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'

export default {
	name: 'OrganisationCard',
	components: {
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

<style scoped lang="scss">
.orgCard {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.orgCard--active {
	border: 2px solid var(--color-success) !important;
	background: var(--color-success-light) !important;
}

.cardHeader {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;

	h2 {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 16px;
		margin: 0;
		flex-wrap: wrap;
	}
}

.defaultBadge, .activeBadge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.defaultBadge {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.activeBadge {
	background: var(--color-success);
	color: white;
}

.organisationInfo {
	padding: 12px 0 0;
}

.description {
	color: var(--color-text-lighter);
	margin-bottom: 12px;
	font-style: italic;
}

.organisationStats {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.stat {
	display: flex;
	justify-content: space-between;
}

.statLabel {
	color: var(--color-text-lighter);
	font-size: 12px;
}

.statValue {
	font-weight: 600;
	font-size: 12px;
}
</style>
