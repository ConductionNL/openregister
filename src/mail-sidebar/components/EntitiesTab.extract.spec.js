/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail sidebar's entities tab: what it extracts from a message envelope
 * (participants, addresses, IBANs, links, phone numbers, deduplicated), how it
 * reads an HTML body, the body response with and without its data wrapper, a
 * failed load, clearing on a closed message, and the group labels and
 * confidence it renders.
 *
 * @spec exclude Unit coverage of client-side mail sidebar entity extraction.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import EntitiesTab from './EntitiesTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => 't:' + text,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))

/**
 * Mount the tab with no open message, so nothing loads.
 *
 * @return {object} The wrapper
 */
function idleTab() {
	return mount(EntitiesTab, { props: { accountId: 1 } })
}

/**
 * The [type, value] pairs extracted from an envelope.
 *
 * @param {object} envelope The message envelope
 * @return {Array<Array<string>>} The pairs, in extraction order
 */
function extract(envelope) {
	return idleTab().vm.extractEntities(envelope).map((e) => [e.type, e.value])
}

let errorSpy

beforeEach(() => {
	jest.clearAllMocks()
	errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	errorSpy.mockRestore()
})

describe('EntitiesTab extraction', () => {
	it('takes addresses and display names from from, to and cc', () => {
		expect(
			extract({
				from: [{ email: 'alice@example.com', label: 'Alice' }],
				to: [{ email: 'bob@example.com', label: 'bob@example.com' }],
				cc: [{ email: 'carol@example.com' }, null, { label: 'Dave' }],
			}),
		).toEqual([
			['EMAIL', 'alice@example.com'],
			['PERSON', 'Alice'],
			// A label equal to the address is not a person.
			['EMAIL', 'bob@example.com'],
			['EMAIL', 'carol@example.com'],
			['PERSON', 'Dave'],
		])
	})

	it('finds addresses, links and phone numbers in the body', () => {
		expect(
			extract({
				body: 'Mail info@gemeente.nl, see https://pay.example.nl/x?id=1 or call 020-1234567.',
			}),
		).toEqual([
			['EMAIL', 'info@gemeente.nl'],
			['URL', 'https://pay.example.nl/x?id=1'],
			['PHONE', '020-1234567'],
		])
	})

	it('finds a grouped IBAN in the body and stores it without spaces', () => {
		const ibans = extract({ body: 'Pay to BE68 5390 0754 7034 please' })
			.filter(([type]) => type === 'IBAN')
		expect(ibans).toEqual([['IBAN', 'BE68539007547034']])
	})

	it('ignores numbers with fewer than nine digits', () => {
		expect(extract({ body: 'Meet at 12345678 tomorrow' })).toEqual([])
	})

	it('keeps each value once per type, ignoring case and surrounding space', () => {
		expect(
			extract({
				from: [{ email: 'Alice@Example.com', label: ' Alice ' }],
				to: [{ email: 'alice@example.com', label: 'alice' }],
				body: 'ALICE@EXAMPLE.COM',
			}),
		).toEqual([
			['EMAIL', 'Alice@Example.com'],
			['PERSON', 'Alice'],
		])
	})

	it('reads the text of an HTML body, not its markup', () => {
		expect(
			extract({
				hasHtmlBody: true,
				body: '<p>Write to <a href="mailto:hidden@markup.nl">us</a> at <b>desk@gemeente.nl</b></p>',
			}),
		).toEqual([['EMAIL', 'desk@gemeente.nl']])
	})

	it('extracts nothing from an empty envelope', () => {
		expect(extract({})).toEqual([])
		expect(extract({ hasHtmlBody: true })).toEqual([])
	})

	it('gives each entity an id from its type and lower-cased value', () => {
		const [entity] = idleTab().vm.extractEntities({ body: 'Info@X.nl' })
		expect(entity).toEqual({ id: 'EMAIL:info@x.nl', type: 'EMAIL', value: 'Info@X.nl' })
	})
})

describe('EntitiesTab loading', () => {
	it('reads a body response without the data wrapper', async () => {
		axios.get.mockResolvedValue({ data: { body: 'a@b.nl' } })
		const wrapper = mount(EntitiesTab, { props: { accountId: 1, messageId: 3 } })
		await flushPromises()

		expect(wrapper.vm.entities.map((e) => e.value)).toEqual(['a@b.nl'])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('logs a failed load and shows the empty state', async () => {
		const failure = new Error('mail down')
		axios.get.mockRejectedValue(failure)
		const wrapper = mount(EntitiesTab, { props: { accountId: 1, messageId: 3 } })
		await flushPromises()

		expect(errorSpy).toHaveBeenCalledWith('[EntitiesTab] Load failed:', failure)
		expect(wrapper.vm.entities).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.or-tab-empty').text()).toBe('t:No entities detected for this email.')
	})

	it('clears the entities without a request when the message closes', async () => {
		axios.get.mockResolvedValue({ data: { data: { body: 'a@b.nl' } } })
		const wrapper = mount(EntitiesTab, { props: { accountId: 1, messageId: 3 } })
		await flushPromises()
		expect(wrapper.vm.entities).toHaveLength(1)

		await wrapper.setProps({ messageId: null })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.entities).toEqual([])
	})
})

describe('EntitiesTab rendering', () => {
	it('labels each known group and passes an unknown type through', () => {
		const vm = idleTab().vm
		expect(['PERSON', 'EMAIL', 'PHONE', 'IBAN', 'URL', 'unknown', 'ORG'].map(vm.formatType)).toEqual([
			't:People',
			't:Email Addresses',
			't:Phone Numbers',
			't:IBANs',
			't:Links',
			't:Other',
			'ORG',
		])
	})

	it('groups untyped entities as other and shows a confidence as a percentage', async () => {
		const wrapper = idleTab()
		await wrapper.setData({
			entities: [
				{ id: '1', type: 'PERSON', value: 'Alice', confidence: 0.876 },
				{ id: '2', value: 'Something' },
			],
		})

		const groups = wrapper.findAll('.or-entity-group')
		expect(groups.map((g) => g.find('.or-entity-group-title').text())).toEqual(['t:People', 't:Other'])
		expect(groups[0].find('.or-entity-confidence').text()).toBe('88%')
		expect(groups[1].find('.or-entity-value').text()).toBe('Something')
		expect(groups[1].find('.or-entity-confidence').exists()).toBe(false)
	})
})
