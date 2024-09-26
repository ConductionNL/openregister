<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<DeleteRegister />
		<EditSchema />
		<DeleteSchema />
		<EditSource />
		<DeleteSource />
	</div>
</template>

<script>
import EditRegister from './register/EditRegister.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import EditSchema from './schema/EditSchema.vue'
import DeleteSchema from './schema/DeleteSchema.vue'
import EditSource from './source/EditSource.vue'
import DeleteSource from './source/DeleteSource.vue'

export default {
	name: 'Modals',
	components: {
		EditRegister,
		DeleteRegister,
		EditSchema,
		DeleteSchema,
		EditSource,
		DeleteSource,
	},
}
</script>
