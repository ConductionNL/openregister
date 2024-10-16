<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<DeleteRegister />
		<EditSchema />
		<DeleteSchema />
		<EditSchemaProperty />
		<DeleteSchemaProperty />
		<EditSource />
		<DeleteSource />
		<EditObject />
		<DeleteObject />
	</div>
</template>

<script>
import EditRegister from './register/EditRegister.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import EditSchema from './schema/EditSchema.vue'
import DeleteSchema from './schema/DeleteSchema.vue'
import EditSchemaProperty from './schema/EditSchemaProperty.vue'
import DeleteSchemaProperty from './schema/DeleteSchemaProperty.vue'
import EditSource from './source/EditSource.vue'
import DeleteSource from './source/DeleteSource.vue'
import EditObject from './object/EditObject.vue'
import DeleteObject from './object/DeleteObject.vue'

export default {
	name: 'Modals',
	components: {
		EditRegister,
		DeleteRegister,
		EditSchema,
		DeleteSchema,
		EditSchemaProperty,
		DeleteSchemaProperty,
		EditSource,
		DeleteSource,
		EditObject,
		DeleteObject,
	},
}
</script>
