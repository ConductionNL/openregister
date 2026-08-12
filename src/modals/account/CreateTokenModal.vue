<template>
	<NcModal @close="$emit('close')">
		<div class="tokens-section__modal">
			<h3>{{ t('openregister', 'Create API Token') }}</h3>
			<div class="section__field">
				<label for="token-name">{{ t('openregister', 'Token name') }}</label>
				<NcTextField id="token-name"
					:model-value="tokenName"
					:label="t('openregister', 'Token name')"
					@update:modelValue="$emit('update:tokenName', $event)" />
			</div>
			<div class="section__field">
				<label for="token-expires">{{ t('openregister', 'Expires in (e.g., 90d)') }}</label>
				<NcTextField id="token-expires"
					:model-value="tokenExpires"
					:label="t('openregister', 'Expiration')"
					@update:modelValue="$emit('update:tokenExpires', $event)" />
			</div>
			<NcButton variant="primary"
				:disabled="!tokenName"
				@click="$emit('create')">
				{{ t('openregister', 'Create') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue'

export default {
	name: 'CreateTokenModal',
	components: { NcButton, NcModal, NcTextField },
	props: {
		tokenName: { type: String, default: '' },
		tokenExpires: { type: String, default: '' },
	},
	emits: ['close', 'create', 'update:tokenName', 'update:tokenExpires'],
	methods: { t },
}
</script>

<style scoped>
.section__field { margin-bottom: 12px; }

.section__field label { display: block; margin-bottom: 4px; font-weight: bold; }

.tokens-section__modal { padding: 24px; }
</style>
