import TranslationCompletenessBadge from './TranslationCompletenessBadge.vue'

/**
 * See TranslationStatusChip.spec.js for why we exercise the component
 * options object directly instead of mounting.
 * @param key
 * @param ctx
 */
const callComputed = (key, ctx) => TranslationCompletenessBadge.computed[key].call(ctx)
const callMethod = (key, ctx, ...args) => TranslationCompletenessBadge.methods[key].apply(ctx, args)

const completeness = {
	nl: { translated: 4, total: 4, ratio: 1 },
	en: { translated: 2, total: 4, ratio: 0.5 },
	de: { translated: 0, total: 4, ratio: 0 },
}

describe('TranslationCompletenessBadge', () => {
	describe('languages computed', () => {
		it('honours an explicit languageOrder', () => {
			const ctx = { completeness, languageOrder: ['en', 'nl', 'de'] }
			expect(callComputed('languages', ctx)).toEqual(['en', 'nl', 'de'])
		})

		it('appends extras alphabetically after the explicit order', () => {
			const ctx = { completeness, languageOrder: ['nl'] }
			expect(callComputed('languages', ctx)).toEqual(['nl', 'de', 'en'])
		})

		it('falls back to alphabetical when languageOrder is empty', () => {
			const ctx = { completeness, languageOrder: [] }
			expect(callComputed('languages', ctx)).toEqual(['de', 'en', 'nl'])
		})

		it('skips languages from languageOrder that are not in the completeness payload', () => {
			const ctx = { completeness: { en: { translated: 1, total: 1, ratio: 1 } }, languageOrder: ['nl', 'en'] }
			expect(callComputed('languages', ctx)).toEqual(['en'])
		})
	})

	describe('ratioPercent', () => {
		it('rounds to whole numbers', () => {
			const ctx = { completeness }
			expect(callMethod('ratioPercent', ctx, 'nl')).toBe('100%')
			expect(callMethod('ratioPercent', ctx, 'en')).toBe('50%')
			expect(callMethod('ratioPercent', ctx, 'de')).toBe('0%')
		})

		it('returns ? when ratio is missing or non-numeric', () => {
			const ctx = { completeness: { nl: { translated: 1, total: 1 } } }
			expect(callMethod('ratioPercent', ctx, 'nl')).toBe('?')
		})

		it('returns ? for unknown languages', () => {
			const ctx = { completeness }
			expect(callMethod('ratioPercent', ctx, 'fr')).toBe('?')
		})
	})

	describe('completenessClassFor', () => {
		it('buckets ratios into complete / partial / low', () => {
			const ctx = { completeness }
			expect(callMethod('completenessClassFor', ctx, 'nl')).toBe('translation-completeness-badge__pill--complete')
			expect(callMethod('completenessClassFor', ctx, 'en')).toBe('translation-completeness-badge__pill--partial')
			expect(callMethod('completenessClassFor', ctx, 'de')).toBe('translation-completeness-badge__pill--low')
		})

		it('treats missing entries as low', () => {
			const ctx = { completeness }
			expect(callMethod('completenessClassFor', ctx, 'fr')).toBe('translation-completeness-badge__pill--low')
		})
	})

	describe('tooltipText', () => {
		it('produces a readable summary across languages', () => {
			const ctx = {
				completeness,
				languages: ['nl', 'en', 'de'],
			}
			expect(callComputed('tooltipText', ctx))
				.toBe('Translation completeness — NL: 4/4, EN: 2/4, DE: 0/4')
		})
	})
})
