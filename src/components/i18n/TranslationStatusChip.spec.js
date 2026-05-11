// Stub Nextcloud ESM-only deps so jest can require the .vue file (which
// transitively imports the translations store).
import TranslationStatusChip from './TranslationStatusChip.vue'
import { TRANSLATION_STATUSES } from '../../store/modules/translations.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: jest.fn((p) => p) }))

/**
 * The repo's installed @vue/test-utils is the Vue 3 build, but the app
 * runs on Vue 2.7. Rather than introduce a second test-utils dependency,
 * exercise the component options object directly (validators, computed,
 * methods) which is a stable Vue 2 unit-test idiom.
 * @param key
 * @param ctx
 */
const callComputed = (key, ctx) => TranslationStatusChip.computed[key].call(ctx)

describe('TranslationStatusChip', () => {
	describe('prop validator', () => {
		const validator = TranslationStatusChip.props.status.validator

		it('accepts every TRANSLATION_STATUSES value', () => {
			Object.values(TRANSLATION_STATUSES).forEach((s) => {
				expect(validator(s)).toBe(true)
			})
		})

		it('rejects unknown statuses', () => {
			expect(validator('not_a_status')).toBe(false)
			expect(validator('')).toBe(false)
		})
	})

	describe('meta', () => {
		it.each([
			[TRANSLATION_STATUSES.DRAFT, 'Draft'],
			[TRANSLATION_STATUSES.MACHINE_TRANSLATED, 'Machine'],
			[TRANSLATION_STATUSES.HUMAN_REVIEWED, 'Reviewed'],
			[TRANSLATION_STATUSES.APPROVED, 'Approved'],
		])('maps %s to label %s', (status, label) => {
			const ctx = { status }
			expect(callComputed('meta', ctx).label).toBe(label)
			expect(callComputed('labelText', { ...ctx, meta: callComputed('meta', ctx) })).toBe(label)
		})

		it('falls back to status string for unknown statuses', () => {
			const ctx = { status: 'mystery' }
			expect(callComputed('meta', ctx)).toEqual({ icon: '?', label: 'mystery' })
		})
	})

	describe('tooltipText', () => {
		const buildCtx = (status, language) => {
			const ctx = { status, language }
			ctx.meta = callComputed('meta', ctx)
			ctx.labelText = ctx.meta.label
			return ctx
		}

		it('appends the language tag in upper case when provided', () => {
			const ctx = buildCtx(TRANSLATION_STATUSES.APPROVED, 'nl')
			expect(callComputed('tooltipText', ctx)).toBe('Translation status: Approved (NL)')
		})

		it('omits the language suffix when language is empty', () => {
			const ctx = buildCtx(TRANSLATION_STATUSES.DRAFT, '')
			expect(callComputed('tooltipText', ctx)).toBe('Translation status: Draft')
		})
	})
})
