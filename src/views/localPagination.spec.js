/**
 * The client-side paging mixin starts where the list pages started and moves
 * the way their own handlers did.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of client-side paging plumbing.
 */
import { mount } from '@vue/test-utils'
import { localPagination } from './localPagination.js'

const Host = {
	mixins: [localPagination],
	template: '<p>{{ pagination.page }}/{{ pagination.limit }}</p>',
}

describe('localPagination', () => {
	it('starts on page 1 with 20 rows', () => {
		expect(mount(Host).text()).toBe('1/20')
	})

	it('moves to the page the paginator asks for', async () => {
		const wrapper = mount(Host)
		wrapper.vm.onPageChanged(3)
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toBe('3/20')
	})

	it('goes back to page 1 when the page size changes', async () => {
		const wrapper = mount(Host)
		wrapper.vm.onPageChanged(4)
		wrapper.vm.onPageSizeChanged(50)
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toBe('1/50')
	})
})
