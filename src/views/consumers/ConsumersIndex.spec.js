/**
 * ConsumersIndex: lists the consumers with their grant and end date, and
 * opens the grant editor on one.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import ConsumersIndex from './ConsumersIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn() }))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		NcAppContent: { name: 'NcAppContent', render() { return h('div', this.$slots.default?.()) } },
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
	}
})
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		CnDataTable: {
			name: 'CnDataTable',
			props: { columns: Array, rows: Array },
			render() {
				return h('table', this.rows.map((row) => h('tr', { 'data-row': row.id }, [
					...this.columns.map((column) => h('td', this.$slots[`column-${column.key}`] ? this.$slots[`column-${column.key}`]({ row }) : row[column.key])),
					h('td', this.$slots['row-actions']({ row })),
				])))
			},
		},
	}
})
jest.mock('../ListTableStates.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'ListTableStates',
			props: { loading: Boolean, empty: Boolean, emptyName: String },
			render() {
				return this.empty ? h('p', { class: 'empty' }, this.emptyName) : h('div', this.$slots.default?.())
			},
		},
	}
})
jest.mock('../../modals/consumers/ConsumerGrantModal.vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'ConsumerGrantModal',
			props: { consumer: Object },
			emits: ['close', 'saved'],
			render() {
				return h('div', { class: 'grant-modal', 'data-consumer': this.consumer.id })
			},
		},
	}
})

const consumers = [
	{ id: 1, name: 'Supplier', userId: 'supplier', authorizationConfiguration: { grant: { verbs: ['read', 'update'], schemas: ['case'], expiresAt: '2027-03-01T23:59:59+00:00' } } },
	{ id: 2, name: 'Legacy', userId: 'legacy', authorizationConfiguration: [] },
]

/**
 * Mount with the consumer list answering.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountList() {
	axios.get.mockResolvedValue({ data: { results: consumers, total: 2 } })
	const wrapper = mount(ConsumersIndex)
	await flushPromises()
	return wrapper
}

describe('ConsumersIndex', () => {
	beforeEach(() => jest.clearAllMocks())

	it('lists each consumer with its grant and end date', async () => {
		const wrapper = await mountList()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/consumers')
		expect(wrapper.find('[data-grant="1"]').text()).toBe('read, update · case')
		expect(wrapper.find('[data-row="1"]').text()).toContain(new Date('2027-03-01T23:59:59+00:00').toLocaleDateString())
		expect(wrapper.find('[data-grant="2"]').text()).toBe('—')
	})

	it('shows the empty state when the list cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(ConsumersIndex)
		await flushPromises()

		expect(wrapper.find('.empty').text()).toBe('No consumers.')
	})

	it('opens the grant editor on a consumer and reloads after a save', async () => {
		const wrapper = await mountList()
		await wrapper.find('[data-edit="1"]').trigger('click')
		expect(wrapper.find('.grant-modal').attributes('data-consumer')).toBe('1')

		wrapper.findComponent({ name: 'ConsumerGrantModal' }).vm.$emit('saved', 'Grant saved')
		await flushPromises()

		expect(wrapper.find('.grant-modal').exists()).toBe(false)
		expect(showSuccess).toHaveBeenCalledWith('Grant saved')
		expect(axios.get).toHaveBeenCalledTimes(2)
	})
})
