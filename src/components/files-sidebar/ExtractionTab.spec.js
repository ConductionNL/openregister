/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files sidebar extraction tab: what it shows for each extraction state
 * and starting an extraction.
 *
 * @spec exclude Unit test of the client-side Files sidebar extraction tab.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ExtractionTab from './ExtractionTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name'],
		template: '<div class="empty">{{ name }}<slot name="action" /></div>',
	},
}))
jest.mock('vue-material-design-icons/FileSearchOutline.vue', () => ({
	name: 'FileSearchOutline',
	render: () => null,
}))
jest.mock('../shared/TabErrorState.vue', () => ({
	name: 'TabErrorState',
	props: ['name', 'description'],
	render: () => null,
}))
jest.mock('./ExtractNowButton.vue', () => ({
	name: 'ExtractNowButton',
	props: ['extracting'],
	emits: ['extract'],
	render: () => null,
}))

const STATUS_URL = '/index.php/apps/openregister/api/files/9/extraction-status'

/**
 * Answer the status request with the given status.
 *
 * @param {object} status The extraction status
 * @return {void}
 */
function serve(status) {
	axios.get.mockResolvedValue({ data: { success: true, data: status } })
}

/**
 * A completed extraction status, overridable.
 *
 * @param {object} extra Overrides
 * @return {object} The status
 */
function status(extra = {}) {
	return {
		fileId: 9,
		extractionStatus: 'completed',
		chunkCount: 4,
		entityCount: 3,
		riskLevel: 'high',
		extractedAt: '2026-01-02T03:04:05Z',
		entities: [
			{ type: 'PERSON', count: 2 },
			{ type: 'EMAIL', count: 1 },
		],
		anonymized: false,
		...extra,
	}
}

/**
 * Mount the tab for file 9 and let it load.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountTab() {
	const wrapper = mount(ExtractionTab, { props: { fileId: 9 } })
	await flushPromises()
	return wrapper
}

/**
 * The texts of the tab's value cells.
 *
 * @param {object} wrapper The wrapper
 * @return {Array<string>} The texts
 */
function values(wrapper) {
	return wrapper.findAll('.extraction-tab__value').map((v) => v.text())
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

describe('ExtractionTab', () => {
	it('loads the status of its file and shows it', async () => {
		serve(status())
		const wrapper = await mountTab()
		expect(axios.get).toHaveBeenCalledWith(STATUS_URL)
		expect(values(wrapper)).toEqual([
			'Completed',
			'4',
			'3  ▸',
			'High',
			new Date('2026-01-02T03:04:05Z').toLocaleString(),
			'No',
		])
		expect(wrapper.find('.extraction-tab__badge--error').text()).toBe('High')
		expect(wrapper.findComponent({ name: 'ExtractNowButton' }).exists()).toBe(false)
	})

	it('shows the spinner while loading', () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(ExtractionTab, { props: { fileId: 9 } })
		expect(wrapper.find('.extraction-tab__loading').exists()).toBe(true)
	})

	it('lists the entity types once expanded', async () => {
		serve(status())
		const wrapper = await mountTab()
		const button = wrapper.find('.extraction-tab__expand-button')
		expect(button.attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('.extraction-tab__entity-list').exists()).toBe(false)
		await button.trigger('click')
		expect(button.attributes('aria-expanded')).toBe('true')
		expect(
			wrapper.findAll('.extraction-tab__entity-item').map((i) => i.text()),
		).toEqual(['PERSON2', 'EMAIL1'])
	})

	it.each([
		['pending', 'none', 'Pending', 'None', 'neutral'],
		['processing', 'low', 'Processing', 'Low', 'success'],
		['failed', 'medium', 'Failed', 'Medium', 'warning'],
		['completed', 'very_high', 'Completed', 'Very high', 'critical'],
		['odd', 'weird', 'odd', 'weird', 'neutral'],
	])('labels status %s and risk %s', async (state, risk, label, riskText, badge) => {
		serve(status({ extractionStatus: state, riskLevel: risk }))
		const wrapper = await mountTab()
		expect(wrapper.find('.extraction-tab__badge--status').text()).toBe(label)
		const riskBadge = wrapper.find(`.extraction-tab__badge--${badge}[title]`)
		expect(riskBadge.text()).toBe(riskText)
	})

	it('shows anonymized and hides the date when there is none', async () => {
		serve(status({ anonymized: true, extractedAt: null }))
		const wrapper = await mountTab()
		expect(values(wrapper)).not.toContain('')
		expect(wrapper.text()).not.toContain('Extracted at')
		expect(values(wrapper).at(-1)).toBe('Yes')
		expect(wrapper.vm.formattedDate).toBe('')
	})

	it('offers extraction for a file that was never extracted', async () => {
		serve(status({ extractionStatus: 'none' }))
		const wrapper = await mountTab()
		expect(wrapper.find('.empty').text()).toContain(
			'No extraction data available for this file',
		)
		expect(wrapper.findComponent({ name: 'ExtractNowButton' }).exists()).toBe(true)
	})

	it('posts the extraction and reloads the status', async () => {
		serve(status({ extractionStatus: 'failed' }))
		const wrapper = await mountTab()
		axios.post.mockResolvedValue({})
		serve(status())
		const button = wrapper.findComponent({ name: 'ExtractNowButton' })
		button.vm.$emit('extract')
		await wrapper.vm.$nextTick()
		expect(button.props('extracting')).toBe(true)
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/files/9/extract',
		)
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.extraction-tab__badge--status').text()).toBe('Completed')
		expect(wrapper.vm.extracting).toBe(false)
	})

	it('logs a failed extraction and stops extracting', async () => {
		serve(status({ extractionStatus: 'failed' }))
		const wrapper = await mountTab()
		axios.post.mockRejectedValue(new Error('no'))
		await wrapper.vm.triggerExtraction()
		expect(console.error).toHaveBeenCalledWith(
			'[ExtractionTab] Extraction failed:',
			expect.any(Error),
		)
		expect(wrapper.vm.extracting).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('shows the error state when the status cannot be loaded', async () => {
		axios.get.mockRejectedValue({ message: 'm', response: { data: { error: 'Denied' } } })
		const wrapper = await mountTab()
		const error = wrapper.findComponent({ name: 'TabErrorState' })
		expect(error.props()).toEqual({
			name: 'Failed to load extraction data',
			description: 'Denied',
		})
		expect(console.error).toHaveBeenCalledWith(
			'[ExtractionTab] Failed to fetch extraction status:',
			expect.anything(),
		)
	})

	it('reloads when the file changes', async () => {
		serve(status())
		const wrapper = await mountTab()
		await wrapper.setProps({ fileId: 10 })
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(
			'/index.php/apps/openregister/api/files/10/extraction-status',
		)
	})
})
