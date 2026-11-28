<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder Div -->
	<!-- Mounted only while open: on mount it loads the dashboard, register
		and schema lists (and the object list on object pages), which every page
		paid for with the dialog closed. -->
	<ImportRegister v-if="navigationStore.modal === 'importRegister'" />
	<ExportRegister v-if="navigationStore.modal === 'exportRegister'" />
	<PublishRegister v-if="navigationStore.modal === 'publishRegister'" />
	<DeleteRegister v-if="navigationStore.dialog === 'deleteRegister'" />
	<EditConfiguration v-if="navigationStore.modal === 'editConfiguration'" />
	<DeleteConfiguration v-if="navigationStore.dialog === 'deleteConfiguration'" />
	<!-- Mounted only while open: on mount it reads the API token settings,
		which every OpenRegister page paid for with the dialog closed. -->
	<ImportConfiguration v-if="navigationStore.modal === 'importConfiguration'" />
	<ExportConfiguration v-if="navigationStore.modal === 'exportConfiguration'" />
	<PublishConfiguration v-if="navigationStore.modal === 'publishConfiguration'" />
	<PublishToGitlab v-if="navigationStore.modal === 'publishConfigurationGitLab'" />
	<ExploreSchema v-if="navigationStore.dialog === 'exploreSchema'" />
	<DeleteSchema v-if="navigationStore.dialog === 'deleteSchema'" />
	<EditSchema v-if="navigationStore.modal === 'editSchema'" />
	<ValidateSchema v-if="navigationStore.dialog === 'validateSchema'" />
	<DeleteSchemaObjects v-if="navigationStore.dialog === 'deleteSchemaObjects'" />
	<!-- Mounted only while open: its dialog is gated on the same key, and it
		carries the code editor (vue-codemirror6), which no page needs at load. -->
	<UploadSchema v-if="navigationStore.modal === 'uploadSchema'" />
	<EditSchemaProperty v-if="navigationStore.modal === 'editSchemaProperty'" />
	<DeleteSchemaProperty v-if="navigationStore.modal === 'deleteSchemaProperty'" />
	<EditSource v-if="navigationStore.modal === 'editSource'" />
	<DeleteSource v-if="navigationStore.dialog === 'deleteSource'" />
	<MergeObject v-if="navigationStore.modal === 'mergeObject'" />
	<MigrationObject v-if="navigationStore.modal === 'migrationObject'" />
	<DeleteObject v-if="navigationStore.dialog === 'deleteObject'" />
	<CopyObject v-if="navigationStore.dialog === 'copyObject'" />
	<SaveRecordTemplate v-if="navigationStore.dialog === 'saveRecordTemplate'" />
	<LockObject v-if="navigationStore.modal === 'lockObject'" />
	<!-- Mounted only while open: it refetches the active schema on mount and
		deep-watches the object store, re-initialising its form on every change. -->
	<ViewObject v-if="navigationStore.modal === 'viewObject'" />
	<DownloadObject v-if="navigationStore.modal === 'downloadObject'" />
	<UploadObject v-if="navigationStore.modal === 'uploadObject'" />
	<RecordImportDialog v-if="navigationStore.dialog === 'recordImport'" />
	<ViewObjectAuditTrail v-if="navigationStore.modal === 'viewObjectAuditTrail'" />
	<MassDeleteObject v-if="navigationStore.dialog === 'massDeleteObject'" />
	<MassValidateObjects v-if="navigationStore.dialog === 'massValidateObjects'" />
	<RestoreMultiple v-if="navigationStore.dialog === 'restoreMultiple'" />
	<PurgeMultiple v-if="navigationStore.dialog === 'permanentlyDeleteMultiple'" />
	<UploadFiles v-if="navigationStore.modal === 'uploadFiles'" />
	<ViewSource v-if="navigationStore.modal === 'viewSource'" />
	<ViewConfiguration v-if="navigationStore.modal === 'viewConfiguration'" />
	<JoinOrganisation v-if="navigationStore.modal === 'joinOrganisation'" />
	<!-- EditOrganisation: modal={{ navigationStore.modal }} -->
	<EditOrganisation v-if="navigationStore.modal === 'editOrganisation'" />
	<DeleteOrganisation v-if="navigationStore.modal === 'deleteOrganisation'" />
	<ManageOrganisationRoles
		v-if="navigationStore.modal === 'manageOrganisationRoles'" />
	<EditApplication v-if="navigationStore.modal === 'editApplication'" />
	<EditWebhook v-if="navigationStore.modal === 'editWebhook'" />
	<ViewWebhookLog v-if="navigationStore.modal === 'viewWebhookLog'" />
	<EditEndpoint v-if="navigationStore.modal === 'editEndpoint'" />
	<DeleteEndpoint v-if="navigationStore.dialog === 'deleteEndpoint'" />
</template>

<script>
import { defineAsyncComponent } from 'vue'
// Every modal opens on demand: each is mounted only while its navigation key
// is set, so each loads as its own chunk when first opened. Imported
// statically they put their whole dependency trees (the object viewer, the
// code editor of the download dialog, the schema editors) and their styles
// into the chunks every OpenRegister page loads, and mounted with the dialog
// closed every page built them and inserted their styles at boot.
const ExportRegister = defineAsyncComponent(() => import('./register/ExportRegister.vue'))
const PublishRegister = defineAsyncComponent(() => import('./register/PublishRegister.vue'))
const DeleteRegister = defineAsyncComponent(() => import('./register/DeleteRegister.vue'))
const DeleteConfiguration = defineAsyncComponent(() => import('./configuration/DeleteConfiguration.vue'))
const ExportConfiguration = defineAsyncComponent(() => import('./configuration/ExportConfiguration.vue'))
const PublishConfiguration = defineAsyncComponent(() => import('./configuration/PublishConfiguration.vue'))
const PublishToGitlab = defineAsyncComponent(() => import('./gitlab/PublishToGitlab.vue'))
const DeleteSchema = defineAsyncComponent(() => import('./schema/DeleteSchema.vue'))
const DeleteSchemaProperty = defineAsyncComponent(() => import('./schema/DeleteSchemaProperty.vue'))
const EditSource = defineAsyncComponent(() => import('./source/EditSource.vue'))
const DeleteSource = defineAsyncComponent(() => import('./source/DeleteSource.vue'))
const DeleteObject = defineAsyncComponent(() => import('./object/DeleteObject.vue'))
const LockObject = defineAsyncComponent(() => import('./object/LockObject.vue'))
const UploadFiles = defineAsyncComponent(() => import('./file/UploadFiles.vue'))
const ViewConfiguration = defineAsyncComponent(() => import('./configuration/ViewConfiguration.vue'))
const RecordImportDialog = defineAsyncComponent(() => import('../dialogs/recordImport/RecordImportDialog.vue'))
const EditApplication = defineAsyncComponent(() => import('./application/EditApplication.vue'))
const EditConfiguration = defineAsyncComponent(() => import('./configuration/EditConfiguration.vue'))
const ImportConfiguration = defineAsyncComponent(() => import('./configuration/ImportConfiguration.vue'))
const PurgeMultiple = defineAsyncComponent(() => import('./deleted/PurgeMultiple.vue'))
const RestoreMultiple = defineAsyncComponent(() => import('./deleted/RestoreMultiple.vue'))
const DeleteEndpoint = defineAsyncComponent(() => import('./endpoint/DeleteEndpoint.vue'))
const EditEndpoint = defineAsyncComponent(() => import('./endpoint/EditEndpoint.vue'))
const CopyObject = defineAsyncComponent(() => import('./object/CopyObject.vue'))
const DownloadObject = defineAsyncComponent(() => import('./object/DownloadObject.vue'))
const MassDeleteObject = defineAsyncComponent(() => import('./object/MassDeleteObject.vue'))
const MassValidateObjects = defineAsyncComponent(() => import('./object/MassValidateObjects.vue'))
const MergeObject = defineAsyncComponent(() => import('./object/MergeObject.vue'))
const MigrationObject = defineAsyncComponent(() => import('./object/MigrationObject.vue'))
const SaveRecordTemplate = defineAsyncComponent(() => import('./object/SaveRecordTemplate.vue'))
const UploadObject = defineAsyncComponent(() => import('./object/UploadObject.vue'))
const ViewObject = defineAsyncComponent(() => import('./object/ViewObject.vue'))
const ViewObjectAuditTrail = defineAsyncComponent(() => import('./objectAuditTrail/ViewObjectAuditTrail.vue'))
const DeleteOrganisation = defineAsyncComponent(() => import('./organisation/DeleteOrganisation.vue'))
const EditOrganisation = defineAsyncComponent(() => import('./organisation/EditOrganisation.vue'))
const JoinOrganisation = defineAsyncComponent(() => import('./organisation/JoinOrganisation.vue'))
const ManageOrganisationRoles = defineAsyncComponent(() => import('./organisation/ManageOrganisationRoles.vue'))
const ImportRegister = defineAsyncComponent(() => import('./register/ImportRegister.vue'))
const DeleteSchemaObjects = defineAsyncComponent(() => import('./schema/DeleteSchemaObjects.vue'))
const EditSchema = defineAsyncComponent(() => import('./schema/EditSchema.vue'))
const EditSchemaProperty = defineAsyncComponent(() => import('./schema/EditSchemaProperty.vue'))
const ExploreSchema = defineAsyncComponent(() => import('./schema/ExploreSchema.vue'))
const UploadSchema = defineAsyncComponent(() => import('./schema/UploadSchema.vue'))
const ValidateSchema = defineAsyncComponent(() => import('./schema/ValidateSchema.vue'))
const ViewSource = defineAsyncComponent(() => import('./source/ViewSource.vue'))
const EditWebhook = defineAsyncComponent(() => import('./webhook/EditWebhook.vue'))
const ViewWebhookLog = defineAsyncComponent(() => import('./webhook/ViewWebhookLog.vue'))

export default {
	name: 'Modals',
	components: {
		ImportRegister,
		ExportRegister,
		PublishRegister,
		DeleteRegister,
		EditConfiguration,
		DeleteConfiguration,
		ImportConfiguration,
		ExportConfiguration,
		PublishConfiguration,
		PublishToGitlab,
		EditSchema,
		ExploreSchema,
		DeleteSchema,
		ValidateSchema,
		DeleteSchemaObjects,
		UploadSchema,
		EditSchemaProperty,
		DeleteSchemaProperty,
		EditSource,
		DeleteSource,
		MergeObject,
		MigrationObject,
		DeleteObject,
		CopyObject,
		SaveRecordTemplate,
		UploadObject,
		RecordImportDialog,
		ViewObjectAuditTrail,
		LockObject,
		ViewObject,
		DownloadObject,
		UploadFiles,
		MassDeleteObject,
		MassValidateObjects,
		RestoreMultiple,
		PurgeMultiple,
		ViewSource,
		ViewConfiguration,
		JoinOrganisation,
		EditOrganisation,
		DeleteOrganisation,
		ManageOrganisationRoles,
		EditApplication,
		EditWebhook,
		ViewWebhookLog,
		EditEndpoint,
		DeleteEndpoint,
	},
}
</script>
