<template>
	<NcModal @close="$emit('close')">
		<div class="tokens-section__modal">
			<h3>{{ t('openregister', 'Create API Token') }}</h3>
			<div class="section__field">
				<label for="token-name">{{ t('openregister', 'Token name') }}</label>
				<NcTextField id="token-name"
					:value="tokenName"
					:label="t('openregister', 'Token name')"
					@input="$emit('update:tokenName', $event.target.value)" />
			</div>
			<div class="section__field">
				<label for="token-expires">{{ t('openregister', 'Expires in (e.g., 90d)') }}</label>
				<NcTextField id="token-expires"
					:value="tokenExpires"
					:label="t('openregister', 'Expiration')"
					@input="$emit('update:tokenExpires', $event.target.value)" />
			</div>
			<NcButton type="primary"
				:disabled="!tokenName"
				@click="$emit('create')">
				{{ t('openregister', 'Create') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

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
