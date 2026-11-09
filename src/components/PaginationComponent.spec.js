/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pager: which page buttons it shows and what it emits.
 *
 * @spec exclude Unit test of the client-side pager.
 */
import { mount } from '@vue/test-utils'
import PaginationComponent from './PaginationComponent.vue'

jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['option:selected'],
		render: () => null,
	},
}))

/**
 * Mount the pager.
 *
 * @param {object} props The props
 * @return {object} The wrapper
 */
function mountPager(props) {
	return mount(PaginationComponent, {
		props,
		global: {
			mocks: {
				t: (app, text, vars = {}) =>
					text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
			},
		},
	})
}

/**
 * The page-number labels shown, ellipses included.
 *
 * @param {object} wrapper The wrapper
 * @return {Array<string>} The labels
 */
function numbers(wrapper) {
	return wrapper
		.find('.viewPaginationNumbers')
		.findAll('button, span')
		.map((el) => el.text())
}

/**
 * The navigation button with a label.
 *
 * @param {object} wrapper The wrapper
 * @param {string} label The label
 * @return {object} The button
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((b) => b.text() === label)
}

describe('PaginationComponent', () => {
	it('renders nothing for a single small page', () => {
		const wrapper = mountPager({ totalPages: 1, totalItems: 5 })
		expect(wrapper.find('.viewPagination').exists()).toBe(false)
	})

	it('shows only the size picker for one page of many items', () => {
		const wrapper = mountPager({ totalPages: 1, totalItems: 11 })
		expect(wrapper.find('.viewPageInfo').text()).toBe('Page 1 of 1')
		expect(wrapper.find('.viewPaginationNav').exists()).toBe(false)
	})

	it.each([
		[1, 5, ['1', '2', '3', '4', '5']],
		[2, 10, ['1', '2', '3', '4', '5', '...', '10']],
		[8, 10, ['1', '...', '6', '7', '8', '9', '10']],
		[7, 10, ['1', '...', '6', '7', '8', '9', '10']],
		[5, 10, ['1', '...', '4', '5', '6', '...', '10']],
	])('page %i of %i shows %j', (currentPage, totalPages, expected) => {
		const wrapper = mountPager({ currentPage, totalPages })
		expect(numbers(wrapper)).toEqual(expected)
		const current = button(wrapper, String(currentPage))
		expect(current.attributes('data-variant')).toBe('primary')
		expect(current.attributes('disabled')).toBeDefined()
	})

	it('emits the page each navigation button asks for', async () => {
		const wrapper = mountPager({ currentPage: 5, totalPages: 10 })
		for (const label of ['First', 'Previous', 'Next', 'Last', '6']) {
			await button(wrapper, label).trigger('click')
		}
		expect(wrapper.emitted('page-changed')).toEqual([[1], [4], [6], [10], [6]])
	})

	it('disables the edges and emits nothing out of range', () => {
		const first = mountPager({ currentPage: 1, totalPages: 3 })
		expect(button(first, 'First').attributes('disabled')).toBeDefined()
		expect(button(first, 'Previous').attributes('disabled')).toBeDefined()
		const last = mountPager({ currentPage: 3, totalPages: 3 })
		expect(button(last, 'Next').attributes('disabled')).toBeDefined()
		last.vm.changePage(3)
		last.vm.changePage(0)
		last.vm.changePage(4)
		expect(last.emitted('page-changed')).toBeUndefined()
	})

	it('picks the current page size, or the second option when it is unknown', () => {
		const known = mountPager({ totalPages: 2, currentPageSize: 50 })
		const select = known.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toEqual({ value: 50, label: '50' })
		const unknown = mountPager({ totalPages: 2, currentPageSize: 7 })
		expect(unknown.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual({
			value: 20,
			label: '20',
		})
	})

	it('emits a new page size, not the current one', () => {
		const wrapper = mountPager({ totalPages: 2, currentPageSize: 20 })
		const select = wrapper.findComponent({ name: 'NcSelect' })
		select.vm.$emit('option:selected', { value: 20 })
		select.vm.$emit('option:selected', { value: 100 })
		expect(wrapper.emitted('page-size-changed')).toEqual([[100]])
	})
})
