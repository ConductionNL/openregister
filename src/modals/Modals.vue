<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<UploadRegister />
		<DeleteRegister />
		<EditSchema v-if="navigationStore.modal === 'editSchema'" />
		<DeleteSchema />
		<UploadSchema />
		<EditSchemaProperty v-if="navigationStore.modal === 'editSchemaProperty'" />
		<DeleteSchemaProperty />
		<EditSource />
		<DeleteSource />
		<EditObject />
		<DeleteObject />
		<LockObject />
		<ViewObject v-if="navigationStore.modal === 'viewObject'" />
		<DownloadObject v-if="navigationStore.modal === 'downloadObject'" />
		<UploadObject v-if="navigationStore.modal === 'uploadObject'" />
		<ViewObjectAuditTrail v-if="navigationStore.modal === 'viewObjectAuditTrail'" />
	</div>
</template>

<script>
import EditRegister from './register/EditRegister.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import UploadRegister from './register/UploadRegister.vue'
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

export default {
	name: 'Modals',
	components: {
		EditRegister,
		DeleteRegister,
		UploadRegister,
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
	},
}
</script>
