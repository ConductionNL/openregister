<template>
	<!-- Placeholder Div -->
	<div>
		<EditRegister />
		<DeleteRegister />
		<EditSource />
		<DeleteSource />
	</div>
</template>

<script>
import EditRegister from './register/EditRegister.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import EditSource from './source/EditSource.vue'
import DeleteSource from './source/DeleteSource.vue'

export default {
	name: 'Modals',
	components: {
		EditRegister,
		DeleteRegister,
		EditSource,
		DeleteSource,
	},
}
</script>
