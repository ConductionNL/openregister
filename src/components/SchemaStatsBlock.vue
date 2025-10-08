<template>
	<div class="schema-stats-block">
		<div class="object-count-section">
			<h4>{{ title || t('openregister', 'Objects') }}</h4>
			<div v-if="objectCount > 0" class="object-count-centered">
				<span class="count-value">{{ objectCount }}</span>
				<span class="count-label">{{ t('openregister', 'objects') }}</span>
			</div>
			<div v-else-if="loading" class="loading-count">
				<NcLoadingIcon :size="16" />
				{{ t('openregister', 'Counting objects...') }}
			</div>
			<div v-else class="no-objects">
				{{ t('openregister', 'No objects found') }}
			</div>
			
			<!-- Show detailed breakdown if available -->
			<div v-if="objectStats && objectCount > 0" class="object-breakdown">
				<div class="breakdown-item">
					<span class="breakdown-label">{{ t('openregister', 'Total:') }}</span>
					<span class="breakdown-value">{{ objectStats.total }}</span>
				</div>
				<div class="breakdown-item">
					<span class="breakdown-label">{{ t('openregister', 'Invalid:') }}</span>
					<span class="breakdown-value invalid">{{ objectStats.invalid }}</span>
				</div>
				<div class="breakdown-item">
					<span class="breakdown-label">{{ t('openregister', 'Deleted:') }}</span>
					<span class="breakdown-value deleted">{{ objectStats.deleted }}</span>
				</div>
				<div class="breakdown-item">
					<span class="breakdown-label">{{ t('openregister', 'Published:') }}</span>
					<span class="breakdown-value published">{{ objectStats.published }}</span>
				</div>
				<div v-if="objectStats.locked !== undefined" class="breakdown-item">
					<span class="breakdown-label">{{ t('openregister', 'Locked:') }}</span>
					<span class="breakdown-value locked">{{ objectStats.locked }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'SchemaStatsBlock',
	components: {
		NcLoadingIcon,
	},
	props: {
		objectCount: {
			type: Number,
			default: 0,
		},
		objectStats: {
			type: Object,
			default: null,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		title: {
			type: String,
			default: '',
		},
	},
	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.schema-stats-block {
	.object-count-section {
		background: var(--color-background-hover);
		border-radius: var(--border-radius);
		padding: 1rem;
		margin-bottom: 1rem;
	}

	.object-count-section h4 {
		margin-top: 0;
		margin-bottom: 1rem;
		color: var(--color-text);
	}

	.object-count-centered {
		display: flex;
		align-items: baseline;
		justify-content: center;
		gap: 0.5rem;
		font-size: 1.2rem;
		margin-bottom: 1rem;
	}

	.count-value {
		font-size: 2rem;
		font-weight: bold;
		color: var(--color-primary-element);
	}

	.count-label {
		color: var(--color-text-lighter);
	}

	.loading-count {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 0.5rem;
		color: var(--color-text-lighter);
		margin-bottom: 1rem;
	}

	.no-objects {
		text-align: center;
		color: var(--color-text-lighter);
		font-style: italic;
		margin-bottom: 1rem;
	}

	.object-breakdown {
		margin-top: 1rem;
		padding: 1rem;
		background: var(--color-background-hover);
		border-radius: var(--border-radius);
		border: 1px solid var(--color-border);
	}

	.breakdown-item {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 0.5rem;
	}

	.breakdown-item:last-child {
		margin-bottom: 0;
	}

	.breakdown-label {
		font-weight: 500;
		color: var(--color-text);
	}

	.breakdown-value {
		font-weight: 600;
		padding: 0.25rem 0.5rem;
		border-radius: var(--border-radius);
		background: var(--color-background-hover);
	}

	.breakdown-value.invalid {
		color: var(--color-warning);
		background: var(--color-warning-light);
	}

	.breakdown-value.deleted {
		color: var(--color-error);
		background: var(--color-error-light);
	}

	.breakdown-value.published {
		color: var(--color-success);
		background: var(--color-success-light);
	}

	.breakdown-value.locked {
		color: var(--color-text-lighter);
		background: var(--color-background-hover);
	}
}
</style>
