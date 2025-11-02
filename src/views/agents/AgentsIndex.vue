<script setup>
import { agentStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'AI Agents') }}
				</h1>
				<p>{{ t('openregister', 'Manage and configure AI agents for automated tasks') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} agents', { showing: paginatedAgents.length, total: agentStore.agentList.length }) }}
					</span>
					<span v-if="selectedAgents.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedAgents.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See agents as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See agents as a table'"
							:button-variant="true"
							value="table"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Table
						</NcCheckboxRadioSwitch>
					</div>

					<NcActions
						:force-name="true"
						:inline="2"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="agentStore.setAgentItem(null); navigationStore.setModal('editAgent')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Agent
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="agentStore.refreshAgentList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="agentStore.loading || agentStore.error || !agentStore.agentList.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="agentStore.loading" :size="64" />
					<RobotOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="agent in paginatedAgents" :key="agent.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="agent.description">
									<RobotOutline :size="20" />
									{{ agent.name }}
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="agentStore.setAgentItem(agent); navigationStore.setModal('editAgent')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="agentStore.setAgentItem(agent); navigationStore.setDialog('deleteAgent')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Agent Details -->
							<div class="agentDetails">
								<p v-if="agent.description" class="agentDescription">
									{{ agent.description }}
								</p>
								<div class="agentInfo">
									<div v-if="agent.type" class="agentInfoItem">
										<strong>{{ t('openregister', 'Type') }}:</strong>
										<span class="agentTypeBadge">{{ agent.type }}</span>
									</div>
									<div v-if="agent.provider" class="agentInfoItem">
										<strong>{{ t('openregister', 'Provider') }}:</strong>
										<span>{{ agent.provider }}</span>
									</div>
									<div v-if="agent.model" class="agentInfoItem">
										<strong>{{ t('openregister', 'Model') }}:</strong>
										<span>{{ agent.model }}</span>
									</div>
									<div v-if="agent.active !== undefined" class="agentInfoItem">
										<strong>{{ t('openregister', 'Status') }}:</strong>
										<span :class="agent.active ? 'status-active' : 'status-inactive'">
											{{ agent.active ? 'Active' : 'Inactive' }}
										</span>
									</div>
									<div v-if="agent.enableRag" class="agentInfoItem">
										<strong>{{ t('openregister', 'RAG') }}:</strong>
										<span class="ragBadge">
											<Brain :size="16" />
											Enabled
										</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>{{ t('openregister', 'Name') }}</th>
									<th>{{ t('openregister', 'Type') }}</th>
									<th>{{ t('openregister', 'Provider') }}</th>
									<th>{{ t('openregister', 'Model') }}</th>
									<th>{{ t('openregister', 'Status') }}</th>
									<th>{{ t('openregister', 'RAG') }}</th>
									<th>{{ t('openregister', 'Created') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="agent in paginatedAgents"
									:key="agent.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedAgents.includes(agent.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedAgents.includes(agent.id)"
											@update:checked="(checked) => toggleAgentSelection(agent.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ agent.name }}</strong>
											<span v-if="agent.description" class="textDescription textEllipsis">{{ agent.description }}</span>
										</div>
									</td>
									<td><span class="agentTypeBadge">{{ agent.type || '-' }}</span></td>
									<td>{{ agent.provider || '-' }}</td>
									<td>{{ agent.model || '-' }}</td>
									<td>
										<span :class="agent.active ? 'status-active' : 'status-inactive'">
											{{ agent.active ? 'Active' : 'Inactive' }}
										</span>
									</td>
									<td>
										<span v-if="agent.enableRag" class="ragBadge">
											<Brain :size="16" />
											Enabled
										</span>
										<span v-else>-</span>
									</td>
									<td>{{ agent.created ? new Date(agent.created).toLocaleDateString() : '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click @click="agentStore.setAgentItem(agent); navigationStore.setModal('editAgent')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="agentStore.setAgentItem(agent); navigationStore.setDialog('deleteAgent')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Delete
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="agentStore.agentList.length > 0"
				:current-page="pagination.page || 1"
				:total-pages="Math.ceil(agentStore.agentList.length / (pagination.limit || 20))"
				:total-items="agentStore.agentList.length"
				:current-page-size="pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import RobotOutline from 'vue-material-design-icons/RobotOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Brain from 'vue-material-design-icons/Brain.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'AgentsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		RobotOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Refresh,
		Plus,
		Brain,
		PaginationComponent,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedAgents: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		paginatedAgents() {
			const start = ((this.pagination.page || 1) - 1) * (this.pagination.limit || 20)
			const end = start + (this.pagination.limit || 20)
			return agentStore.agentList.slice(start, end)
		},
		allSelected() {
			return agentStore.agentList.length > 0 && agentStore.agentList.every(agent => this.selectedAgents.includes(agent.id))
		},
		someSelected() {
			return this.selectedAgents.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (agentStore.loading) {
				return t('openregister', 'Loading agents...')
			} else if (agentStore.error) {
				return agentStore.error
			} else if (!agentStore.agentList.length) {
				return t('openregister', 'No agents found')
			}
			return ''
		},
		emptyContentDescription() {
			if (agentStore.loading) {
				return t('openregister', 'Please wait while we fetch your agents.')
			} else if (agentStore.error) {
				return t('openregister', 'Please try again later.')
			} else if (!agentStore.agentList.length) {
				return t('openregister', 'Create your first AI agent to get started.')
			}
			return ''
		},
	},
	mounted() {
		agentStore.refreshAgentList()
	},
	methods: {
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedAgents = agentStore.agentList.map(agent => agent.id)
			} else {
				this.selectedAgents = []
			}
		},
		toggleAgentSelection(agentId, checked) {
			if (checked) {
				this.selectedAgents.push(agentId)
			} else {
				this.selectedAgents = this.selectedAgents.filter(id => id !== agentId)
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
	},
}
</script>

<style scoped>
.agentDetails {
	margin-top: 1rem;
}

.agentDescription {
	color: var(--color-text-lighter);
	margin-bottom: 1rem;
}

.agentInfo {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.agentInfoItem {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.agentInfoItem strong {
	min-width: 80px;
}

.status-active {
	color: var(--color-success);
	font-weight: 600;
}

.status-inactive {
	color: var(--color-text-lighter);
}

.agentTypeBadge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-size: 0.85em;
	font-weight: 600;
	text-transform: capitalize;
}

.ragBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	background-color: var(--color-success-light);
	color: var(--color-success-dark);
	font-size: 0.85em;
	font-weight: 600;
}
</style>
