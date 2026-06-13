<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'Remove connection')"
		:can-close="!removing"
		class="or-remove-connection-dialog"
		@closing="$emit('cancel')">
		<p>
			{{ t('openregister', 'Remove the connection between this email and {name}?', { name }) }}
		</p>

		<template #actions>
			<NcButton
				:disabled="removing"
				@click="$emit('cancel')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="removing"
				type="error"
				@click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="removing" :size="20" />
					<LinkVariantOff v-else :size="20" />
				</template>
				{{ t('openregister', 'Remove connection') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * Confirmation dialog for removing a mail ↔ object connection. Lives in its
 * own file (ADR-004 modal-isolation) and is driven entirely by the parent
 * via `show`/`removing` props and `confirm`/`cancel` events.
 *
 * @spec openspec/changes/integration-email/tasks.md
 */
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'

export default {
	name: 'RemoveConnectionDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		Cancel,
		LinkVariantOff,
	},
	props: {
		show: {
			type: Boolean,
			required: true,
		},
		name: {
			type: String,
			default: '',
		},
		removing: {
			type: Boolean,
			default: false,
		},
	},
	methods: {
		t,
	},
}
</script>
