import { registerTranslations } from '@conduction/nextcloud-vue/dist/esm/l10n/index.js'
import { getLanguage, register } from '@nextcloud/l10n'
import fs from 'fs'
import path from 'path'
import {
	LIBRARY_CATALOGUES,
	libraryPluralEntries,
	loadLibraryTranslations,
	registerLibraryTranslations,
} from './libraryTranslations.js'

jest.mock('@conduction/nextcloud-vue/dist/esm/l10n/index.js', () => ({
	__esModule: true,
	registerTranslations: jest.fn(),
}))

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	getLanguage: jest.fn(() => 'en'),
	register: jest.fn(),
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

const ENGLISH = {
	translations: { Save: 'Save', 'Task deadline': 'Deadline' },
	plurals: { '{count} member': ['{count} member', '{count} members'] },
}
const DUTCH = {
	translations: { Save: 'Opslaan' },
	plurals: { '{count} member': ['{count} lid', '{count} leden'] },
}

describe('loadLibraryTranslations', () => {
	let warnSpy

	beforeEach(() => {
		jest.clearAllMocks()
		warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		warnSpy.mockRestore()
	})

	it('fetches only the reader\'s catalogue and the English one, and registers the reader\'s with plural keys', async () => {
		getLanguage.mockReturnValue('nl_NL')
		const load = jest.fn((lang) => Promise.resolve(lang === 'nl' ? DUTCH : ENGLISH))

		await loadLibraryTranslations(load)

		expect(load.mock.calls.map(([lang]) => lang).sort()).toEqual(['en', 'nl'])
		expect(register).toHaveBeenCalledTimes(1)
		expect(register).toHaveBeenCalledWith('nextcloud-vue', {
			Save: 'Opslaan',
			'_{count} member_::_{count} members_': ['{count} lid', '{count} leden'],
		})
		expect(registerTranslations).not.toHaveBeenCalled()
	})

	it('fetches the English catalogue once for an English reader', async () => {
		getLanguage.mockReturnValue('en')
		const load = jest.fn(() => Promise.resolve(ENGLISH))

		await loadLibraryTranslations(load)

		expect(load).toHaveBeenCalledTimes(1)
		expect(register).toHaveBeenCalledWith('nextcloud-vue', expect.objectContaining({ 'Task deadline': 'Deadline' }))
	})

	it('falls back to English for a language the library does not ship', async () => {
		getLanguage.mockReturnValue('xx')
		const load = jest.fn((lang) => (lang === 'en' ? Promise.resolve(ENGLISH) : Promise.reject(new Error('none'))))

		await loadLibraryTranslations(load)

		expect(register).toHaveBeenCalledWith('nextcloud-vue', expect.objectContaining({ 'Task deadline': 'Deadline' }))
		expect(warnSpy).not.toHaveBeenCalled()
	})

	it('never rejects and warns when the English catalogue cannot be fetched', async () => {
		getLanguage.mockReturnValue('nl')
		const err = new Error('offline')
		const load = jest.fn(() => Promise.reject(err))

		await expect(loadLibraryTranslations(load)).resolves.toBeUndefined()

		expect(register).not.toHaveBeenCalled()
		expect(warnSpy).toHaveBeenCalledWith(
			'[openregister] loading the library translations failed; library labels stay English',
			err,
		)
	})
})

describe('libraryPluralEntries', () => {
	it('skips a plural with no English counterpart or no forms', () => {
		const bundle = { plurals: { '{count} member': ['a', 'b'], orphan: ['x', 'y'], broken: 'x' } }
		expect(libraryPluralEntries(bundle, { plurals: { ...ENGLISH.plurals, broken: ['b', 'bs'] } })).toEqual({
			'_{count} member_::_{count} members_': ['a', 'b'],
		})
	})

	it('returns nothing for a catalogue without plurals', () => {
		expect(libraryPluralEntries({}, ENGLISH)).toEqual({})
	})
})

describe('LIBRARY_CATALOGUES', () => {
	it('names exactly the catalogues the installed library ships', () => {
		const dir = path.join(path.dirname(require.resolve('@conduction/nextcloud-vue/package.json')), 'l10n')
		const shipped = fs.readdirSync(dir)
			.filter((file) => file.endsWith('.json'))
			.map((file) => file.replace(/\.json$/, ''))
			.sort()
		expect(Object.keys(LIBRARY_CATALOGUES).sort()).toEqual(shipped)
	})
})
