<script setup>
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div class="applicationCard">
		<div class="cardHeader">
			<h2 v-tooltip.bottom="item.description">
				<ApplicationOutline :size="20" />
				{{ item.name }}
				<span v-if="item.active !== undefined"
					:class="item.active ? 'statusBadge--active' : 'statusBadge--inactive'">
					{{ item.active ? 'Active' : 'Inactive' }}
				</span>
			</h2>
			<NcActions :primary="true" menu-name="Actions">
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click
					@click="applicationStore.setApplicationItem(item); navigationStore.setModal('editApplication')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcActionButton>
				<NcActionButton close-after-click
					@click="applicationStore.setApplicationItem(item); navigationStore.setDialog('deleteApplication')">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					Delete
				</NcActionButton>
			</NcActions>
		</div>

		<div class="applicationInfo">
			<p v-if="item.description" class="description">
				{{ item.description }}
			</p>
			<div class="applicationStats">
				<div v-if="item.version" class="stat">
					<span class="statLabel">{{ t('openregister', 'Version') }}:</span>
					<span class="statValue">{{ item.version }}</span>
				</div>
				<div v-if="item.configurations" class="stat">
					<span class="statLabel">{{ t('openregister', 'Configurations') }}:</span>
					<span class="statValue">{{ item.configurations.length }}</span>
				</div>
				<div v-if="item.registers" class="stat">
					<span class="statLabel">{{ t('openregister', 'Registers') }}:</span>
					<span class="statValue">{{ item.registers.length }}</span>
				</div>
				<div v-if="item.schemas" class="stat">
					<span class="statLabel">{{ t('openregister', 'Schemas') }}:</span>
					<span class="statValue">{{ item.schemas.length }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ApplicationCard',
	components: {
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
}
</script>

<style scoped lang="scss">
.applicationCard {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	height: 100%;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
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

.statusBadge--active,
.statusBadge--inactive {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.statusBadge--active {
	background: var(--color-success);
	color: white;
}

.statusBadge--inactive {
	background: var(--color-text-lighter);
	color: white;
}

.applicationInfo {
	padding: 12px 0 0;
}

.description {
	color: var(--color-text-lighter);
	margin-bottom: 12px;
	font-style: italic;
	word-wrap: break-word;
	overflow-wrap: break-word;
	display: -webkit-box;
	-webkit-line-clamp: 3;
	line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.applicationStats {
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
