/**
 * ConsumerGrantModal: saves and removes a Consumer's grant inside its
 * authorization configuration, keeping the rest, and lists its renewals.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ConsumerGrantModal from './ConsumerGrantModal.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { patch: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		NcModal: {
			name: 'NcModal',
			emits: ['close'],
			render() {
				return h('div', { class: 'nc-modal' }, this.$slots.default?.())
			},
		},
	}
})
jest.mock('../../components/tokens/TokenGrantEditor.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'TokenGrantEditor',
			props: { grant: Object, busy: Boolean, removable: Boolean },
			emits: ['save', 'remove'],
			render() {
				return h('div', { class: 'editor' })
			},
		},
	}
})

const grant = { verbs: ['read'], expiresAt: '2027-03-01T23:59:59+00:00' }
const consumer = {
	id: 5,
	name: 'Supplier',
	authorizationConfiguration: {
		publicKey: 'k',
		grant,
		grantRenewals: [
			{ by: 'admin', at: '2026-10-01T00:00:00+00:00', from: '2026-11-01T00:00:00+00:00', expiresAt: '2026-12-01T00:00:00+00:00' },
			{ by: 'root', at: '2026-11-01T00:00:00+00:00', from: '2026-12-01T00:00:00+00:00', expiresAt: '2027-03-01T23:59:59+00:00' },
		],
	},
}

describe('ConsumerGrantModal', () => {
	beforeEach(() => jest.clearAllMocks())

	it('edits the stored grant and lists the renewals newest first', () => {
		const wrapper = mount(ConsumerGrantModal, { props: { consumer } })
		const editor = wrapper.findComponent({ name: 'TokenGrantEditor' })

		expect(editor.props('grant')).toEqual(grant)
		expect(editor.props('removable')).toBe(true)
		const renewals = wrapper.findAll('[data-renewal]')
		expect(renewals).toHaveLength(2)
		expect(renewals[0].text()).toMatch(/^root:/)
	})

	it('saves the grant and keeps the rest of the configuration', async () => {
		axios.patch.mockResolvedValue({ data: {} })
		const wrapper = mount(ConsumerGrantModal, { props: { consumer } })
		const changed = { verbs: ['read', 'update'], expiresAt: '2027-06-01T23:59:59+00:00' }
		wrapper.findComponent({ name: 'TokenGrantEditor' }).vm.$emit('save', changed)
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/consumers/5', {
			authorizationConfiguration: { ...consumer.authorizationConfiguration, grant: changed },
		})
		expect(wrapper.emitted('saved')).toEqual([['Grant saved']])
	})

	it('removes only the grant', async () => {
		axios.patch.mockResolvedValue({ data: {} })
		const wrapper = mount(ConsumerGrantModal, { props: { consumer } })
		wrapper.findComponent({ name: 'TokenGrantEditor' }).vm.$emit('remove')
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/consumers/5', {
			authorizationConfiguration: { publicKey: 'k', grantRenewals: consumer.authorizationConfiguration.grantRenewals },
		})
		expect(wrapper.emitted('saved')).toEqual([['Grant removed']])
	})

	it('starts empty for a consumer without a grant or a configuration', () => {
		const wrapper = mount(ConsumerGrantModal, { props: { consumer: { id: 6, name: 'New', authorizationConfiguration: [] } } })

		expect(wrapper.findComponent({ name: 'TokenGrantEditor' }).props('grant')).toBeNull()
		expect(wrapper.findComponent({ name: 'TokenGrantEditor' }).props('removable')).toBe(false)
		expect(wrapper.find('[data-renewal]').exists()).toBe(false)
	})

	it('shows the server reason when the grant is refused', async () => {
		axios.patch.mockRejectedValue({ response: { data: { error: 'manage cannot be granted to a token' } } })
		const wrapper = mount(ConsumerGrantModal, { props: { consumer } })
		wrapper.findComponent({ name: 'TokenGrantEditor' }).vm.$emit('save', grant)
		await flushPromises()

		expect(wrapper.text()).toContain('manage cannot be granted to a token')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
