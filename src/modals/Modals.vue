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
		<MergeObject v-if="navigationStore.modal === 'mergeObject'" />
		<MigrationObject v-if="navigationStore.modal === 'migrationObject'" />
		<DeleteObject />
		<CopyObject v-if="navigationStore.dialog === 'copyObject'" />
		<LockObject />
		<ViewObject />
		<DownloadObject v-if="navigationStore.modal === 'downloadObject'" />
		<UploadObject v-if="navigationStore.modal === 'uploadObject'" />
		<ViewObjectAuditTrail v-if="navigationStore.modal === 'viewObjectAuditTrail'" />
		<MassDeleteObject v-if="navigationStore.dialog === 'massDeleteObject'" />
		<MassPublishObjects v-if="navigationStore.dialog === 'massPublishObjects'" />
		<MassDepublishObjects v-if="navigationStore.dialog === 'massDepublishObjects'" />
		<MassValidateObjects v-if="navigationStore.dialog === 'massValidateObjects'" />
		<RestoreMultiple v-if="navigationStore.dialog === 'restoreMultiple'" />
		<PurgeMultiple v-if="navigationStore.dialog === 'permanentlyDeleteMultiple'" />
		<UploadFiles />
		<ViewSource />
		<ViewConfiguration />
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
import MergeObject from './object/MergeObject.vue'
import MigrationObject from './object/MigrationObject.vue'
import DeleteObject from './object/DeleteObject.vue'
import CopyObject from './object/CopyObject.vue'
import UploadObject from './object/UploadObject.vue'
import ViewObjectAuditTrail from './objectAuditTrail/ViewObjectAuditTrail.vue'
import LockObject from './object/LockObject.vue'
import ViewObject from './object/ViewObject.vue'
import DownloadObject from './object/DownloadObject.vue'
import UploadFiles from './file/UploadFiles.vue'
import MassDeleteObject from './object/MassDeleteObject.vue'
import MassPublishObjects from './object/MassPublishObjects.vue'
import MassDepublishObjects from './object/MassDepublishObjects.vue'
import MassValidateObjects from './object/MassValidateObjects.vue'
import RestoreMultiple from './deleted/RestoreMultiple.vue'
import PurgeMultiple from './deleted/PurgeMultiple.vue'
import ViewSource from './source/ViewSource.vue'
import ViewConfiguration from './configuration/ViewConfiguration.vue'
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
		MergeObject,
		MigrationObject,
		DeleteObject,
		CopyObject,
		UploadObject,
		ViewObjectAuditTrail,
		LockObject,
		ViewObject,
		DownloadObject,
		UploadFiles,
		MassDeleteObject,
		MassPublishObjects,
		MassDepublishObjects,
		MassValidateObjects,
		RestoreMultiple,
		PurgeMultiple,
		ViewSource,
		ViewConfiguration,
	},
}
</script>
