<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<ImportRegister />
		<ExportRegister />
		<DeleteRegister />
		<EditConfiguration v-if="navigationStore.modal === 'editConfiguration'" />
		<DeleteConfiguration />
		<ImportConfiguration />
		<ExportConfiguration />
		<EditSchema v-if="navigationStore.modal === 'editSchema'" />
		<DeleteSchema />
		<UploadSchema />
		<EditSchemaProperty v-if="navigationStore.modal === 'editSchemaProperty'" />
		<DeleteSchemaProperty />
		<EditSource />
		<DeleteSource />
		<EditObject v-if="navigationStore.modal === 'editObject'" />
		<DeleteObject />
		<LockObject />
		<ViewObject />
		<DownloadObject v-if="navigationStore.modal === 'downloadObject'" />
		<UploadObject v-if="navigationStore.modal === 'uploadObject'" />
		<ViewObjectAuditTrail v-if="navigationStore.modal === 'viewObjectAuditTrail'" />
		<UploadFiles />
	</div>
</template>

<script>
import EditRegister from './register/EditRegister.vue'
import ImportRegister from './register/ImportRegister.vue'
import ExportRegister from './register/ExportRegister.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import EditConfiguration from './configuration/EditConfiguration.vue'
import DeleteConfiguration from './configuration/DeleteConfiguration.vue'
import ImportConfiguration from './configuration/ImportConfiguration.vue'
import ExportConfiguration from './configuration/ExportConfiguration.vue'
import EditSchema from './schema/EditSchema.vue'
import DeleteSchema from './schema/DeleteSchema.vue'
import UploadSchema from './schema/UploadSchema.vue'
import EditSchemaProperty from './schema/EditSchemaProperty.vue'
import DeleteSchemaProperty from './schema/DeleteSchemaProperty.vue'
import EditSource from './source/EditSource.vue'
import DeleteSource from './source/DeleteSource.vue'
import EditObject from './object/EditObject.vue'
import DeleteObject from './object/DeleteObject.vue'
import UploadObject from './object/UploadObject.vue'
import ViewObjectAuditTrail from './objectAuditTrail/ViewObjectAuditTrail.vue'
import LockObject from './object/LockObject.vue'
import ViewObject from './object/ViewObject.vue'
import DownloadObject from './object/DownloadObject.vue'
import UploadFiles from './file/UploadFiles.vue'
export default {
	name: 'Modals',
	components: {
		EditRegister,
		ImportRegister,
		ExportRegister,
		DeleteRegister,
		EditConfiguration,
		DeleteConfiguration,
		ImportConfiguration,
		ExportConfiguration,
		EditSchema,
		DeleteSchema,
		UploadSchema,
		EditSchemaProperty,
		DeleteSchemaProperty,
		EditSource,
		DeleteSource,
		EditObject,
		DeleteObject,
		UploadObject,
		ViewObjectAuditTrail,
		LockObject,
		ViewObject,
		DownloadObject,
		UploadFiles,
	},
}
</script>
