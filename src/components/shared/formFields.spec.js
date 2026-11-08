/**
 * FormSelectField and FormCheckboxField render the markup EditWebhook and
 * EditConfiguration wrote inline before, and hand every attribute, listener
 * and slot on to the Nextcloud control they wrap.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of presentational form fields.
 */
import { mount } from '@vue/test-utils'
import FormCheckboxField from './FormCheckboxField.vue'
import FormSelectField from './FormSelectField.vue'
import SelectOption from './SelectOption.vue'

jest.mock('@nextcloud/vue', () => ({
	NcSelect: { name: 'NcSelect', template: '<div />' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', template: '<span />' },
}))

/**
 * Stands in for NcSelect: shows the attributes it got and renders the
 * option slot for every option, then the no-options slot.
 */
const SelectStub = {
	name: 'NcSelect',
	inheritAttrs: false,
	props: ['options'],
	emits: ['update:modelValue'],
	template: `<div class="nc-select" :data-attrs="JSON.stringify($attrs)">
		<button class="pick" @click="$emit('update:modelValue', options[0])" />
		<template v-for="(o, i) in options" :key="i"><slot name="option" v-bind="o" /></template>
		<slot name="no-options" />
	</div>`,
}

const SwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	inheritAttrs: false,
	emits: ['update:modelValue'],
	template: `<span class="nc-switch" :data-attrs="JSON.stringify($attrs)" @click="$emit('update:modelValue', true)"><slot /></span>`,
}

const OLD_SELECT = {
	components: { NcSelect: SelectStub, SelectOption },
	props: ['options'],
	template: `<div class="selectField">
		<label for="type-select">Type</label>
		<NcSelect
			id="type-select"
			:options="options"
			label="title"
			trackBy="id"
			:labelOutside="true"
			placeholder="Pick">
			<template #option="{ title, description }">
				<SelectOption :title="title" :description="description" />
			</template>
			<template #no-options>
				<span>Nothing</span>
			</template>
		</NcSelect>
		<p class="field-hint">
			The type
		</p>
	</div>`,
}

const NEW_SELECT = {
	components: { FormSelectField },
	props: ['options'],
	template: `<FormSelectField
		id="type-select"
		title="Type"
		hint="The type"
		:options="options"
		label="title"
		trackBy="id"
		placeholder="Pick">
		<template #noOptions>
			<span>Nothing</span>
		</template>
	</FormSelectField>`,
}

const OLD_SWITCH = {
	components: { NcCheckboxRadioSwitch: SwitchStub },
	template: `<div class="checkboxField">
		<NcCheckboxRadioSwitch :modelValue="true">
			Enabled
		</NcCheckboxRadioSwitch>
		<p class="field-hint">
			Turn it on
		</p>
	</div>`,
}

const NEW_SWITCH = {
	components: { FormCheckboxField },
	template: `<FormCheckboxField :modelValue="true" hint="Turn it on">
		Enabled
	</FormCheckboxField>`,
}

const stubs = { NcSelect: SelectStub, NcCheckboxRadioSwitch: SwitchStub }

/**
 * The markup as a browser lays it out: without the scope attributes each
 * component adds, with the whitespace around tags (which HTML collapses)
 * removed and the stubs' attribute lists in a fixed order.
 *
 * @param {object} wrapper A mounted component
 * @return {string} Its HTML
 */
function html(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[a-z0-9]+(="")?/g, '')
		.replace(/data-attrs="([^"]*)"/g, (_, json) => {
			const attrs = JSON.parse(json.replace(/&quot;/g, '"'))
			return `data-attrs="${Object.keys(attrs).sort().map((key) => `${key}=${JSON.stringify(attrs[key])}`).join(' ')}"`
		})
		.replace(/>\s+/g, '>')
		.replace(/\s+</g, '<')
}

describe('FormSelectField', () => {
	const options = [
		{ id: 1, title: 'One', description: 'First' },
		{ id: 2, title: 'Two' },
	]

	it('renders what the inline label, select and hint rendered', () => {
		const old = mount(OLD_SELECT, { props: { options } })
		const now = mount(NEW_SELECT, { props: { options }, global: { stubs } })
		expect(html(now)).toBe(html(old))
	})

	it('lets the option and hint slots replace the defaults', () => {
		const wrapper = mount(FormSelectField, {
			props: { title: 'Groups' },
			attrs: { label: 'title', options },
			slots: {
				option: '<template #option="{ title }"><b>{{ title }}</b></template>',
				hint: '<i>2 selected</i>',
			},
			global: { stubs },
		})
		expect(wrapper.findAll('b').map((b) => b.text())).toEqual(['One', 'Two'])
		expect(wrapper.find('.field-hint').html()).toContain('<i>2 selected</i>')
		expect(wrapper.find('label').attributes('for')).toBeUndefined()
	})

	it('passes the chosen option on to v-model', async () => {
		const onUpdate = jest.fn()
		const wrapper = mount(FormSelectField, {
			attrs: { options, label: 'title', 'onUpdate:modelValue': onUpdate },
			global: { stubs },
		})
		await wrapper.find('.pick').trigger('click')
		expect(onUpdate).toHaveBeenCalledWith(options[0])
	})
})

describe('FormCheckboxField', () => {
	it('renders what the inline switch and hint rendered', () => {
		const old = mount(OLD_SWITCH)
		const now = mount(NEW_SWITCH, { global: { stubs } })
		expect(html(now)).toBe(html(old))
	})

	it('passes the switch change on', async () => {
		const onUpdate = jest.fn()
		const wrapper = mount(FormCheckboxField, {
			attrs: { 'onUpdate:modelValue': onUpdate },
			global: { stubs },
		})
		await wrapper.find('.nc-switch').trigger('click')
		expect(onUpdate).toHaveBeenCalledWith(true)
	})
})
