<script setup>
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<template #list>
			<EndpointsList />
		</template>
		<template #default>
			<NcEmptyContent v-if="!endpointStore.endpointItem || navigationStore.selected != 'endpoints'"
				class="detailContainer"
				name="No endpoint"
				description="No endpoint selected yet">
				<template #icon>
					<Api />
				</template>
				<template #action>
					<NcButton type="primary" @click="endpointStore.setEndpointItem({}); navigationStore.setModal('editEndpoint')">
						Add endpoint
					</NcButton>
				</template>
			</NcEmptyContent>
			<EndpointDetails v-if="endpointStore.endpointItem && navigationStore.selected === 'endpoints'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import EndpointsList from './EndpointsList.vue'
import EndpointDetails from './EndpointDetails.vue'
import Api from 'vue-material-design-icons/Api.vue'

export default {
	name: 'EndpointsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		EndpointsList,
		EndpointDetails,
		Api,
	},
}
</script>

