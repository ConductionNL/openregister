/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { objectUsageGuard } from './objectUsageGuard.js'

jest.mock('../store/store.js', () => ({ __esModule: true, objectStore: {}, schemaStore: {} }))

/**
 * A dialog using the guard, counting its init calls.
 *
 * @param {object} state Reactive `{ open }`.
 * @return {object} The component.
 */
function dialog(state) {
	return {
		mixins: [objectUsageGuard(() => state.open)],
		data: () => ({ objects: [], inits: 0 }),
		methods: { initDialog() { this.inits++ } },
		template: '<p>{{ state.open ? "open" : "closed" }} {{ inits }}</p>',
		setup: () => ({ state }),
	}
}

describe('objectUsageGuard', () => {
	it('initializes a dialog that mounts open, once', async () => {
		const state = reactive({ open: true })
		const wrapper = mount(dialog(state))
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.inits).toBe(1)
	})

	it('initializes a dialog that mounts closed when it opens, once', async () => {
		const state = reactive({ open: false })
		const wrapper = mount(dialog(state))
		expect(wrapper.vm.inits).toBe(0)

		state.open = true
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.inits).toBe(1)
	})

	it('enables deletion only when no objects use it', () => {
		const wrapper = mount(dialog(reactive({ open: false })))
		expect(wrapper.vm.canDelete).toBe(true)
	})
})
