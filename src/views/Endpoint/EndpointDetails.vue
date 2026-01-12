<script setup>
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="head">
					<h1 class="h1">
						{{ endpointStore.endpointItem.name }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click @click="navigationStore.setModal('editEndpoint')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="testEndpoint()">
							<template #icon>
								<PlayCircle :size="20" />
							</template>
							Test
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteEndpoint')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ endpointStore.endpointItem.description }}</span>

				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>ID:</b>
						<p>{{ endpointStore.endpointItem.uuid }}</p>
					</div>
					<div class="gridContent gridFullWidth">
						<b>Endpoint Path:</b>
						<p>{{ endpointStore.endpointItem.endpoint }}</p>
					</div>
					<div class="gridContent">
						<b>Method:</b>
						<p>{{ endpointStore.endpointItem.method }}</p>
					</div>
					<div class="gridContent">
						<b>Target Type:</b>
						<p>{{ endpointStore.endpointItem.targetType }}</p>
					</div>
					<div class="gridContent">
						<b>Target ID:</b>
						<p>{{ endpointStore.endpointItem.targetId || 'N/A' }}</p>
					</div>
					<div class="gridContent">
						<b>Version:</b>
						<p>{{ endpointStore.endpointItem.version }}</p>
					</div>
					<div class="gridContent gridFullWidth" v-if="endpointStore.endpointItem.groups && endpointStore.endpointItem.groups.length > 0">
						<b>Allowed Groups:</b>
						<p>{{ endpointStore.endpointItem.groups.join(', ') }}</p>
					</div>
				</div>
				<!-- Add more endpoint-specific details here -->
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'EndpointDetails',
	components: {
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		PlayCircle,
		TrashCanOutline,
	},
	methods: {
		testEndpoint() {
			endpointStore.testEndpoint(endpointStore.endpointItem)
				.then((result) => {
					if (result.success) {
						OCP.Toast.success('Endpoint tested successfully')
					} else {
						OCP.Toast.error(`Endpoint test failed: ${result.error || result.message}`)
					}
				})
				.catch((error) => {
					OCP.Toast.error(`Error testing endpoint: ${error.message}`)
				})
		},
	},
}
</script>

<style>
/* Styles remain the same */
</style>

