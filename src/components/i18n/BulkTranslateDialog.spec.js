import BulkTranslateDialog from './BulkTranslateDialog.vue'
import { useTranslationsStore } from '../../store/modules/translations.js'

jest.mock('../../store/modules/translations.js', () => ({
	useTranslationsStore: jest.fn(),
}))

/**
 * See TranslationStatusChip.spec.js for why we exercise the component
 * options object directly instead of mounting.
 * @param key
 * @param ctx
 */
const callComputed = (key, ctx) => BulkTranslateDialog.computed[key].call(ctx)
const callMethod = (key, ctx, ...args) => BulkTranslateDialog.methods[key].apply(ctx, args)
const callWatcher = (key, ctx, value) => BulkTranslateDialog.watch[key].call(ctx, value)

const baseData = () => BulkTranslateDialog.data()

describe('BulkTranslateDialog', () => {
	describe('canSubmit', () => {
		it('requires both languages to be set and to differ', () => {
			expect(callComputed('canSubmit', { loading: false, from: 'nl', to: 'en' })).toBe(true)
			expect(callComputed('canSubmit', { loading: false, from: 'nl', to: 'nl' })).toBe(false)
			expect(callComputed('canSubmit', { loading: false, from: '', to: 'en' })).toBe(false)
			expect(callComputed('canSubmit', { loading: false, from: 'nl', to: '' })).toBe(false)
		})

		it('blocks submission while loading', () => {
			expect(callComputed('canSubmit', { loading: true, from: 'nl', to: 'en' })).toBe(false)
		})
	})

	describe('hasTranslated / hasSkipped', () => {
		it('reports translated/skipped presence', () => {
			expect(callComputed('hasTranslated', { result: { translated: { title: 'Hi' } } })).toBe(true)
			expect(callComputed('hasTranslated', { result: { translated: {} } })).toBe(false)
			expect(callComputed('hasTranslated', { result: null })).toBeFalsy()

			expect(callComputed('hasSkipped', { result: { skipped: { x: 'reason' } } })).toBe(true)
			expect(callComputed('hasSkipped', { result: { skipped: {} } })).toBe(false)
			expect(callComputed('hasSkipped', { result: null })).toBeFalsy()
		})
	})

	describe('open watcher', () => {
		it('resets state when the dialog opens', () => {
			const ctx = { from: 'nl', to: 'en', error: 'old', result: { translated: {} } }
			callWatcher('open', ctx, true)
			expect(ctx.from).toBe('')
			expect(ctx.to).toBe('')
			expect(ctx.error).toBeNull()
			expect(ctx.result).toBeNull()
		})

		it('leaves state alone when the dialog closes', () => {
			const ctx = { from: 'nl', to: 'en', error: 'oops', result: { translated: { x: 'y' } } }
			callWatcher('open', ctx, false)
			expect(ctx.from).toBe('nl')
			expect(ctx.to).toBe('en')
			expect(ctx.error).toBe('oops')
			expect(ctx.result.translated.x).toBe('y')
		})
	})

	describe('default data', () => {
		it('returns idle state', () => {
			expect(baseData()).toEqual({
				from: '',
				to: '',
				loading: false,
				error: null,
				result: null,
			})
		})
	})

	describe('onSubmit', () => {
		beforeEach(() => {
			jest.clearAllMocks()
		})

		const buildCtx = (overrides = {}) => {
			const emitted = []
			return {
				uuid: 'abc',
				from: 'nl',
				to: 'en',
				loading: false,
				error: null,
				result: null,
				canSubmit: true,
				$emit(name, payload) { emitted.push([name, payload]) },
				_emitted: emitted,
				...overrides,
			}
		}

		it('returns early if canSubmit is false', async () => {
			const bulkTranslate = jest.fn()
			useTranslationsStore.mockReturnValue({ bulkTranslate })
			const ctx = buildCtx({ canSubmit: false })

			await callMethod('onSubmit', ctx)

			expect(bulkTranslate).not.toHaveBeenCalled()
		})

		it('calls the store and emits translated on success', async () => {
			const payload = { translated: { title: 'Hello' }, skipped: {} }
			const bulkTranslate = jest.fn().mockResolvedValue(payload)
			useTranslationsStore.mockReturnValue({ bulkTranslate })
			const ctx = buildCtx()

			await callMethod('onSubmit', ctx)

			expect(bulkTranslate).toHaveBeenCalledWith('abc', 'nl', 'en')
			expect(ctx.result).toBe(payload)
			expect(ctx._emitted).toEqual([['translated', payload]])
			expect(ctx.loading).toBe(false)
			expect(ctx.error).toBeNull()
		})

		it('captures backend error.response.data.error first', async () => {
			const err = { response: { data: { error: 'no provider' } }, message: 'fallback' }
			const bulkTranslate = jest.fn().mockRejectedValue(err)
			useTranslationsStore.mockReturnValue({ bulkTranslate })
			const ctx = buildCtx()

			await callMethod('onSubmit', ctx)

			expect(ctx.error).toBe('no provider')
			expect(ctx.loading).toBe(false)
			expect(ctx.result).toBeNull()
		})

		it('falls back to error.message when no response payload', async () => {
			const bulkTranslate = jest.fn().mockRejectedValue(new Error('network'))
			useTranslationsStore.mockReturnValue({ bulkTranslate })
			const ctx = buildCtx()

			await callMethod('onSubmit', ctx)

			expect(ctx.error).toBe('network')
		})

		it('falls back to a default message when the error is opaque', async () => {
			const bulkTranslate = jest.fn().mockRejectedValue({})
			useTranslationsStore.mockReturnValue({ bulkTranslate })
			const ctx = buildCtx()

			await callMethod('onSubmit', ctx)

			expect(ctx.error).toBe('Translation failed')
		})
	})
})
