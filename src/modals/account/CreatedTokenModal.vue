<template>
	<NcModal @close="$emit('close')">
		<div class="tokens-section__modal">
			<h3>{{ t('openregister', 'Token Created') }}</h3>
			<p class="tokens-section__warning">
				{{
					t(
						'openregister',
						'This token will only be shown once. Copy it now.',
					)
				}}
			</p>
			<div class="tokens-section__token-display">
				<code>{{ token }}</code>
				<NcButton @click="$emit('copy')">
					{{ t('openregister', 'Copy to clipboard') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcModal } from '@nextcloud/vue'

export default {
	name: 'CreatedTokenModal',
	components: { NcButton, NcModal },
	props: {
		token: { type: String, required: true },
	},
	emits: ['close', 'copy'],
	methods: { t },
}
</script>

<style scoped>
.tokens-section__modal {
	padding: 24px;
}

.tokens-section__warning {
	color: var(--color-warning);
	font-weight: bold;
	margin-bottom: 12px;
}

.tokens-section__token-display {
	display: flex;
	gap: 8px;
	align-items: center;
}

.tokens-section__token-display code {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: 4px;
	word-break: break-all;
	flex: 1;
}
</style>
