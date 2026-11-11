<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'deleteRegister'"
		name="Register verwijderen"
		:success="success"
		:error="error"
		:loading="loading"
		:showConfirm="!success && registerStore.registerItem?.schemas.length === 0"
		successText="Register succesvol verwijderd"
		:closeLabel="
			success || registerStore.registerItem?.schemas.length !== 0
				? 'Sluiten'
				: 'Annuleer'
		"
		confirmLabel="Verwijderen"
		@cancel="closeDialog"
		@confirm="deleteRegister()">
		<p v-if="!success && registerStore.registerItem?.schemas.length === 0">
			Wil je <b>{{ registerStore.registerItem?.title }}</b> definitief
			verwijderen? Deze actie kan niet ongedaan worden gemaakt.
		</p>
		<p v-if="!success && registerStore.registerItem?.schemas.length > 0">
			Het register kan niet worden verwijderd omdat het nog schema's bevat.
			Verwijder eerst alle schema's voordat u het register verwijdert. Er
			{{ registerStore.registerItem?.schemas.length > 1 ? 'zijn' : 'is' }} nog
			<b>{{ registerStore.registerItem?.schemas.length }}</b> schema{{
				registerStore.registerItem?.schemas.length > 1 ? "'s" : ''
			}}
			in het register.
		</p>
	</ConfirmDialogFrame>
</template>

<script>
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'
import {
	confirmDialogState,
	settleConfirmRequest,
} from '../shared/confirmDialogState.js'

export default {
	name: 'DeleteRegister',
	components: {
		ConfirmDialogFrame,
	},

	mixins: [confirmDialogState(false)],

	methods: {
		/**
		 * @spec exclude Modal action plumbing — delegates deletion to registerStore.deleteRegister.
		 */
		async deleteRegister() {
			if (registerStore.registerItem?.schemas.length > 0) {
				return
			}
			this.loading = true
			settleConfirmRequest(
				this,
				registerStore.deleteRegister({ ...registerStore.registerItem }),
				'Er is een fout opgetreden bij het verwijderen van het register',
			)
		},
	},
}
</script>
