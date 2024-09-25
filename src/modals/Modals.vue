<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<DeleteRegister />
	</div>
</template>

<script>
import EditRegister from './register/EditRegister.vue'
import DeleteRegister from './register/DeleteRegister.vue'

export default {
	name: 'Modals',
	components: {
		EditRegister,
		DeleteRegister,
	},
}
</script>
