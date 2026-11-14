<template>
	<!-- Placeholder div -->
	<div>
		<DeleteAuditTrail />
		<AuditTrailDetails />
		<AuditTrailChanges />
		<ClearAuditTrails />
		<DeleteApplication v-if="navigationStore.dialog === 'deleteApplication'" />
		<BulkTranslateDialog
			v-if="navigationStore.dialog === 'bulkTranslate' && bulkTranslate"
			:open="true"
			:uuid="bulkTranslate.uuid"
			:languages="bulkTranslate.languages"
			@translated="onTranslated"
			@close="closeBulkTranslate" />
	</div>
</template>

<script setup>
import { computed } from 'vue'
import DeleteApplication from '../modals/application/DeleteApplication.vue'
import AuditTrailChanges from '../modals/logs/AuditTrailChanges.vue'
import AuditTrailDetails from '../modals/logs/AuditTrailDetails.vue'
import ClearAuditTrails from '../modals/logs/ClearAuditTrails.vue'
import DeleteAuditTrail from '../modals/logs/DeleteAuditTrail.vue'
import BulkTranslateDialog from './i18n/BulkTranslateDialog.vue'
import { navigationStore } from '../store/store.js'

defineOptions({ name: 'Dialogs' })

/**
 * What the object page handed the Translate dialog: the object's uuid, the
 * register's languages and the callback that saves the answer.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
const bulkTranslate = computed(() => {
	const data = navigationStore.transferData
	return data && typeof data.uuid === 'string' && Array.isArray(data.languages) ? data : null
})

/**
 * Hand the answer to the object page, which saves it on the object.
 *
 * @param {object} result The bulk-translate answer.
 * @return {Promise<void>}
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
async function onTranslated(result) {
	await bulkTranslate.value?.onTranslated?.(result)
}

/**
 * Close the Translate dialog and drop what was handed to it.
 *
 * @return {void}
 * @spec exclude dialog close handler, UI plumbing
 */
function closeBulkTranslate() {
	navigationStore.setDialog(false)
	navigationStore.clearTransferData()
}
</script>
