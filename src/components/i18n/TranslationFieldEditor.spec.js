// See TranslationStatusChip.spec.js for why these are mocked.
import TranslationFieldEditor from './TranslationFieldEditor.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: jest.fn((p) => p) }))

/**
 * See TranslationStatusChip.spec.js for why we exercise the component
 * options object directly instead of mounting.
 * @param key
 * @param ctx
 */
const callComputed = (key, ctx) => TranslationFieldEditor.computed[key].call(ctx)
const callMethod = (key, ctx, ...args) => TranslationFieldEditor.methods[key].apply(ctx, args)

describe('TranslationFieldEditor', () => {
	describe('orderedLanguages', () => {
		it('returns the full languages list when hideEmpty is false', () => {
			const ctx = {
				hideEmpty: false,
				languages: ['nl', 'en'],
				value: { nl: 'Hallo' },
				getValue(lang) { return this.value?.[lang] ?? '' },
			}
			expect(callComputed('orderedLanguages', ctx)).toEqual(['nl', 'en'])
		})

		it('filters out empty languages when hideEmpty is true', () => {
			const ctx = {
				hideEmpty: true,
				languages: ['nl', 'en'],
				value: { nl: 'Hallo' },
				getValue(lang) { return typeof this.value?.[lang] === 'string' ? this.value[lang] : '' },
			}
			expect(callComputed('orderedLanguages', ctx)).toEqual(['nl'])
		})
	})

	describe('getValue', () => {
		it('returns the slot value when present', () => {
			const ctx = { value: { nl: 'Hallo' } }
			expect(callMethod('getValue', ctx, 'nl')).toBe('Hallo')
		})

		it('returns empty string when the slot is missing or non-string', () => {
			expect(callMethod('getValue', { value: {} }, 'nl')).toBe('')
			expect(callMethod('getValue', { value: { nl: 42 } }, 'nl')).toBe('')
			expect(callMethod('getValue', { value: null }, 'nl')).toBe('')
		})
	})

	describe('getStatus', () => {
		it('returns the status when the map has it', () => {
			const ctx = { statuses: { nl: 'approved' } }
			expect(callMethod('getStatus', ctx, 'nl')).toBe('approved')
		})

		it('returns null when no status is recorded', () => {
			expect(callMethod('getStatus', { statuses: {} }, 'nl')).toBeNull()
			expect(callMethod('getStatus', { statuses: null }, 'nl')).toBeNull()
		})
	})

	describe('dirFor', () => {
		it('returns rtl for Arabic, ltr for English', () => {
			const ctx = {}
			expect(callMethod('dirFor', ctx, 'ar')).toBe('rtl')
			expect(callMethod('dirFor', ctx, 'he-IL')).toBe('rtl')
			expect(callMethod('dirFor', ctx, 'en')).toBe('ltr')
			expect(callMethod('dirFor', ctx, 'nl')).toBe('ltr')
		})
	})

	describe('placeholderFor', () => {
		it('renders an upper-cased BCP 47 hint', () => {
			expect(callMethod('placeholderFor', {}, 'nl')).toBe('Translation in NL')
			expect(callMethod('placeholderFor', {}, 'en-GB')).toBe('Translation in EN-GB')
		})
	})

	describe('onInput', () => {
		it('emits an updated value object preserving other languages', () => {
			const emitted = []
			const ctx = {
				value: { nl: 'Hallo' },
				$emit(name, payload) { emitted.push([name, payload]) },
			}
			callMethod('onInput', ctx, 'en', 'Hello')
			expect(emitted).toEqual([['input', { nl: 'Hallo', en: 'Hello' }]])
		})

		it('drops the slot when the new value is empty', () => {
			const emitted = []
			const ctx = {
				value: { nl: 'Hallo', en: 'Hello' },
				$emit(name, payload) { emitted.push([name, payload]) },
			}
			callMethod('onInput', ctx, 'en', '')
			expect(emitted[0][1]).toEqual({ nl: 'Hallo' })
			expect('en' in emitted[0][1]).toBe(false)
		})

		it('handles an undefined initial value object', () => {
			const emitted = []
			const ctx = {
				value: undefined,
				$emit(name, payload) { emitted.push([name, payload]) },
			}
			callMethod('onInput', ctx, 'nl', 'Hallo')
			expect(emitted[0][1]).toEqual({ nl: 'Hallo' })
		})
	})
})
