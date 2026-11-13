/**
 * The clear-audit-trails dialog mounted: the count and active filters it
 * shows when it opens, the warning without filters, the DELETE it sends with
 * those filters, and what it shows and does after a success or a refusal.
 *
 * @spec exclude Client-side clear-audit-trails dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ClearAuditTrails from './ClearAuditTrails.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The dialog mixes <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcNoteCard: mockLibrary().NcNoteCard,
}))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => mockModule('DeleteOutline'))
jest.mock('../shared/ConfirmDialogFrame.vue', () => mockModule('ConfirmDialogFrame'))
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		auditTrailStore: reactive({
			auditTrailFilters: null,
			auditTrailPagination: { total: 0 },
			refreshAuditTrailList: jest.fn(),
		}),
		navigationStore: reactive({ dialog: null, setDialog: jest.fn() }),
	}
})

const { auditTrailStore, navigationStore } = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and every slot.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'function')
					.map(([key, value]) => [key, typeof value === 'object' ? JSON.stringify(value) : value]),
			)
			const slots = Object.entries(this.$slots).map(([slot, fn]) =>
				render('section', { 'data-slot': slot }, fn()),
			)
			return render(`x-${name}`, attrs, slots)
		},
	}
}

/**
 * Mount the dialog closed, then open it as the list page does.
 *
 * @return {Promise<object>} The wrapper
 */
async function open() {
	const wrapper = mount(ClearAuditTrails, {
		global: {
			config: {
				globalProperties: {
					t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
				},
			},
		},
	})
	navigationStore.dialog = 'clearAuditTrails'
	await flushPromises()
	return wrapper
}

const frame = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialogFrame' })

/**
 * Answer the DELETE request.
 *
 * @param {object|Error} body The JSON body, or a rejection
 */
function answer(body) {
	global.fetch = jest.fn(() => (body instanceof Error
		? Promise.reject(body)
		: Promise.resolve({ json: () => Promise.resolve(body) })))
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	navigationStore.dialog = null
	auditTrailStore.auditTrailPagination = { total: 12 }
	auditTrailStore.auditTrailFilters = {
		action: 'delete',
		register: '',
		onlyWithChanges: true,
		schema: ['5', '6'],
		user: null,
		custom: 7,
	}
	auditTrailStore.refreshAuditTrailList.mockResolvedValue()
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
	delete global.fetch
})

describe('ClearAuditTrails mounted', () => {
	it('renders nothing until its dialog opens', () => {
		const wrapper = mount(ClearAuditTrails)
		expect(frame(wrapper).exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows the entry count and every non-empty filter when it opens', async () => {
		const wrapper = await open()
		expect(frame(wrapper).vm.$attrs).toMatchObject({
			name: 't:Clear Filtered Audit Trails',
			showConfirm: true,
			closeLabel: 't:Cancel',
			confirmLabel: 't:Clear Entries',
			loading: false,
		})
		expect(wrapper.get('.clear-info p').text()).toBe('t:Entries to be deleted: 12')
		const filters = wrapper.findAll('.filter-item').map((item) => item.text())
		expect(filters).toEqual([
			't:Action:delete',
			't:Only With Changes:t:Yes',
			't:Schema:5, 6',
			'custom:7',
		])
		expect(wrapper.find('.no-filters-warning').exists()).toBe(false)
	})

	it('warns that everything goes when no filter is active', async () => {
		auditTrailStore.auditTrailFilters = { action: '', user: null }
		const wrapper = await open()
		expect(wrapper.find('.active-filters').exists()).toBe(false)
		expect(wrapper.get('.no-filters-warning').text()).toBe(
			't:No filters are currently active. This will delete ALL audit trail entries!',
		)
	})

	it('deletes with the active filters, reports the server message and closes after three seconds', async () => {
		jest.useFakeTimers()
		answer({ success: true, message: 'Deleted 12 entries' })
		const wrapper = await open()
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()

		const [url, init] = global.fetch.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/audit-trails?action=delete&onlyWithChanges=true&schema=5%2C6&custom=7',
		)
		expect(init.method).toBe('DELETE')
		expect(init.headers['Content-Type']).toBe('application/json')
		expect(init.headers).toHaveProperty('requesttoken')
		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)
		expect(frame(wrapper).vm.$attrs).toMatchObject({
			success: true,
			error: false,
			loading: false,
			showConfirm: false,
			closeLabel: 't:Close',
			successText: 'Deleted 12 entries',
		})
		expect(wrapper.find('.clear-info').exists()).toBe(false)

		jest.advanceTimersByTime(2999)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('counts the cleared entries when the server sends no message', async () => {
		answer({ success: true })
		const wrapper = await open()
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(frame(wrapper).vm.$attrs.successText).toBe('t:{count} audit trails cleared successfully{"count":12}')
	})

	it.each([
		[{ success: false, error: 'Entries are immutable' }, 'Entries are immutable'],
		[{ success: false }, 'Deletion failed'],
		[new Error(''), 't:An error occurred while clearing audit trails'],
	])('shows why a clear was refused or failed %#', async (body, message) => {
		answer(body)
		const wrapper = await open()
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(frame(wrapper).vm.$attrs).toMatchObject({ success: false, error: message, loading: false })
		expect(auditTrailStore.refreshAuditTrailList).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalledWith('Error clearing audit trails:', expect.any(Error))
	})

	it('cancel closes the dialog and resets its state', async () => {
		answer({ success: false })
		const wrapper = await open()
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		frame(wrapper).vm.$emit('cancel')
		await flushPromises()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.vm.success).toBeNull()
		expect(wrapper.vm.error).toBe(false)
		expect(wrapper.vm.successMessage).toBe('')
	})
})
