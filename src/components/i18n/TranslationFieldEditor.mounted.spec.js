/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The translation field editor and its status chip, mounted: one field per
 * language, writing direction, status, and the value it emits.
 *
 * @spec exclude Unit test of the client-side translation field editor.
 */
import { mount } from '@vue/test-utils'
import TranslationFieldEditor from './TranslationFieldEditor.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

/**
 * The values the editor emitted, without the native input events that bubble
 * up through its root element (the component declares no emits).
 *
 * @param {object} wrapper The wrapper
 * @return {Array<object>} The emitted values
 */
function emittedValues(wrapper) {
	return (wrapper.emitted('input') ?? [])
		.map(([payload]) => payload)
		.filter((payload) => !(payload instanceof Event))
}

/**
 * Mount the editor.
 *
 * @param {object} props Overrides
 * @return {object} The wrapper
 */
function mountEditor(props = {}) {
	return mount(TranslationFieldEditor, {
		props: {
			value: { nl: 'Zaak', ar: 'قضية' },
			languages: ['nl', 'ar', 'en'],
			statuses: { nl: 'approved', ar: 'machine_translated' },
			label: 'Title',
			...props,
		},
	})
}

describe('TranslationFieldEditor mounted', () => {
	it('renders one labelled input per language with its direction', () => {
		const wrapper = mountEditor()
		expect(wrapper.find('label').text()).toBe('Title')
		const inputs = wrapper.findAll('input')
		expect(inputs.map((i) => [i.attributes('lang'), i.attributes('dir'), i.element.value])).toEqual([
			['nl', 'ltr', 'Zaak'],
			['ar', 'rtl', 'قضية'],
			['en', 'ltr', ''],
		])
		expect(inputs[2].attributes('aria-label')).toBe('Translation in EN')
		expect(wrapper.find('textarea').exists()).toBe(false)
	})

	it('shows a status chip only for a language with a status', () => {
		const wrapper = mountEditor()
		const chips = wrapper.findAll('.translation-status-chip')
		expect(chips.map((c) => c.attributes('title'))).toEqual([
			'Translation status: Approved (NL)',
			'Translation status: Machine (AR)',
		])
		expect(chips[0].classes()).toContain('translation-status-chip--approved')
		expect(chips[0].find('.translation-status-chip__icon').text()).toBe('✓')
	})

	it('uses text areas when multiline, and can be disabled', () => {
		const wrapper = mountEditor({ multiline: true, disabled: true, label: '' })
		expect(wrapper.find('label').exists()).toBe(false)
		const areas = wrapper.findAll('textarea')
		expect(areas).toHaveLength(3)
		expect(areas[0].attributes('disabled')).toBeDefined()
		expect(areas[1].attributes('dir')).toBe('rtl')
	})

	it('hides languages without a value when asked', () => {
		const wrapper = mountEditor({ hideEmpty: true })
		expect(wrapper.findAll('.translation-field-editor__lang-badge').map((b) => b.text())).toEqual([
			'NL',
			'AR',
		])
	})

	it('emits the whole value with one language changed or removed', async () => {
		const wrapper = mountEditor()
		await wrapper.findAll('input')[2].setValue('Case')
		await wrapper.findAll('input')[0].setValue('')
		expect(emittedValues(wrapper)).toEqual([
			{ nl: 'Zaak', ar: 'قضية', en: 'Case' },
			{ ar: 'قضية' },
		])
	})

	it('starts from no value and no statuses when given none', async () => {
		const wrapper = mount(TranslationFieldEditor, { props: { languages: ['de'] } })
		expect(wrapper.find('input').element.value).toBe('')
		expect(wrapper.find('.translation-status-chip').exists()).toBe(false)
		await wrapper.find('input').setValue('Fall')
		expect(emittedValues(wrapper)).toEqual([{ de: 'Fall' }])
	})

	it('emits from a multiline field too', async () => {
		const wrapper = mountEditor({ multiline: true })
		await wrapper.findAll('textarea')[2].setValue('Long')
		expect(emittedValues(wrapper)[0].en).toBe('Long')
	})
})
