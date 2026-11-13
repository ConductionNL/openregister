/**
 * The publish-configuration modal: what it hands the shared GitHub publish
 * form, and how it refreshes the list afterwards.
 *
 * @spec exclude Client-side wiring of the shared publish form.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import PublishConfiguration from './PublishConfiguration.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('../github/PublishToGithub.vue', () => ({
	name: 'PublishToGithub',
	props: [
		'modalName',
		'entity',
		'entityPath',
		'sectionClass',
		'preselectPublished',
		'copy',
		'refresh',
	],
	render: () => null,
}))
jest.mock('../../store/store.js', () => ({
	configurationStore: {
		configurationItem: { id: 9, title: 'Mine' },
		refreshConfigurationList: jest.fn(),
	},
}))

const { configurationStore } = jest.requireMock('../../store/store.js')

describe('PublishConfiguration', () => {
	it('configures the publish form for the selected configuration', () => {
		const form = mount(PublishConfiguration).findComponent({ name: 'PublishToGithub' })

		expect(form.props('modalName')).toBe('publishConfiguration')
		expect(form.props('entity')).toEqual({ id: 9, title: 'Mine' })
		expect(form.props('entityPath')).toBe('configurations')
		expect(form.props('sectionClass')).toBe('formSection--config')
		expect(form.props('preselectPublished')).toBe(true)
		expect(form.props('copy')).toEqual({
			title: 't:Publish Configuration to GitHub',
			entityHeading: 't:Configuration',
			filePathPlaceholder: 't:e.g., lib/settings/config.json',
			filePathHint: 't:Path where the configuration file will be saved in the repository',
			commitPlaceholder: 't:Update configuration: ...',
			commitPrefix: 'Update configuration: ',
			publishedMessage: 'Configuration published successfully!',
			failureMessage: 'Failed to publish configuration',
		})
	})

	it('refreshes the configuration list through the store', async () => {
		configurationStore.refreshConfigurationList.mockResolvedValue('done')
		const form = mount(PublishConfiguration).findComponent({ name: 'PublishToGithub' })

		await expect(form.props('refresh')()).resolves.toBe('done')
		expect(configurationStore.refreshConfigurationList).toHaveBeenCalledTimes(1)
	})
})
