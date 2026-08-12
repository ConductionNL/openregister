<template>
	<NcModal @close="$emit('close')">
		<div class="account-section__modal">
			<h3>{{ t('openregister', 'Confirm Account Deactivation') }}</h3>
			<p>{{ t('openregister', 'This action will submit a deactivation request to your administrators.') }}</p>
			<div class="section__field">
				<label for="deactivation-reason">{{ t('openregister', 'Reason (optional)') }}</label>
				<NcTextField id="deactivation-reason"
					:model-value="reason"
					:label="t('openregister', 'Reason')"
					@update:modelValue="$emit('update:reason', $event)" />
			</div>
			<div class="section__field">
				<label for="confirm-username">
					{{ t('openregister', 'Type your username to confirm') }}: <strong>{{ username }}</strong>
				</label>
				<NcTextField id="confirm-username"
					:model-value="confirmUsername"
					:label="t('openregister', 'Username')"
					@update:modelValue="$emit('update:confirmUsername', $event)" />
			</div>
			<NcButton variant="error"
				:disabled="confirmUsername !== username"
				@click="$emit('confirm')">
				{{ t('openregister', 'Confirm deactivation') }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue'

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
