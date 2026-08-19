<template>
	<div>
		<img src="/apps/gateplant/img/logo.png" width="32" height="32">
	</div>
</template>

<script>
export default {
	name: 'PlantImg',
	/**
	 * Handle the `<img>` `error` event. PROSE, not markup — a gate that
	 * flattens the file scores this as a finding (#220/#235).
	 *
	 * @param {Event} e - The `<img>` error event
	 */
	methods: { onError(e) {} },
}
</script>
