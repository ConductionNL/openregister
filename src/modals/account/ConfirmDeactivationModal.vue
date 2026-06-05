<template>
	<NcModal @close="$emit('close')">
		<div class="account-section__modal">
			<h3>{{ t('openregister', 'Confirm Account Deactivation') }}</h3>
			<p>{{ t('openregister', 'This action will submit a deactivation request to your administrators.') }}</p>
			<div class="section__field">
				<label for="deactivation-reason">{{ t('openregister', 'Reason (optional)') }}</label>
				<NcTextField id="deactivation-reason"
					:value="reason"
					:label="t('openregister', 'Reason')"
					@input="$emit('update:reason', $event.target.value)" />
			</div>
			<div class="section__field">
				<label for="confirm-username">
					{{ t('openregister', 'Type your username to confirm') }}: <strong>{{ username }}</strong>
				</label>
				<NcTextField id="confirm-username"
					:value="confirmUsername"
					:label="t('openregister', 'Username')"
					@input="$emit('update:confirmUsername', $event.target.value)" />
			</div>
			<NcButton type="error"
				:disabled="confirmUsername !== username"
				@click="$emit('confirm')">
				{{ t('openregister', 'Confirm deactivation') }}
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
	name: 'ConfirmDeactivationModal',
	components: { NcButton, NcModal, NcTextField },
	props: {
		username: { type: String, required: true },
		reason: { type: String, default: '' },
		confirmUsername: { type: String, default: '' },
	},
	emits: ['close', 'confirm', 'update:reason', 'update:confirmUsername'],
	methods: { t },
}
</script>

<style scoped>
.section__field { margin-bottom: 12px; }
.section__field label { display: block; margin-bottom: 4px; font-weight: bold; }
.account-section__modal { padding: 24px; }
</style>
