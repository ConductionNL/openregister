<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'deleteSchema'"
		name="Verwijder Schema"
		:success="success"
		:error="error"
		:loading="loading"
		:showConfirm="!success"
		:confirmDisabled="!canDelete"
		:closeLabel="success ? 'Sluiten' : 'Annuleren'"
		confirmLabel="Verwijder"
		@cancel="closeDialog"
		@confirm="deleteSchema()">
		<p v-if="!success && canDelete">
			Wil je <b>{{ schemaStore.schemaItem?.title }}</b> permanent verwijderen?
			Deze actie kan niet ongedaan worden gemaakt.
		</p>
		<p v-if="!success && !canDelete">
			Er {{ objects.length > 1 ? 'zijn' : 'is' }} {{ objects.length }}
			{{ objects.length > 1 ? 'objecten' : 'object' }} in dit schema in het
			register <b>{{ registerName }}</b
			>. Je moet {{ objects.length > 1 ? 'deze' : 'dit' }} eerst verwijderen.
		</p>
	</ConfirmDialogFrame>
</template>

<script>
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'
import {
	objectUsageGuard,
	refreshSchemaObjects,
} from '../../composables/objectUsageGuard.js'
import {
	confirmDialogState,
	settleConfirmRequest,
} from '../shared/confirmDialogState.js'

/**
 * The first register that holds the schema being deleted.
 *
 * @return {object|undefined} The register
 */
function registerHoldingSchema() {
	return registerStore.registerList.find((reg) =>
		reg.schemas.includes(schemaStore.schemaItem.id),
	)
}

export default {
	name: 'DeleteSchema',
	components: {
		ConfirmDialogFrame,
	},

	mixins: [
		confirmDialogState(false),
		objectUsageGuard(() => navigationStore.dialog === 'deleteSchema'),
	],

	data() {
		return {
			objects: [],
			registerName: '',
		}
	},

	methods: {
		/**
		 * @spec exclude Modal data-load plumbing — counts objects referencing the schema before delete.
		 */
		async initDialog() {
			await registerStore.refreshRegisterList()
			if (!registerStore.registerList.length) {
				return
			}

			try {
				await this.countFromStats()
			} catch (err) {
				console.warn(
					'Could not load schema stats, falling back to individual register checks:',
					err,
				)
				await this.collectFromRegisters()
			}
		},

		/**
		 * Collect the schema's objects register by register, from every
		 * register that holds the schema.
		 *
		 * @spec exclude Modal data-load plumbing — counts objects referencing the schema before delete.
		 * @return {Promise<void>}
		 */
		async collectFromRegisters() {
			for (const reg of registerStore.registerList) {
				if (reg.schemas.includes(schemaStore.schemaItem.id)) {
					await this.collectFromRegister(reg)
				}
			}
		},

		/**
		 * Count the schema's objects through the stats endpoint and name the
		 * first register that holds the schema. The objects are placeholders,
		 * only their number is shown.
		 *
		 * @spec exclude Modal data-load plumbing — counts objects referencing the schema before delete.
		 * @return {Promise<void>}
		 */
		async countFromStats() {
			const stats = await schemaStore.getSchemaStats(schemaStore.schemaItem.id)
			const totalObjects = stats.objects?.total || 0
			const register = totalObjects > 0 && registerHoldingSchema()
			if (register) {
				this.registerName = register.title
				this.objects = Array(totalObjects).fill({
					id: '...',
					name: 'Object',
				})
			}
		},

		/**
		 * Load the schema's objects in one register and keep them when there
		 * are any.
		 *
		 * @spec exclude Modal data-load plumbing — counts objects referencing the schema before delete.
		 * @param {object} reg The register
		 * @return {Promise<void>}
		 */
		async collectFromRegister(reg) {
			await refreshSchemaObjects(reg)
			if (objectStore.getCollection(objectStore.currentType).length) {
				this.objects.push(
					...objectStore.getCollection(objectStore.currentType),
				)
				this.registerName = reg.title
			}
		},

		/**
		 * @spec exclude Modal close plumbing — closes the dialog and resets state.
		 */
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = false
			this.loading = false
			this.error = false
			this.objects = []
			this.registerName = ''
			this.isUpdated = false
		},

		/**
		 * @spec exclude Modal action plumbing — delegates deletion to schemaStore.deleteSchema.
		 */
		async deleteSchema() {
			this.loading = true

			settleConfirmRequest(
				this,
				schemaStore.deleteSchema({ ...schemaStore.schemaItem }),
				'An error occurred while deleting the schema',
			)
		},
	},
}
</script>
