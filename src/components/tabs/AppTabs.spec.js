/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tab strip: registration, selection, keyboard navigation, v-model.
 *
 * @spec exclude Unit test of the client-side tab strip component.
 */
import { mount } from '@vue/test-utils'
import { defineComponent, h, inject } from 'vue'
import AppTab from './AppTab.vue'
import AppTabs from './AppTabs.vue'

let nextUid = 100
// A tab that registers as disabled, which AppTab itself does not offer.
const DisabledTab = defineComponent({
	setup() {
		const tabs = inject('appTabs')
		tabs.register({ uid: ++nextUid, title: 'Locked', disabled: true })
		return () => null
	},
})

/**
 * Mount a strip with tabs One, Two (or a disabled one) and Three.
 *
 * @param {object} props The AppTabs props
 * @param {object} options What to put in the middle
 * @return {Promise<object>} The wrapper, once the tabs have registered
 */
async function mountTabs(props = {}, { disabled = false, activeThree = false } = {}) {
	const wrapper = mount(AppTabs, {
		props,
		attachTo: document.body,
		slots: {
			default: () => [
				h(AppTab, { title: 'One' }, () => 'first'),
				disabled ? h(DisabledTab) : h(AppTab, { title: 'Two' }, () => 'second'),
				h(AppTab, { title: 'Three', active: activeThree }, {
					default: () => 'third',
					title: () => h('b', 'Three!'),
				}),
			],
		},
	})
	await wrapper.vm.$nextTick()
	return wrapper
}

const tabs = (wrapper) => wrapper.findAll('[role="tab"]')
const panel = (wrapper) => wrapper.find('[role="tabpanel"]').text()

afterEach(() => {
	document.body.innerHTML = ''
})

describe('AppTabs', () => {
	it('lists the registered tabs and shows the first panel', async () => {
		const wrapper = await mountTabs()
		expect(tabs(wrapper).map((t) => t.text())).toEqual(['One', 'Two', 'Three!'])
		expect(tabs(wrapper)[0].attributes('aria-selected')).toBe('true')
		expect(tabs(wrapper)[0].attributes('tabindex')).toBe('0')
		expect(tabs(wrapper)[1].attributes('tabindex')).toBe('-1')
		expect(panel(wrapper)).toBe('first')
	})

	it('starts on a tab that declares itself active', async () => {
		const wrapper = await mountTabs({}, { activeThree: true })
		expect(panel(wrapper)).toBe('third')
	})

	it('ignores an active tab when the parent controls the index', async () => {
		const wrapper = await mountTabs({ modelValue: 1 }, { activeThree: true })
		expect(panel(wrapper)).toBe('second')
	})

	it('selects on click and reports the index', async () => {
		const wrapper = await mountTabs()
		await tabs(wrapper)[1].trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([[1]])
		expect(panel(wrapper)).toBe('second')
		expect(tabs(wrapper)[1].classes()).toContain('app-tabs__tab--active')
	})

	it('will not select a disabled tab', async () => {
		const wrapper = await mountTabs({}, { disabled: true })
		expect(tabs(wrapper)[1].attributes('disabled')).toBeDefined()
		expect(tabs(wrapper)[1].classes()).toContain('app-tabs__tab--disabled')
		wrapper.vm.select(1)
		wrapper.vm.select(-1)
		wrapper.vm.select(3)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it.each([
		['ArrowRight', 0, 1],
		['ArrowRight', 2, 0],
		['ArrowLeft', 0, 2],
		['ArrowLeft', 2, 1],
		['Home', 2, 0],
		['End', 0, 2],
	])('%s from tab %i selects tab %i and focuses it', async (key, from, to) => {
		const wrapper = await mountTabs()
		await tabs(wrapper)[from].trigger('click')
		await wrapper.find('[role="tablist"]').trigger('keydown', { key })
		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([to])
		await wrapper.vm.$nextTick()
		expect(document.activeElement).toBe(tabs(wrapper)[to].element)
		expect(panel(wrapper)).toBe(['first', 'second', 'third'][to])
	})

	it('leaves other keys alone', async () => {
		const wrapper = await mountTabs()
		const event = new KeyboardEvent('keydown', { key: 'a', cancelable: true })
		wrapper.find('[role="tablist"]').element.dispatchEvent(event)
		expect(event.defaultPrevented).toBe(false)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('applies the layout classes', async () => {
		const wrapper = await mountTabs({ justified: true, pills: true, contentClass: 'pad' })
		const list = wrapper.find('[role="tablist"]')
		expect(list.classes()).toEqual(
			expect.arrayContaining(['app-tabs__list--justified', 'app-tabs__list--pills']),
		)
		expect(wrapper.find('.app-tabs__content').classes()).toContain('pad')
	})

	it('drops a tab that unmounts', async () => {
		const Host = defineComponent({
			data: () => ({ show: true }),
			render() {
				return h(AppTabs, null, () => [
					h(AppTab, { title: 'A' }, () => 'a'),
					this.show ? h(AppTab, { title: 'B' }, () => 'b') : null,
				])
			},
		})
		const wrapper = mount(Host)
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('[role="tab"]')).toHaveLength(2)
		wrapper.vm.show = false
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('[role="tab"]').map((t) => t.text())).toEqual(['A'])
	})
})
