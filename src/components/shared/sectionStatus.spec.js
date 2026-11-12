/**
 * SectionStatus and the sectionStatus mixin replace the status paragraph and
 * message/isError bookkeeping the account sections each carried inline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a presentational status line and its state.
 */
import { mount } from '@vue/test-utils'
import SectionStatus from './SectionStatus.vue'
import { sectionStatus } from '../../composables/sectionStatus.js'

const Host = {
	components: { SectionStatus },
	mixins: [sectionStatus],
	template: '<div><SectionStatus :message="message" :isError="isError" /></div>',
}

describe('SectionStatus', () => {
	it('renders nothing without a message', () => {
		const w = mount(Host)
		expect(w.find('p').exists()).toBe(false)
	})

	it('renders the markup the sections wrote inline', async () => {
		const w = mount(Host)
		w.vm.setStatus('Saved')
		await w.vm.$nextTick()
		expect(w.html()).toBe(
			'<div>\n  <p class="section__success">Saved</p>\n</div>',
		)
		w.vm.setStatus('Nope', true)
		await w.vm.$nextTick()
		expect(w.find('p').classes()).toEqual(['section__error'])
		expect(w.find('p').text()).toBe('Nope')
	})

	it('prefers the API error and falls back to the given text', () => {
		const w = mount(Host)
		w.vm.setStatusError(
			{ response: { data: { error: 'API says no' } } },
			'Fallback',
		)
		expect([w.vm.message, w.vm.isError]).toEqual(['API says no', true])
		w.vm.setStatusError({}, 'Fallback')
		expect([w.vm.message, w.vm.isError]).toEqual(['Fallback', true])
	})
})
