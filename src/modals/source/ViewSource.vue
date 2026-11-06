<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	navigationStore,
	registerStore,
	schemaStore,
	sourceStore,
} from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'viewSource'"
		:name="`View Source: ${sourceStore.sourceItem?.title || 'Unknown'}`"
		size="large"
		noClose>
		<div class="formContainer viewSourceDialog">
			<!-- Source Details -->
			<div class="sourceDetailsGrid">
				<div class="sourceMainInfo">
					<h2>{{ sourceStore.sourceItem?.title }}</h2>
					<p
						v-if="sourceStore.sourceItem?.description"
						class="sourceDescription">
						{{ sourceStore.sourceItem.description }}
					</p>
				</div>

				<div class="sourceProperties">
					<div class="propertyItem">
						<strong>{{ t('openregister', 'Type') }}:</strong>
						<span>{{ sourceStore.sourceItem?.type || 'Unknown' }}</span>
					</div>
					<div
						v-if="sourceStore.sourceItem?.databaseUrl"
						class="propertyItem">
						<strong>{{ t('openregister', 'Database URL') }}:</strong>
						<span class="urlValue">{{
							sourceStore.sourceItem.databaseUrl
						}}</span>
					</div>
					<div v-if="sourceStore.sourceItem?.created" class="propertyItem">
						<strong>{{ t('openregister', 'Created') }}:</strong>
						<span>{{
							new Date(sourceStore.sourceItem.created).toLocaleString()
						}}</span>
					</div>
					<div v-if="sourceStore.sourceItem?.updated" class="propertyItem">
						<strong>{{ t('openregister', 'Updated') }}:</strong>
						<span>{{
							new Date(sourceStore.sourceItem.updated).toLocaleString()
						}}</span>
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
							<div
								v-for="register in filterRegisters"
								:key="register.id"
								class="registerCard">
								<div class="registerHeader">
									<h3>{{ register.title }}</h3>
									<NcActions>
										<NcActionButton
											closeAfterClick
											@click="viewRegister(register)">
											<template #icon>
												<Eye :size="20" />
											</template>
											View
										</NcActionButton>
										<NcActionButton
											closeAfterClick
											@click="editRegister(register)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
									</NcActions>
								</div>
								<p
									v-if="register.description"
									class="registerDescription">
									{{ register.description }}
								</p>
							</div>
						</div>
						<div v-else class="emptyTabContent">
							<NcEmptyContent
								:name="t('openregister', 'No registers found')"
								:description="
									t(
										'openregister',
										'This source has no associated registers.',
									)
								">
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
								:description="
									t(
										'openregister',
										'No logs are available for this source.',
									)
								">
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
			<NcActionButton closeAfterClick @click="editSource">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Source
			</NcActionButton>
			<NcActionButton closeAfterClick @click="deleteSource">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
				Delete Source
			</NcActionButton>
			<NcButton variant="primary" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</template>
	</NcDialog>

	<EditRegisterFormDialog
		v-if="showEditRegisterDialog"
		:item="editingRegister"
		:afterSave="() => { fetchRegisters() }"
		@close="showEditRegisterDialog = false" />
</template>

<script>
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcDialog,
	NcEmptyContent,
} from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import EditRegisterFormDialog from '../register/EditRegisterFormDialog.vue'

export default {
	name: 'ViewSource',
	components: {
		EditRegisterFormDialog,
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
			showEditRegisterDialog: false,
			editingRegister: null,
		}
	},

	computed: {
		/**
		 * @spec exclude UI display helper — filters registers belonging to the current source.
		 */
		filterRegisters() {
			if (!registerStore.registerList || !sourceStore.sourceItem?.id) {
				return []
			}
			return registerStore.registerList.filter((register) => {
				return (
					register.source
					&& register.source.toString()
						=== sourceStore.sourceItem.id.toString()
				)
			})
		},
	},

	mounted() {
		this.fetchRegisters()
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		closeModal() {
			navigationStore.setModal(false)
		},

		/**
		 * @spec exclude Modal navigation plumbing — opens the edit-source modal.
		 */
		editSource() {
			navigationStore.setModal('editSource')
		},

		/**
		 * @spec exclude Modal navigation plumbing — opens the delete-source dialog.
		 */
		deleteSource() {
			navigationStore.setModal(false)
			navigationStore.setDialog('deleteSource')
		},

		/**
		 * @param {object} register
		 * @spec exclude UI navigation handler — selects a register and routes to the registers view.
		 */
		viewRegister(register) {
			registerStore.setRegisterItem(register)
			navigationStore.setModal(false)
			this.$router.push('/registers')
		},

		/**
		 * @param {object} register
		 * @spec exclude UI event handler — opens the inline edit-register dialog.
		 */
		editRegister(register) {
			this.editingRegister = register
			this.showEditRegisterDialog = true
		},

		/**
		 * @spec exclude Modal data-load plumbing — refreshes the register list for the source.
		 */
		fetchRegisters() {
			this.registersLoading = true
			registerStore
				.refreshRegisterList()
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

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

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

@include property-item-rules;

.urlValue {
	word-break: break-all;
	font-family: monospace;
}

.tabContainer {
	margin-top: 1rem;
}

@include tab-headers-rules;

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

@include empty-tab-content-rules;
</style>
