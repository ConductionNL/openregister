<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="head">
					<h1 class="h1">
						{{ configurationStore.configurationItem.title }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click @click="navigationStore.setModal('editConfiguration')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setModal('exportConfiguration')">
							<template #icon>
								<Download :size="20" />
							</template>
							Export
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteConfiguration')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ configurationStore.configurationItem.description }}</span>

				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>Type:</b>
						<p>{{ configurationStore.configurationItem.type }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Owner:</b>
						<p>{{ configurationStore.configurationItem.owner }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Configuration:</b>
						<pre>{{ JSON.stringify(configurationStore.configurationItem.configuration, null, 2) }}</pre>
					</div>
				</div>

				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Configuration" active>
							<div class="tabPanel">
								<pre>{{ JSON.stringify(configurationStore.configurationItem.configuration, null, 2) }}</pre>
							</div>
						</BTab>
						<BTab title="Logs">
							<div class="tabPanel">
								No logs found
							</div>
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import {
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'

export default {
	name: 'ConfigurationDetails',
	components: {
		NcActions,
		NcActionButton,
		BTabs,
		BTab,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Download,
	},
}
</script>

<style>
.detailContainer {
	padding: 20px;
}

.head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.h1 {
	margin: 0;
}

.detailGrid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	margin: 20px 0;
}

.gridContent {
	padding: 10px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.gridFullWidth {
	grid-column: 1 / -1;
}

.tabContainer {
	margin-top: 20px;
}

.tabPanel {
	padding: 20px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

pre {
	white-space: pre-wrap;
	word-wrap: break-word;
	margin: 0;
	max-height: 300px;
	overflow-y: auto;
}
</style>
