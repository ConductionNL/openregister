// Stub Nextcloud's ESM-only component package so jest can require the .vue
// file — the same idiom as BrowserNotificationsSection.spec.js. Both
// `jest.mock` calls are hoisted above these imports by babel-jest.
import BulkTranslateDialog from './BulkTranslateDialog.vue'
import { useTranslationsStore } from '../../store/modules/translations.js'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: {},
	NcDialog: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))

jest.mock('../../store/modules/translations.js', () => ({
	useTranslationsStore: jest.fn(),
}))

/**
 * See TranslationStatusChip.spec.js for why we exercise the component
 * options object directly instead of mounting.
 *
 * @param {string} key The computed/method/watcher name.
 * @param {object} ctx The `this` context to bind.
 *
 * @return {*} The invocation result.
 */
const callComputed = (key, ctx) => BulkTranslateDialog.computed[key].call(ctx)
const callMethod = (key, ctx, ...args) =>
	BulkTranslateDialog.methods[key].apply(ctx, args)
const callWatcher = (key, ctx, value) =>
	BulkTranslateDialog.watch[key].call(ctx, value)

const baseData = () => BulkTranslateDialog.data()

describe('BulkTranslateDialog', () => {
	describe('canSubmit', () => {
		it('requires both languages to be set and to differ', () => {
			expect(
				callComputed('canSubmit', { loading: false, from: 'nl', to: 'en' }),
			).toBe(true)
			expect(
				callComputed('canSubmit', { loading: false, from: 'nl', to: 'nl' }),
			).toBe(false)
			expect(
				callComputed('canSubmit', { loading: false, from: '', to: 'en' }),
			).toBe(false)
			expect(
				callComputed('canSubmit', { loading: false, from: 'nl', to: '' }),
			).toBe(false)
		})

		it('blocks submission while loading', () => {
			expect(
				callComputed('canSubmit', { loading: true, from: 'nl', to: 'en' }),
			).toBe(false)
		})
	})

	describe('source / target NcSelect proxies', () => {
		it('presents an unset language as null, not the empty string', () => {
			expect(
				BulkTranslateDialog.computed.source.get.call({ from: '' }),
			).toBeNull()
			expect(
				BulkTranslateDialog.computed.source.get.call({ from: 'nl' }),
			).toBe('nl')
			expect(
				BulkTranslateDialog.computed.target.get.call({ to: '' }),
			).toBeNull()
			expect(BulkTranslateDialog.computed.target.get.call({ to: 'en' })).toBe(
				'en',
			)
		})

		it('normalises a cleared selection back to the empty string', () => {
			// NcSelect emits null when the user clears the combobox. Without the
			// proxy that null reaches onSubmit and the API receives "null" as a
			// language code, so this is the assertion that matters.
			const ctx = { from: 'nl' }
			BulkTranslateDialog.computed.source.set.call(ctx, null)
			expect(ctx.from).toBe('')

			BulkTranslateDialog.computed.source.set.call(ctx, 'de')
			expect(ctx.from).toBe('de')

			const targetCtx = { to: 'en' }
			BulkTranslateDialog.computed.target.set.call(targetCtx, undefined)
			expect(targetCtx.to).toBe('')
		})

		it('canSubmit stays false for a cleared selection', () => {
			const ctx = { from: 'nl', to: 'en', loading: false }
			BulkTranslateDialog.computed.target.set.call(ctx, null)
			expect(callComputed('canSubmit', ctx)).toBe(false)
		})
	})

	describe('sameLanguage', () => {
		it('warns only once a source is chosen and repeated', () => {
			expect(callComputed('sameLanguage', { from: 'nl', to: 'nl' })).toBe(true)
			expect(callComputed('sameLanguage', { from: 'nl', to: 'en' })).toBe(
				false,
			)
			expect(callComputed('sameLanguage', { from: '', to: '' })).toBe(false)
		})
	})

	describe('isSelectableTarget', () => {
		it('removes the source language from the target list', () => {
			expect(callMethod('isSelectableTarget', { from: 'nl' }, 'en')).toBe(true)
			expect(callMethod('isSelectableTarget', { from: 'nl' }, 'nl')).toBe(
				false,
			)
		})

		it('offers every language while no source is chosen', () => {
			expect(callMethod('isSelectableTarget', { from: '' }, 'nl')).toBe(true)
		})
	})

	describe('hasTranslated / hasSkipped', () => {
		it('reports translated/skipped presence', () => {
			expect(
				callComputed('hasTranslated', {
					result: { translated: { title: 'Hi' } },
				}),
			).toBe(true)
			expect(
				callComputed('hasTranslated', { result: { translated: {} } }),
			).toBe(false)
			expect(callComputed('hasTranslated', { result: null })).toBeFalsy()

			expect(
				callComputed('hasSkipped', { result: { skipped: { x: 'reason' } } }),
			).toBe(true)
			expect(callComputed('hasSkipped', { result: { skipped: {} } })).toBe(
				false,
			)
			expect(callComputed('hasSkipped', { result: null })).toBeFalsy()
		})
	})

	describe('open watcher', () => {
		it('resets state when the dialog opens', () => {
			const ctx = {
				from: 'nl',
				to: 'en',
				error: 'old',
				result: { translated: {} },
			}
			callWatcher('open', ctx, true)
			expect(ctx.from).toBe('')
			expect(ctx.to).toBe('')
			expect(ctx.error).toBeNull()
			expect(ctx.result).toBeNull()
		})

		it('leaves state alone when the dialog closes', () => {
			const ctx = {
				from: 'nl',
				to: 'en',
				error: 'oops',
				result: { translated: { x: 'y' } },
			}
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
				$emit(name, payload) {
					emitted.push([name, payload])
				},
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
			const err = {
				response: { data: { error: 'no provider' } },
				message: 'fallback',
			}
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
