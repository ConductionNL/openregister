<template>
	<PublishToGithub
		modalName="publishConfiguration"
		:entity="configuration"
		entityPath="configurations"
		sectionClass="formSection--config"
		:preselectPublished="true"
		:copy="copy"
		:refresh="refresh" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import PublishToGithub from '../github/PublishToGithub.vue'
import { configurationStore } from '../../store/store.js'

export default {
	name: 'PublishConfiguration',
	components: {
		PublishToGithub,
	},

	computed: {
		/**
		 * @spec exclude UI accessor — exposes the store's configurationItem to the template.
		 */
		configuration() {
			return configurationStore.configurationItem
		},

		/**
		 * @spec exclude UI display helper — the configuration texts of the publish form.
		 */
		copy() {
			return {
				title: t('openregister', 'Publish Configuration to GitHub'),
				entityHeading: t('openregister', 'Configuration'),

				filePathPlaceholder: t(
					'openregister',
					'e.g., lib/settings/config.json',
				),

				filePathHint: t(
					'openregister',
					'Path where the configuration file will be saved in the repository',
				),

				commitPlaceholder: t('openregister', 'Update configuration: ...'),
				commitPrefix: 'Update configuration: ',
				publishedMessage: 'Configuration published successfully!',
				failureMessage: 'Failed to publish configuration',
			}
		},
	},

	methods: {
		/**
		 * @spec exclude Modal action plumbing — refreshes the configuration list after a publish.
		 */
		refresh() {
			return configurationStore.refreshConfigurationList()
		},
	},
}
</script>
