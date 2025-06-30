<script setup>
import { sourceStore, navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'viewSource'"
		:name="`View Source: ${sourceStore.sourceItem?.title || 'Unknown'}`"
		size="large"
		:can-close="false">
		<div class="formContainer viewSourceDialog">
			<!-- Source Details -->
			<div class="sourceDetailsGrid">
				<div class="sourceMainInfo">
					<h2>{{ sourceStore.sourceItem?.title }}</h2>
					<p v-if="sourceStore.sourceItem?.description" class="sourceDescription">
						{{ sourceStore.sourceItem.description }}
					</p>
				</div>

				<div class="sourceProperties">
					<div class="propertyItem">
						<strong>{{ t('openregister', 'Type') }}:</strong>
						<span>{{ sourceStore.sourceItem?.type || 'Unknown' }}</span>
					</div>
					<div v-if="sourceStore.sourceItem?.databaseUrl" class="propertyItem">
						<strong>{{ t('openregister', 'Database URL') }}:</strong>
						<span class="urlValue">{{ sourceStore.sourceItem.databaseUrl }}</span>
					</div>
					<div v-if="sourceStore.sourceItem?.created" class="propertyItem">
						<strong>{{ t('openregister', 'Created') }}:</strong>
						<span>{{ new Date(sourceStore.sourceItem.created).toLocaleString() }}</span>
					</div>
					<div v-if="sourceStore.sourceItem?.updated" class="propertyItem">
						<strong>{{ t('openregister', 'Updated') }}:</strong>
						<span>{{ new Date(sourceStore.sourceItem.updated).toLocaleString() }}</span>
					</div>
				</div>
			</div>

			<!-- Tabs for additional information -->
			<div class="tabContainer">
				<div class="tabHeaders">
					<button
						v-for="(tab, index) in tabs"
						:key="tab"
						class="tabHeader"
						:class="{ active: activeTab === index }"
						@click="activeTab = index">
						{{ tab }}
					</button>
				</div>

				<div class="tabContent">
					<!-- Registers Tab -->
					<div v-if="activeTab === 0" class="tabPanel">
						<div v-if="filterRegisters.length > 0" class="registersGrid">
							<div v-for="register in filterRegisters"
								:key="register.id"
								class="registerCard">
								<div class="registerHeader">
									<h3>{{ register.title }}</h3>
									<NcActions>
										<NcActionButton close-after-click
											@click="viewRegister(register)">
											<template #icon>
												<Eye :size="20" />
											</template>
											View
										</NcActionButton>
										<NcActionButton close-after-click
											@click="editRegister(register)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
									</NcActions>
								</div>
								<p v-if="register.description" class="registerDescription">
									{{ register.description }}
								</p>
							</div>
						</div>
						<div v-else class="emptyTabContent">
							<NcEmptyContent
								:name="t('openregister', 'No registers found')"
								:description="t('openregister', 'This source has no associated registers.')">
								<template #icon>
									<DatabaseOutline :size="64" />
								</template>
							</NcEmptyContent>
						</div>
					</div>

					<!-- Logs Tab -->
					<div v-if="activeTab === 1" class="tabPanel">
						<div class="emptyTabContent">
							<NcEmptyContent
								:name="t('openregister', 'No logs found')"
								:description="t('openregister', 'No logs are available for this source.')">
								<template #icon>
									<PostOutline :size="64" />
								</template>
							</NcEmptyContent>
						</div>
					</div>
				</div>
			</div>
		</div>

		<template #actions>
			<NcActionButton close-after-click @click="editSource">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Source
			</NcActionButton>
			<NcActionButton close-after-click @click="deleteSource">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
				Delete Source
			</NcActionButton>
			<NcButton type="primary" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcActions,
	NcActionButton,
	NcEmptyContent,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

export default {
	name: 'ViewSource',
	components: {
		NcDialog,
		NcButton,
		NcActions,
		NcActionButton,
		NcEmptyContent,
		Cancel,
		Pencil,
		TrashCanOutline,
		DatabaseOutline,
		PostOutline,
		Eye,
	},
	data() {
		return {
			activeTab: 0,
			tabs: [
				this.t('openregister', 'Registers'),
				this.t('openregister', 'Logs'),
			],
			registersLoading: false,
		}
	},
	computed: {
		filterRegisters() {
			if (!registerStore.registerList || !sourceStore.sourceItem?.id) {
				return []
			}
			return registerStore.registerList.filter((register) => {
				return register.source && register.source.toString() === sourceStore.sourceItem.id.toString()
			})
		},
	},
	mounted() {
		this.fetchRegisters()
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
		},
		editSource() {
			navigationStore.setModal('editSource')
		},
		deleteSource() {
			navigationStore.setModal(false)
			navigationStore.setDialog('deleteSource')
		},
		viewRegister(register) {
			registerStore.setRegisterItem(register)
			navigationStore.setModal(false)
			navigationStore.setSelected('registers')
		},
		editRegister(register) {
			registerStore.setRegisterItem(register)
			navigationStore.setModal('editRegister')
		},
		fetchRegisters() {
			this.registersLoading = true
			registerStore.refreshRegisterList()
				.then(() => {
					this.registersLoading = false
				})
				.catch(() => {
					this.registersLoading = false
				})
		},
	},
}
</script>

<style scoped>
.viewSourceDialog {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.sourceDetailsGrid {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.sourceMainInfo h2 {
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
}

.sourceDescription {
	color: var(--color-text-lighter);
	margin: 0;
}

.sourceProperties {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.propertyItem {
	display: flex;
	gap: 0.5rem;
}

.propertyItem strong {
	min-width: 120px;
	color: var(--color-text-lighter);
}

.urlValue {
	word-break: break-all;
	font-family: monospace;
}

.tabContainer {
	margin-top: 1rem;
}

.tabHeaders {
	display: flex;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 1rem;
}

.tabHeader {
	padding: 0.75rem 1rem;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-lighter);
	border-bottom: 2px solid transparent;
	transition: all 0.2s ease;
}

.tabHeader:hover {
	color: var(--color-main-text);
	background-color: var(--color-background-hover);
}

.tabHeader.active {
	color: var(--color-primary);
	border-bottom-color: var(--color-primary);
}

.tabContent {
	min-height: 200px;
}

.tabPanel {
	padding: 1rem 0;
}

.registersGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 1rem;
}

.registerCard {
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.registerHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.registerHeader h3 {
	margin: 0;
	color: var(--color-main-text);
}

.registerDescription {
	color: var(--color-text-lighter);
	margin: 0;
}

.emptyTabContent {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 200px;
}
</style>
