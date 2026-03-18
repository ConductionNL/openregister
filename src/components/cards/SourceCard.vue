<template>
	<div class="sourceCard">
		<div class="cardHeader">
			<h2 v-tooltip.bottom="item.description">
				<DatabaseArrowRightOutline :size="20" />
				{{ item.title }}
			</h2>
			<NcActions :primary="true" menu-name="Actions">
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click
					@click="$emit('view', item)">
					<template #icon>
						<Eye :size="20" />
					</template>
					View
				</NcActionButton>
				<NcActionButton close-after-click
					@click="$emit('edit', item)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcActionButton>
				<NcActionButton close-after-click
					@click="$emit('delete', item)">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					Delete
				</NcActionButton>
			</NcActions>
		</div>

		<div class="sourceInfo">
			<p v-if="item.description" class="description">
				{{ item.description }}
			</p>
			<div class="sourceStats">
				<div class="stat">
					<span class="statLabel">{{ t('openregister', 'Type') }}:</span>
					<span class="statValue">{{ item.type || 'Unknown' }}</span>
				</div>
				<div v-if="item.databaseUrl" class="stat">
					<span class="statLabel">{{ t('openregister', 'Database URL') }}:</span>
					<span class="statValue truncatedUrl">{{ item.databaseUrl }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'SourceCard',
	components: {
		NcActions,
		NcActionButton,
		DatabaseArrowRightOutline,
		DotsHorizontal,
		Eye,
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
.sourceCard {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	height: 100%;
	display: flex;
	flex-direction: column;
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

.sourceInfo {
    flex-grow: 1;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
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

.sourceStats {
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

.truncatedUrl {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
