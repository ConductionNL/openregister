import { registerTranslations } from '@conduction/nextcloud-vue'
import { registerLibraryTranslations } from './libraryTranslations.js'

jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	registerTranslations: jest.fn(),
}))

describe('registerLibraryTranslations', () => {
	let warnSpy

	beforeEach(() => {
		jest.clearAllMocks()
		warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		warnSpy.mockRestore()
	})

	it('registers the library translations once without warning', () => {
		registerLibraryTranslations()
		expect(registerTranslations).toHaveBeenCalledTimes(1)
		expect(warnSpy).not.toHaveBeenCalled()
	})

	it('keeps booting and warns when the library registration throws', () => {
		const err = new Error('no l10n')
		registerTranslations.mockImplementation(() => {
			throw err
		})
		expect(() => registerLibraryTranslations()).not.toThrow()
		expect(warnSpy).toHaveBeenCalledWith(
			'[openregister] registerTranslations failed; library labels stay English',
			err,
		)
	})
})
