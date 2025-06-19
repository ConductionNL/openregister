<script setup>
import { sourceStore, navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<div class="detailContainer">
		<div id="app-content">
			<div>
				<div class="head">
					<h1 class="h1">
						{{ sourceStore.sourceItem.title }}
					</h1>

					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click @click="navigationStore.setModal('editSource')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click @click="navigationStore.setDialog('deleteSource')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
				<span>{{ sourceStore.sourceItem.description }}</span>

				<div class="detailGrid">
					<div class="gridContent gridFullWidth">
						<b>Type:</b>
						<p>{{ sourceStore.sourceItem.type }}</p>
					</div>
				</div>
				<!-- Add more source-specific details here -->

				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Registers" active>
							<div v-if="filterRegisters.length > 0">
								<NcListItem v-for="(register) in filterRegisters"
									:key="register.id"
									:name="register.title"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<DatabaseOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ register.description }}
									</template>
									<template #actions>
										<NcActionButton close-after-click
											:aria-label="`Go to register '${register.title}'`"
											@click="registerStore.setRegisterItem(register); navigationStore.setSelected('registers')">
											<template #icon>
												<EyeArrowRight :size="20" />
											</template>
											View
										</NcActionButton>
										<NcActionButton close-after-click
											:aria-label="`Edit '${register.title}'`"
											@click="registerStore.setRegisterItem(register); navigationStore.setModal('editRegister')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-if="!filterRegisters.length" class="tabPanel">
								Geen registers gevonden
							</div>
						</BTab>
						<BTab title="Logs">
							<div v-if="false && logs.length">
								<NcListItem v-for="(log, key) in logs"
									:key="key"
									:name="log.title"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<PostOutline disable-menu
											:size="44" />
									</template>
									<template #subname>
										{{ log.description }}
									</template>
								</NcListItem>
							</div>
							<div v-if="true || !logs.length" class="tabPanel">
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
	NcListItem,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import EyeArrowRight from 'vue-material-design-icons/EyeArrowRight.vue'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'

export default {
	name: 'SourceDetails',
	components: {
		NcActions,
		NcActionButton,
		NcListItem,
		BTabs,
		BTab,
	},
	data() {
		return {
			registersLoading: false,
		}
	},
	computed: {
		filterRegisters() {
			return registerStore.registerList.filter((register) => {
				return register.source.toString() === sourceStore.sourceItem.id.toString()
			})
		},
	},
	mounted() {
		this.fetchRegisters()
	},
	methods: {
		fetchRegisters() {
			this.registersLoading = true
			registerStore.refreshRegisterList()
				.then(() => {
					this.registersLoading = false
				})
		},
	},
}
</script>

<style>
/* Styles remain the same */
</style>
