/**
 * The editor the record form gives each declared field (REQ-RFCE-001), and
 * which fields the records list may edit in place (REQ-RFCE-002).
 */
import {
	editorFor,
	enumOptions,
	isInlineEditable,
	readFileAsDataUri,
} from './propertyEditor.js'

describe('editorFor', () => {
	it('gives an enum field a choice list', () => {
		expect(editorFor({ type: 'string', enum: ['open', 'closed'] })).toBe('select')
	})

	it('gives a oneOf of constants a choice list', () => {
		expect(
			editorFor({ type: 'string', oneOf: [{ const: 'a', title: 'A' }, { const: 'b' }] }),
		).toBe('select')
	})

	it('gives a file property a file picker', () => {
		expect(editorFor({ type: 'file' })).toBe('file')
	})

	it('gives a translatable property one input per register language', () => {
		expect(editorFor({ type: 'string', translatable: true }, ['nl', 'en'])).toBe('translation')
	})

	it('keeps a translatable property a text field when the register declares no languages', () => {
		expect(editorFor({ type: 'string', translatable: true }, [])).toBe('text')
	})

	it('keeps booleans, dates and plain text as before', () => {
		expect(editorFor({ type: 'boolean' })).toBe('switch')
		expect(editorFor({ type: 'string', format: 'date' })).toBe('date')
		expect(editorFor({ type: 'string' })).toBe('text')
		expect(editorFor({ type: 'integer' })).toBe('text')
		expect(editorFor(undefined)).toBe('text')
	})
})

describe('enumOptions', () => {
	it('offers the declared enum values', () => {
		expect(enumOptions({ enum: ['open', 'closed'] })).toEqual([
			{ value: 'open', label: 'open' },
			{ value: 'closed', label: 'closed' },
		])
	})

	it('labels a oneOf constant by its title', () => {
		expect(enumOptions({ oneOf: [{ const: 'a', title: 'Alpha' }, { const: 'b' }] })).toEqual([
			{ value: 'a', label: 'Alpha' },
			{ value: 'b', label: 'b' },
		])
	})

	it('offers nothing for a free field', () => {
		expect(enumOptions({ type: 'string' })).toEqual([])
	})
})

describe('isInlineEditable', () => {
	it('allows scalar text, number and enum fields', () => {
		expect(isInlineEditable({ type: 'string' })).toBe(true)
		expect(isInlineEditable({ type: 'number' })).toBe(true)
		expect(isInlineEditable({ type: 'string', enum: ['a'] })).toBe(true)
	})

	it('refuses files, translations, objects, constants and read-only fields', () => {
		expect(isInlineEditable({ type: 'file' })).toBe(false)
		expect(isInlineEditable({ type: 'string', translatable: true })).toBe(false)
		expect(isInlineEditable({ type: 'object' })).toBe(false)
		expect(isInlineEditable({ type: 'array' })).toBe(false)
		expect(isInlineEditable({ type: 'string', const: 'x' })).toBe(false)
		expect(isInlineEditable({ type: 'string', readOnly: true })).toBe(false)
		expect(isInlineEditable({ type: 'string', format: 'date-time' })).toBe(false)
		expect(isInlineEditable(undefined)).toBe(false)
	})

	it('refuses an immutable field that already holds a value', () => {
		expect(isInlineEditable({ type: 'string', immutable: true }, 'Z-1')).toBe(false)
		expect(isInlineEditable({ type: 'string', immutable: true }, '')).toBe(true)
	})
})

describe('readFileAsDataUri', () => {
	it('reads the chosen file as the data URI the save path accepts', async () => {
		const file = new File(['hello'], 'hello.txt', { type: 'text/plain' })
		await expect(readFileAsDataUri(file)).resolves.toBe('data:text/plain;base64,aGVsbG8=')
	})
})
