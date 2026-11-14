/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The external register card: what it reads and shows in each state.
 *
 * @spec exclude Unit test of the client-side external register card.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ExternalRegisterCard from './ExternalRegisterCard.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/gen' + path }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, k) => (k in vars ? vars[k] : all)) }))
jest.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading" />' },
}))

/**
 * Mount the card and let it load.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function card(props) {
	const w = mount(ExternalRegisterCard, { props: { schema: 'bag-adres', ...props } })
	await flushPromises()
	return w
}

beforeEach(() => axios.get.mockReset())

describe('ExternalRegisterCard', () => {
	it('shows the record, when it was fetched and from where', async () => {
		axios.get.mockResolvedValueOnce({ data: { state: 'ok', record: { straat: 'Dam', huisnummer: 1, extra: 'x' }, fetchedAt: 'yesterday', source: 'BAG' } })
		const w = await card({ keyValue: '0363', display: ['straat', 'huisnummer'] })

		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/external-register/external-registers/bag-adres/0363')
		expect(w.findAll('dt').map((n) => n.text())).toEqual(['straat', 'huisnummer'])
		expect(w.findAll('dd').map((n) => n.text())).toEqual(['Dam', '1'])
		expect(w.find('[data-testid="external-register-fetched"]').text()).toBe('Fetched yesterday from BAG')
		expect(w.find('[data-testid="external-register-message"]').exists()).toBe(false)
	})

	it('refreshes past the cache', async () => {
		axios.get.mockResolvedValue({ data: { state: 'ok', record: {} } })
		const w = await card({ keyValue: '0363' })
		await w.find('[data-testid="external-register-refresh"]').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith('/gen/apps/openregister/api/external-register/external-registers/bag-adres/0363?refresh=1')
	})

	it('reads nothing without a key and says so', async () => {
		const w = await card({ keyValue: '' })

		expect(axios.get).not.toHaveBeenCalled()
		expect(w.find('[data-testid="external-register-message"]').text()).toBe('There is nothing to look up yet.')
		expect(w.find('[data-testid="external-register-refresh"]').exists()).toBe(false)
	})

	it('tells an empty register apart from a lookup that could not happen', async () => {
		axios.get.mockResolvedValueOnce({ data: { state: 'not-found', record: null } })
		const empty = await card({ keyValue: '1' })
		expect(empty.find('[data-testid="external-register-message"]').text()).toBe('The register holds no record for this key.')

		axios.get.mockResolvedValueOnce({ data: { state: 'not-configured', adminActionable: true, record: null } })
		const unset = await card({ keyValue: '1' })
		expect(unset.find('[data-testid="external-register-message"]').text()).toBe('This register is not available here yet. An administrator can set it up.')
		expect(unset.find('dl').exists()).toBe(false)
	})

	it('reads a refused read as refused and any other failure as no answer', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 404 } })
		const refused = await card({ keyValue: '1' })
		expect(refused.find('[data-testid="external-register-message"]').text()).toBe('The register refused this request.')

		axios.get.mockRejectedValueOnce(new Error('network'))
		const down = await card({ keyValue: '1' })
		expect(down.find('[data-testid="external-register-message"]').text()).toBe('The register did not answer. Try again later.')
	})
})
