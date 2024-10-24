<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<UploadRegister />
		<DeleteRegister />
		<EditSchema />
		<DeleteSchema />
		<UploadSchema />
		<EditSchemaProperty />
		<DeleteSchemaProperty />
		<EditSource />
		<DeleteSource />
		<EditObject />
		<DeleteObject />
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
import ViewObjectAuditTrail from './objectAuditTrail/ViewObjectAuditTrail.vue'

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
		ViewObjectAuditTrail,
	},
}
</script>
