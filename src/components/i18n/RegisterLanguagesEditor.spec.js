import RegisterLanguagesEditor from './RegisterLanguagesEditor.vue'

/**
 * See TranslationStatusChip.spec.js for why we exercise the component
 * options object directly instead of mounting.
 * @param key
 * @param ctx
 */
const callComputed = (key, ctx) => RegisterLanguagesEditor.computed[key].call(ctx)
const callMethod = (key, ctx, ...args) => RegisterLanguagesEditor.methods[key].apply(ctx, args)

const buildCtx = (overrides = {}) => {
	const emitted = []
	return {
		value: [],
		disabled: false,
		draft: '',
		error: null,
		$emit(name, payload) { emitted.push([name, payload]) },
		_emitted: emitted,
		...overrides,
	}
}

describe('RegisterLanguagesEditor', () => {
	describe('normalizedDraft', () => {
		it('lowercases and trims whitespace', () => {
			expect(callComputed('normalizedDraft', { draft: '  EN-GB ' })).toBe('en-gb')
		})

		it('handles empty/missing input', () => {
			expect(callComputed('normalizedDraft', { draft: '' })).toBe('')
			expect(callComputed('normalizedDraft', { draft: null })).toBe('')
		})
	})

	describe('validateDraft', () => {
		it('returns null for valid BCP 47 tags', () => {
			const ctx = { value: [] }
			expect(callMethod('validateDraft', ctx, 'nl')).toBeNull()
			expect(callMethod('validateDraft', ctx, 'en')).toBeNull()
			expect(callMethod('validateDraft', ctx, 'ar-sa')).toBeNull()
			expect(callMethod('validateDraft', ctx, 'zh-hant-tw')).toBeNull()
		})

		it('rejects empty input', () => {
			expect(callMethod('validateDraft', { value: [] }, '')).toBe('empty')
		})

		it('rejects malformed tags', () => {
			const ctx = { value: [] }
			expect(callMethod('validateDraft', ctx, 'a')).toBe('invalid')
			expect(callMethod('validateDraft', ctx, '12')).toBe('invalid')
			expect(callMethod('validateDraft', ctx, 'nl_BE')).toBe('invalid')
			expect(callMethod('validateDraft', ctx, 'nl ')).toBe('invalid')
		})

		it('rejects duplicates case-insensitively', () => {
			const ctx = { value: ['nl', 'EN'] }
			expect(callMethod('validateDraft', ctx, 'nl')).toBe('duplicate')
			expect(callMethod('validateDraft', ctx, 'en')).toBe('duplicate')
		})
	})

	describe('canAdd', () => {
		it('is false when input is empty', () => {
			expect(callComputed('canAdd', buildCtx({ draft: '', normalizedDraft: '' }))).toBe(false)
		})

		it('is false when disabled', () => {
			const ctx = buildCtx({ draft: 'nl', value: [], normalizedDraft: 'nl', disabled: true })
			ctx.validateDraft = RegisterLanguagesEditor.methods.validateDraft
			expect(callComputed('canAdd', ctx)).toBe(false)
		})

		it('is true for valid new entries', () => {
			const ctx = buildCtx({ draft: 'nl', value: [], normalizedDraft: 'nl' })
			ctx.validateDraft = RegisterLanguagesEditor.methods.validateDraft
			expect(callComputed('canAdd', ctx)).toBe(true)
		})

		it('is false for duplicates', () => {
			const ctx = buildCtx({ draft: 'NL', value: ['nl'], normalizedDraft: 'nl' })
			ctx.validateDraft = RegisterLanguagesEditor.methods.validateDraft
			expect(callComputed('canAdd', ctx)).toBe(false)
		})
	})

	describe('addCurrent', () => {
		const buildAddCtx = (overrides = {}) => {
			const ctx = buildCtx(overrides)
			// Compute normalizedDraft on the fly using the actual computed.
			Object.defineProperty(ctx, 'normalizedDraft', {
				get() { return (this.draft || '').trim().toLowerCase() },
			})
			// Bind sibling methods used by addCurrent.
			ctx.validateDraft = RegisterLanguagesEditor.methods.validateDraft.bind(ctx)
			ctx.errorMessageFor = RegisterLanguagesEditor.methods.errorMessageFor.bind(ctx)
			return ctx
		}

		it('emits the appended list and clears the draft on success', () => {
			const ctx = buildAddCtx({ value: ['nl'], draft: 'EN' })
			callMethod('addCurrent', ctx)

			expect(ctx._emitted).toEqual([['input', ['nl', 'en']]])
			expect(ctx.draft).toBe('')
			expect(ctx.error).toBeNull()
		})

		it('does not emit when validation fails', () => {
			const ctx = buildAddCtx({ value: ['nl'], draft: 'NL' })
			callMethod('addCurrent', ctx)

			expect(ctx._emitted).toEqual([])
			expect(ctx.error).toMatch(/already in the list/)
			expect(ctx.draft).toBe('NL')
		})

		it('reports a clear error for malformed tags', () => {
			const ctx = buildAddCtx({ value: [], draft: 'nl_BE' })
			callMethod('addCurrent', ctx)

			expect(ctx._emitted).toEqual([])
			expect(ctx.error).toMatch(/valid BCP 47/)
		})

		it('reports a clear error for empty input', () => {
			const ctx = buildAddCtx({ value: [], draft: '   ' })
			callMethod('addCurrent', ctx)

			expect(ctx._emitted).toEqual([])
			expect(ctx.error).toMatch(/Enter a BCP 47/)
		})
	})

	describe('remove', () => {
		it('emits the list with the index removed', () => {
			const ctx = buildCtx({ value: ['nl', 'en', 'de'] })
			callMethod('remove', ctx, 1)
			expect(ctx._emitted).toEqual([['input', ['nl', 'de']]])
		})

		it('is a no-op when disabled', () => {
			const ctx = buildCtx({ value: ['nl', 'en'], disabled: true })
			callMethod('remove', ctx, 0)
			expect(ctx._emitted).toEqual([])
		})
	})

	describe('move', () => {
		it('reorders by splice and emits the new list', () => {
			const ctx = buildCtx({ value: ['nl', 'en', 'de'] })
			callMethod('move', ctx, 2, 0)
			expect(ctx._emitted).toEqual([['input', ['de', 'nl', 'en']]])
		})

		it('promotes a non-default language to first', () => {
			const ctx = buildCtx({ value: ['nl', 'en'] })
			callMethod('move', ctx, 1, 0)
			expect(ctx._emitted).toEqual([['input', ['en', 'nl']]])
		})

		it('ignores out-of-bounds moves', () => {
			const ctx = buildCtx({ value: ['nl', 'en'] })
			callMethod('move', ctx, 0, -1)
			callMethod('move', ctx, 0, 5)
			callMethod('move', ctx, -1, 0)
			expect(ctx._emitted).toEqual([])
		})

		it('is a no-op when disabled', () => {
			const ctx = buildCtx({ value: ['nl', 'en'], disabled: true })
			callMethod('move', ctx, 0, 1)
			expect(ctx._emitted).toEqual([])
		})
	})

	describe('errorMessageFor', () => {
		it('maps known reasons to readable messages', () => {
			const ctx = {}
			expect(callMethod('errorMessageFor', ctx, 'empty')).toMatch(/Enter a BCP 47/)
			expect(callMethod('errorMessageFor', ctx, 'invalid')).toMatch(/valid BCP 47/)
			expect(callMethod('errorMessageFor', ctx, 'duplicate')).toMatch(/already in the list/)
		})

		it('falls back for unknown reasons', () => {
			expect(callMethod('errorMessageFor', {}, 'mystery')).toBe('Invalid input.')
		})
	})
})
