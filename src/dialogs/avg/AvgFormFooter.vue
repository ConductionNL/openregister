<!--
  The end of an AVG case form: the error, if any, and the Cancel and submit
  buttons. It sits inside the dialog's <form>, so the submit button submits
  that form.

  @spec exclude Presentational form footer of the AVG case dialogs.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcNoteCard v-if="error" type="error">
		{{ error }}
	</NcNoteCard>

	<div class="avgFormActions">
		<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
			{{ t('openregister', 'Cancel') }}
		</NcButton>
		<NcButton
			variant="primary"
			type="submit"
			:disabled="saving || incomplete">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
			</template>
			{{ submitLabel }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * The error and the Cancel / submit buttons of an AVG case form.
 *
 * @spec exclude Presentational form footer of the AVG case dialogs.
 */
export default {
	name: 'AvgFormFooter',
	components: { NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** The error to show above the buttons, if any. */
		error: { type: String, default: null },
		/** Whether the form is being saved: disables both buttons. */
		saving: { type: Boolean, default: false },
		/** Whether the form still lacks what a submit needs. */
		incomplete: { type: Boolean, default: false },
		/** The submit button's text, already translated. */
		submitLabel: { type: String, required: true },
	},

	emits: ['close'],

	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../../styles/dialogs.scss' as *;

.avgFormActions {
	@include end-aligned-actions;
}
</style>
