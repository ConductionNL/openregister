<template>
	<PublishToGithub
		modalName="publishRegister"
		:entity="register"
		entityPath="registers"
		sectionClass="formSection--register"
		:defaultFilePath="defaultFilePath"
		:copy="copy"
		:refresh="refresh" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import PublishToGithub from '../github/PublishToGithub.vue'
import { registerStore } from '../../store/store.js'

export default {
	name: 'PublishRegister',
	components: {
		PublishToGithub,
	},

	computed: {
		/**
		 * @spec exclude UI accessor — exposes the store's registerItem to the template.
		 */
		register() {
			return registerStore.registerItem
		},

		/**
		 * @spec exclude UI display helper — the default file name from the register slug.
		 */
		defaultFilePath() {
			return this.register?.slug
				? `${this.register.slug}_openregister.json`
				: ''
		},

		/**
		 * @spec exclude UI display helper — the register texts of the publish form.
		 */
		copy() {
			return {
				title: t('openregister', 'Publish Register OAS to GitHub'),
				entityHeading: t('openregister', 'Register'),

				filePathPlaceholder: t(
					'openregister',
					'e.g., lib/settings/register.json',
				),

				filePathHint: t(
					'openregister',
					'Path where the register OAS file will be saved in the repository',
				),

				commitPlaceholder: t('openregister', 'Update register OAS: ...'),
				commitPrefix: 'Update register OAS: ',
				publishedMessage: 'Register OAS published successfully!',
				failureMessage: 'Failed to publish register OAS',
			}
		},
	},

	methods: {
		/**
		 * @spec exclude Modal action plumbing — refreshes the register list after a publish.
		 */
		refresh() {
			return registerStore.refreshRegisterList()
		},
	},
}
</script>
