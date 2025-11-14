<template>
	<div class="agent-selector" :class="{ 'inline-mode': inline }">
		<div v-if="!inline" class="selector-header">
			<Robot :size="24" />
			<span>{{ t('openregister', 'Select an AI Agent') }}</span>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<NcLoadingIcon :size="32" />
			<p>{{ t('openregister', 'Loading agents...') }}</p>
		</div>

		<!-- Error State -->
		<div v-else-if="error" class="error-state">
			<AlertCircle :size="48" />
			<p>{{ error }}</p>
			<NcButton @click="$emit('retry')">
				{{ t('openregister', 'Retry') }}
			</NcButton>
		</div>

		<!-- Empty State -->
		<div v-else-if="agents.length === 0" class="empty-state">
			<RobotOff :size="48" />
			<h3>{{ t('openregister', 'No agents available') }}</h3>
			<p>{{ t('openregister', 'You need an AI agent to start a conversation.') }}</p>
			<div class="empty-state-help">
				<p>
					{{ t('openregister', 'Please create an agent in the') }}
					<router-link to="/agents" class="agents-link">
						{{ t('openregister', 'Agents') }}
					</router-link>
					{{ t('openregister', 'menu or contact someone with permission to create agents.') }}
				</p>
			</div>
		</div>

		<!-- Agent Cards Grid -->
		<div v-else class="agent-grid">
			<div
				v-for="agent in agents"
				:key="agent.id"
				class="agent-card">
				<!-- Agent Header -->
				<div class="agent-header">
					<div class="agent-icon">
						<Robot :size="32" />
					</div>
					<div class="agent-title-section">
						<h3 class="agent-name">
							{{ agent.name }}
						</h3>
						<div v-if="agent.description" class="agent-description">
							{{ agent.description }}
						</div>
					</div>
					<!-- Start Button -->
					<NcButton
						type="primary"
						class="start-button"
						:disabled="startingAgentId === agent.id"
						@click="handleStartConversation(agent)">
						<template #icon>
							<NcLoadingIcon v-if="startingAgentId === agent.id" :size="20" />
							<MessagePlus v-else :size="20" />
						</template>
						{{ startingAgentId === agent.id ? t('openregister', 'Starting...') : t('openregister', 'Start Conversation') }}
					</NcButton>
				</div>

				<!-- Agent Capabilities -->
				<div v-if="hasCapabilities(agent)" class="agent-capabilities">
					<!-- Views and Tools in a grid -->
					<div class="capabilities-grid">
						<!-- Views -->
						<div v-if="agent.views && agent.views.length > 0" class="capability-section">
							<div class="capability-header">
								<CubeOutline :size="16" />
								<span class="capability-label">{{ t('openregister', 'Views') }}</span>
								<span class="capability-count">{{ agent.views.length }}</span>
							</div>
							<div class="capability-list">
								<span 
									v-for="view in getVisibleViews(agent)" 
									:key="view.uuid || view" 
									class="capability-item">
									{{ getViewName(view) }}
								</span>
								<button 
									v-if="agent.views.length > 3 && !isExpanded(agent.id, 'views')" 
									class="capability-more"
									@click.stop="toggleExpand(agent.id, 'views')">
									+{{ agent.views.length - 3 }}
								</button>
							</div>
						</div>

						<!-- Tools -->
						<div v-if="agent.tools && agent.tools.length > 0" class="capability-section">
							<div class="capability-header">
								<Tools :size="16" />
								<span class="capability-label">{{ t('openregister', 'Tools') }}</span>
								<span class="capability-count">{{ agent.tools.length }}</span>
							</div>
							<div class="capability-list">
								<span 
									v-for="tool in getVisibleTools(agent)" 
									:key="tool.uuid || tool" 
									class="capability-item">
									{{ getToolName(tool) }}
								</span>
								<button 
									v-if="agent.tools.length > 3 && !isExpanded(agent.id, 'tools')" 
									class="capability-more"
									@click.stop="toggleExpand(agent.id, 'tools')">
									+{{ agent.tools.length - 3 }}
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Agent Meta Info -->
				<div v-if="agent.model || agent.isPrivate" class="agent-meta">
					<span v-if="agent.model" class="agent-model">
						<Chip :size="12" />
						{{ agent.model }}
					</span>
					<span v-if="agent.isPrivate" class="agent-private">
						<Lock :size="12" />
						{{ t('openregister', 'Private') }}
					</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import RobotOff from 'vue-material-design-icons/RobotOff.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Chip from 'vue-material-design-icons/Chip.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import MessagePlus from 'vue-material-design-icons/MessagePlus.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Tools from 'vue-material-design-icons/Tools.vue'

export default {
	name: 'AgentSelector',

	components: {
		NcButton,
		NcLoadingIcon,
		Robot,
		RobotOff,
		AlertCircle,
		Chip,
		Lock,
		MessagePlus,
		CubeOutline,
		Tools,
	},

	props: {
		agents: {
			type: Array,
			default: () => [],
		},
		selectedAgent: {
			type: Object,
			default: null,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		error: {
			type: String,
			default: null,
		},
		inline: {
			type: Boolean,
			default: false,
		},
		starting: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['select-agent', 'confirm', 'cancel', 'retry'],

	data() {
		return {
			startingAgentId: null,
			expandedSections: {}, // Track which sections are expanded: { 'agentId-views': true, 'agentId-tools': true }
		}
	},

	methods: {
		t(app, text) {
			// Translation function placeholder
			return text
		},

		/**
		 * Check if agent has any capabilities to display
		 *
		 * @param {object} agent - The agent object to check
		 * @return {boolean} True if agent has views or tools
		 */
		hasCapabilities(agent) {
			return (agent.views && agent.views.length > 0) || (agent.tools && agent.tools.length > 0)
		},

		/**
		 * Get view name from view object or string
		 *
		 * @param {object|string} view - The view object or string identifier
		 * @return {string} The view name
		 */
		getViewName(view) {
			if (typeof view === 'string') {
				return view
			}
			return view.name || view.title || view.uuid || 'View'
		},

		/**
		 * Get tool name from tool object or string
		 *
		 * @param {object|string} tool - The tool object or string identifier
		 * @return {string} The tool name
		 */
		getToolName(tool) {
			if (typeof tool === 'string') {
				// Convert tool ID to readable name
				const toolName = tool.replace('openregister.', '').replace(/_/g, ' ')
				return toolName.charAt(0).toUpperCase() + toolName.slice(1)
			}
			return tool.name || tool.title || tool.uuid || 'Tool'
		},

		/**
		 * Handle start conversation click
		 *
		 * @param {object} agent - The agent to start conversation with
		 */
		handleStartConversation(agent) {
			this.startingAgentId = agent.id
			// Select the agent and immediately confirm
			this.$emit('select-agent', agent)
			this.$emit('confirm')
		},

		/**
		 * Toggle expand state for views or tools
		 *
		 * @param {string|number} agentId - The agent ID
		 * @param {string} section - The section to toggle ('views' or 'tools')
		 */
		toggleExpand(agentId, section) {
			const key = `${agentId}-${section}`
			this.$set(this.expandedSections, key, !this.expandedSections[key])
		},

		/**
		 * Check if a section is expanded
		 *
		 * @param {string|number} agentId - The agent ID
		 * @param {string} section - The section to check ('views' or 'tools')
		 * @return {boolean} True if section is expanded
		 */
		isExpanded(agentId, section) {
			const key = `${agentId}-${section}`
			return this.expandedSections[key] || false
		},

		/**
		 * Get visible views based on expand state
		 *
		 * @param {object} agent - The agent object
		 * @return {Array} Array of visible views
		 */
		getVisibleViews(agent) {
			if (this.isExpanded(agent.id, 'views')) {
				return agent.views
			}
			return agent.views.slice(0, 3)
		},

		/**
		 * Get visible tools based on expand state
		 *
		 * @param {object} agent - The agent object
		 * @return {Array} Array of visible tools
		 */
		getVisibleTools(agent) {
			if (this.isExpanded(agent.id, 'tools')) {
				return agent.tools
			}
			return agent.tools.slice(0, 3)
		},
	},
}
</script>

<style scoped lang="scss">
.agent-selector {
	display: flex;
	flex-direction: column;
	width: 100%;
	min-height: 400px;

	&.inline-mode {
		background: transparent;
		min-height: 300px;
	}

	.selector-header {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 16px;
		border-bottom: 1px solid var(--color-border);
		font-size: 18px;
		font-weight: 600;
	}

	.loading-state,
	.error-state,
	.empty-state {
		flex: 1;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 48px 24px;
		text-align: center;
		opacity: 0.7;

		h3 {
			margin: 16px 0 8px;
			font-size: 1.2em;
			color: var(--color-main-text);
		}

		p {
			margin: 8px 0;
			font-size: 14px;
			color: var(--color-text-maxcontrast);
		}

		small {
			margin-top: 8px;
			font-size: 12px;
			color: var(--color-text-maxcontrast);
		}
	}

	.error-state {
		opacity: 1;

		p {
			color: var(--color-error);
		}
	}

	.empty-state {
		h3 {
			color: var(--color-text-maxcontrast);
		}

		.empty-state-help {
			margin-top: 16px;
			padding: 16px;
			background: var(--color-background-hover);
			border-radius: var(--border-radius);
			border-left: 3px solid var(--color-primary-element);
			max-width: 400px;

			p {
				margin: 0;
				font-size: 0.9em;
			}

			.agents-link {
				color: var(--color-primary-element);
				font-weight: 600;
				text-decoration: none;

				&:hover {
					text-decoration: underline;
				}
			}
		}
	}

	.agent-grid {
		flex: 1;
		overflow-y: auto;
		padding: 24px;
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
		gap: 20px;
		align-content: start;
		
		// Ensure at least 2 columns on larger screens
		@media (min-width: 640px) {
			grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
		}
		
		// Prefer 2-3 columns on wide screens
		@media (min-width: 960px) {
			grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
		}
		
		// Allow up to 3 columns on very wide screens
		@media (min-width: 1280px) {
			grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
		}

		.agent-card {
			display: flex;
			flex-direction: column;
			padding: 16px;
			background: var(--color-main-background);
			border: 2px solid var(--color-border);
			border-radius: 12px;
			transition: all 0.2s ease;

			&:hover {
				border-color: var(--color-primary-element);
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
				transform: translateY(-2px);
			}

			.agent-header {
				display: flex;
				align-items: flex-start;
				gap: 12px;
				margin-bottom: 12px;

				.agent-icon {
					flex-shrink: 0;
					width: 48px;
					height: 48px;
					display: flex;
					align-items: center;
					justify-content: center;
					background: var(--color-primary-element-light);
					border-radius: 12px;
				}

				.agent-title-section {
					flex: 1;
					min-width: 0;

					.agent-name {
						font-size: 16px;
						font-weight: 600;
						margin: 0 0 4px 0;
						color: var(--color-main-text);
					}

					.agent-description {
						font-size: 12px;
						color: var(--color-text-maxcontrast);
						line-height: 1.3;
						overflow: hidden;
						text-overflow: ellipsis;
						display: -webkit-box;
						-webkit-line-clamp: 2;
						-webkit-box-orient: vertical;
					}
				}

				.start-button {
					flex-shrink: 0;
					padding: 6px 12px !important;
					min-height: 36px !important;
					white-space: nowrap;
					
					::v-deep .button-vue__text {
						font-size: 13px;
					}
				}
			}

			.agent-capabilities {
				margin-bottom: 8px;

				.capabilities-grid {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 10px;
				}

				.capability-section {
					.capability-header {
						display: flex;
						align-items: center;
						gap: 4px;
						margin-bottom: 6px;
						font-size: 11px;
						color: var(--color-text-maxcontrast);
						font-weight: 500;

						.capability-label {
							flex: 1;
						}

						.capability-count {
							background: var(--color-primary-element-light);
							color: var(--color-primary-element);
							padding: 1px 6px;
							border-radius: 8px;
							font-size: 10px;
							font-weight: 600;
						}
					}

					.capability-list {
						display: flex;
						flex-wrap: wrap;
						gap: 4px;

						.capability-item {
							padding: 3px 8px;
							background: var(--color-background-hover);
							border: 1px solid var(--color-border);
							border-radius: 4px;
							font-size: 11px;
							color: var(--color-main-text);
							line-height: 1.2;
						}

						.capability-more {
							padding: 3px 8px;
							background: var(--color-background-dark);
							border: 1px dashed var(--color-border);
							border-radius: 4px;
							font-size: 11px;
							color: var(--color-primary-element);
							font-weight: 500;
							cursor: pointer;
							transition: all 0.15s ease;

							&:hover {
								background: var(--color-primary-element-light);
								border-style: solid;
								border-color: var(--color-primary-element);
							}
						}
					}
				}
			}

			.agent-meta {
				display: flex;
				gap: 8px;
				padding-top: 8px;
				border-top: 1px solid var(--color-border);
				font-size: 11px;
				color: var(--color-text-maxcontrast);

				span {
					display: flex;
					align-items: center;
					gap: 3px;
				}

				.agent-model {
					color: var(--color-text-maxcontrast);
				}

				.agent-private {
					color: var(--color-warning);
				}
			}
		}
	}
}
</style>
