<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<DeleteRegister />
		<EditSchema />
		<DeleteSchema />
	</div>
</template>

<script>
import EditRegister from './register/EditRegister.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import EditSchema from './schema/EditSchema.vue'
import DeleteSchema from './schema/DeleteSchema.vue'

export default {
	name: 'Modals',
	components: {
		EditRegister,
		DeleteRegister,
		EditSchema,
		DeleteSchema,
	},
}
</script>
