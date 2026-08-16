<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder Div -->
	<ImportRegister />
	<ExportRegister />
	<PublishRegister />
	<DeleteRegister />
	<EditConfiguration v-if="navigationStore.modal === 'editConfiguration'" />
	<DeleteConfiguration />
	<ImportConfiguration />
	<ExportConfiguration />
	<PublishConfiguration />
	<ExploreSchema v-if="navigationStore.dialog === 'exploreSchema'" />
	<DeleteSchema />
	<EditSchema v-if="navigationStore.modal === 'editSchema'" />
	<ValidateSchema v-if="navigationStore.dialog === 'validateSchema'" />
	<DeleteSchemaObjects v-if="navigationStore.dialog === 'deleteSchemaObjects'" />
	<UploadSchema />
	<EditSchemaProperty v-if="navigationStore.modal === 'editSchemaProperty'" />
	<DeleteSchemaProperty />
	<EditSource />
	<DeleteSource />
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
	<MassValidateObjects v-if="navigationStore.dialog === 'massValidateObjects'" />
	<RestoreMultiple v-if="navigationStore.dialog === 'restoreMultiple'" />
	<PurgeMultiple v-if="navigationStore.dialog === 'permanentlyDeleteMultiple'" />
	<UploadFiles />
	<ViewSource />
	<ViewConfiguration />
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
import EditApplication from './application/EditApplication.vue'
import DeleteConfiguration from './configuration/DeleteConfiguration.vue'
import EditConfiguration from './configuration/EditConfiguration.vue'
import ExportConfiguration from './configuration/ExportConfiguration.vue'
import ImportConfiguration from './configuration/ImportConfiguration.vue'
import PublishConfiguration from './configuration/PublishConfiguration.vue'
import ViewConfiguration from './configuration/ViewConfiguration.vue'
import PurgeMultiple from './deleted/PurgeMultiple.vue'
import RestoreMultiple from './deleted/RestoreMultiple.vue'
import DeleteEndpoint from './endpoint/DeleteEndpoint.vue'
import EditEndpoint from './endpoint/EditEndpoint.vue'
import UploadFiles from './file/UploadFiles.vue'
import CopyObject from './object/CopyObject.vue'
import DeleteObject from './object/DeleteObject.vue'
import DownloadObject from './object/DownloadObject.vue'
import LockObject from './object/LockObject.vue'
import MassDeleteObject from './object/MassDeleteObject.vue'
import MassValidateObjects from './object/MassValidateObjects.vue'
import MergeObject from './object/MergeObject.vue'
import MigrationObject from './object/MigrationObject.vue'
import UploadObject from './object/UploadObject.vue'
import ViewObject from './object/ViewObject.vue'
import ViewObjectAuditTrail from './objectAuditTrail/ViewObjectAuditTrail.vue'
import DeleteOrganisation from './organisation/DeleteOrganisation.vue'
import EditOrganisation from './organisation/EditOrganisation.vue'
import JoinOrganisation from './organisation/JoinOrganisation.vue'
import ManageOrganisationRoles from './organisation/ManageOrganisationRoles.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import ExportRegister from './register/ExportRegister.vue'
import ImportRegister from './register/ImportRegister.vue'
import PublishRegister from './register/PublishRegister.vue'
import DeleteSchema from './schema/DeleteSchema.vue'
import DeleteSchemaObjects from './schema/DeleteSchemaObjects.vue'
import DeleteSchemaProperty from './schema/DeleteSchemaProperty.vue'
import EditSchema from './schema/EditSchema.vue'
import EditSchemaProperty from './schema/EditSchemaProperty.vue'
import ExploreSchema from './schema/ExploreSchema.vue'
import UploadSchema from './schema/UploadSchema.vue'
import ValidateSchema from './schema/ValidateSchema.vue'
import DeleteSource from './source/DeleteSource.vue'
import EditSource from './source/EditSource.vue'
import ViewSource from './source/ViewSource.vue'
import EditWebhook from './webhook/EditWebhook.vue'
import ViewWebhookLog from './webhook/ViewWebhookLog.vue'
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
		UploadObject,
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
