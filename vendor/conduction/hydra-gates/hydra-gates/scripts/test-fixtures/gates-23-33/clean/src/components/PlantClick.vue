<template>
	<NcAppContent>
		<template #list>
			<div class="list">safe</div>
		</template>
		<!-- Historic note: this used to be a <div @click="x"> with no role. -->
		<button type="button" class="row-toggle" @click="toggle">
			Toggle me
		</button>
	</NcAppContent>
</template>

<script>
export default { name: 'PlantClick', methods: { toggle() {} } }
</script>
