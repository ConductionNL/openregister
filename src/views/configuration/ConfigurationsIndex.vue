<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div>
		<NcAppContent>
			<template #list>
				<ConfigurationsList />
			</template>
			<template #default>
				<NcEmptyContent v-if="!configurationStore.configurationItem || navigationStore.selected !== 'configurations'"
					class="detailContainer"
					name="No configuration"
					description="No configuration selected yet">
					<template #icon>
						<CogOutline />
					</template>
					<template #action>
						<NcButton type="primary" @click="configurationStore.setConfigurationItem(null); navigationStore.setModal('editConfiguration')">
							Add configuration
						</NcButton>
					</template>
				</NcEmptyContent>
				<ConfigurationDetails v-if="configurationStore.configurationItem && navigationStore.selected === 'configurations'" />
			</template>
		</NcAppContent>

		<!-- Modals -->
		<EditConfiguration />
		<DeleteConfiguration />
		<ImportConfiguration />
		<ExportConfiguration />
	</div>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton } from '@nextcloud/vue'
import ConfigurationsList from './ConfigurationsList.vue'
import ConfigurationDetails from './ConfigurationDetails.vue'
import EditConfiguration from '../../modals/configuration/EditConfiguration.vue'
import DeleteConfiguration from '../../modals/configuration/DeleteConfiguration.vue'
import ImportConfiguration from '../../modals/configuration/ImportConfiguration.vue'
import ExportConfiguration from '../../modals/configuration/ExportConfiguration.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'

export default {
	name: 'ConfigurationsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		ConfigurationsList,
		ConfigurationDetails,
		EditConfiguration,
		DeleteConfiguration,
		ImportConfiguration,
		ExportConfiguration,
		CogOutline,
	},
}
</script> 