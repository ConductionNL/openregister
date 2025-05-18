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
				<!-- Show if there are no configurations at all -->
				<NcEmptyContent
					v-if="configurationStore.configurationList && configurationStore.configurationList.length === 0"
					class="detailContainer"
					name="No configurations"
					description="No configurations have been defined yet."
				>
					<template #icon>
						<CogOutline />
					</template>
					<template #action>
						<NcButton type="primary" @click="configurationStore.setConfigurationItem(null); navigationStore.setModal('editConfiguration')">
							Add configuration
						</NcButton>
					</template>
				</NcEmptyContent>

				<!-- Show if a configuration is not selected, but there are configurations -->
				<NcEmptyContent
					v-else-if="!configurationStore.configurationItem || navigationStore.selected !== 'configurations'"
					class="detailContainer"
					name="No configuration selected"
					description="Select a configuration from the list or add a new one."
				>
					<template #icon>
						<CogOutline />
					</template>
					<template #action>
						<NcButton type="primary" @click="configurationStore.setConfigurationItem(null); navigationStore.setModal('editConfiguration')">
							Add configuration
						</NcButton>
					</template>
				</NcEmptyContent>

				<!-- Show details if a configuration is selected -->
				<ConfigurationDetails v-else />
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
