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

		<!-- Agent List -->
		<div v-else class="agent-list">
			<div
				v-for="agent in agents"
				:key="agent.id"
				:class="['agent-card', { selected: selectedAgent?.id === agent.id }]"
				@click="$emit('select-agent', agent)">
				<div class="agent-icon">
					<Robot :size="32" />
				</div>
				<div class="agent-info">
					<div class="agent-name">
						{{ agent.name }}
					</div>
					<div v-if="agent.description" class="agent-description">
						{{ agent.description }}
					</div>
					<div class="agent-meta">
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
				<div v-if="selectedAgent?.id === agent.id" class="selected-indicator">
					<Check :size="20" />
				</div>
			</div>
		</div>

		<!-- Actions -->
		<div v-if="agents.length > 0" class="selector-actions">
			<NcButton
				type="primary"
				:disabled="!selectedAgent || starting"
				@click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="starting" :size="20" />
					<MessagePlus v-else :size="20" />
				</template>
				{{ starting ? t('openregister', 'Starting conversation...') : t('openregister', 'Start Conversation') }}
			</NcButton>
			<NcButton v-if="!inline" :disabled="starting" @click="$emit('cancel')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import RobotOff from 'vue-material-design-icons/RobotOff.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Chip from 'vue-material-design-icons/Chip.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import MessagePlus from 'vue-material-design-icons/MessagePlus.vue'

export default {
	name: 'AgentSelector',

	components: {
		NcButton,
		NcLoadingIcon,
		Robot,
		RobotOff,
		AlertCircle,
		Check,
		Chip,
		Lock,
		MessagePlus,
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

	methods: {
		t(app, text) {
			// Translation function placeholder
			return text
		},
	},
}
</script>

<script setup>
</script>

<style scoped lang="scss">
.agent-selector {
	display: flex;
	flex-direction: column;
	max-width: 600px;
	min-height: 400px;

	&.inline-mode {
		background: var(--color-background-hover);
		border-radius: 12px;
		border: 1px solid var(--color-border);
		min-height: 300px;

		.selector-actions {
			justify-content: center;
		}
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

	.agent-list {
		flex: 1;
		overflow-y: auto;
		padding: 16px;
		display: flex;
		flex-direction: column;
		gap: 12px;

		.agent-card {
			display: flex;
			align-items: flex-start;
			gap: 16px;
			padding: 16px;
			background: var(--color-background-hover);
			border: 2px solid transparent;
			border-radius: 12px;
			cursor: pointer;
			transition: all 0.2s ease;

			&:hover {
				background: var(--color-background-dark);
				border-color: var(--color-border);
			}

			&.selected {
				background: var(--color-primary-element-light);
				border-color: var(--color-primary-element);
			}

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

			.agent-info {
				flex: 1;
				min-width: 0;

				.agent-name {
					font-size: 16px;
					font-weight: 600;
					margin-bottom: 4px;
				}

				.agent-description {
					font-size: 13px;
					color: var(--color-text-maxcontrast);
					margin-bottom: 8px;
					overflow: hidden;
					text-overflow: ellipsis;
					display: -webkit-box;
					-webkit-line-clamp: 2;
					-webkit-box-orient: vertical;
				}

				.agent-meta {
					display: flex;
					gap: 12px;
					font-size: 12px;
					color: var(--color-text-maxcontrast);

					span {
						display: flex;
						align-items: center;
						gap: 4px;
					}

					.agent-private {
						color: var(--color-warning);
					}
				}
			}

			.selected-indicator {
				flex-shrink: 0;
				width: 32px;
				height: 32px;
				display: flex;
				align-items: center;
				justify-content: center;
				background: var(--color-primary-element);
				color: var(--color-primary-element-text);
				border-radius: 50%;
			}
		}
	}

	.selector-actions {
		display: flex;
		gap: 8px;
		padding: 16px;
		border-top: 1px solid var(--color-border);
		justify-content: flex-end;
	}
}
</style>

