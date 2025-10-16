<template>
	<!-- Placeholder div for all of the views-->
	<NcAppContent>
		<template #default>
			<router-view />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'

export default {
	name: 'Views',
	components: {
		NcAppContent,
	},
}
</script>
