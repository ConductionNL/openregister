/**
 * Golden record of useFileSelection's drop handling: the file list it keeps
 * after each drop, how each kept file carries its tags and status, and the
 * callbacks it fires, for single and multiple selection, FileList and array
 * input, and drops onto an empty or a filled list.
 */
import { useDropZone, useFileDialog } from '@vueuse/core'
import { toRaw } from 'vue'
import { useFileSelection } from './UseFileSelection.js'

jest.mock('@vueuse/core', () => ({
	__esModule: true,
	useDropZone: jest.fn(() => ({ isOverDropZone: false })),
	useFileDialog: jest.fn(),
}))

jest.mock('../store/store.js', () => ({
	__esModule: true,
	objectStore: { setAttachmentFile: jest.fn() },
}))

let dialogChange

beforeEach(() => {
	jest.clearAllMocks()
	useFileDialog.mockImplementation(() => ({
		onChange: (handler) => {
			dialogChange = handler
		},
		open: jest.fn(),
	}))
})

/**
 * Make a plain File with a fixed modification time.
 *
 * @param {string} name The file name.
 * @return {File} The file.
 */
function file(name) {
	return new File(['data-' + name], name, { type: 'text/plain', lastModified: 42 })
}

/**
 * Make an array-like that passes `instanceof FileList`.
 *
 * @param {Array<File>} files The files.
 * @return {FileList} The fake list.
 */
function fileList(files) {
	const list = { length: files.length }
	files.forEach((f, i) => {
		list[i] = f
	})
	return Object.setPrototypeOf(list, FileList.prototype)
}

/**
 * Describe one kept file, including how its tags and status were attached.
 *
 * @param {File} kept The kept file.
 * @param {Array<File>} inputs Files passed in, to detect copies.
 * @return {object} The description.
 */
function describeFile(kept, inputs) {
	const descriptor = (key) => {
		const d = Object.getOwnPropertyDescriptor(kept, key)
		return d
			? {
					value: d.value,
					writable: d.writable,
					enumerable: d.enumerable,
					configurable: d.configurable,
				}
			: null
	}
	return {
		name: kept.name,
		type: kept.type,
		size: kept.size,
		lastModified: kept.lastModified,
		isFile: kept instanceof File,
		inputIndex: inputs.indexOf(kept),
		tags: descriptor('tags'),
		status: descriptor('status'),
	}
}

/**
 * Run a sequence of drops and record the result after each.
 *
 * @param {object} options useFileSelection options.
 * @param {Array<Function>} drops Functions returning the drop input.
 * @return {Array<object>} The record per drop.
 */
function run(options, drops) {
	const onFileDrop = jest.fn()
	const onFileSelect = jest.fn()
	const selection = useFileSelection({
		dropzone: {},
		onFileDrop,
		onFileSelect,
		...options,
	})
	const onDrop = useDropZone.mock.calls[0][1].onDrop
	const inputs = []
	return drops.map(({ input, tags, viaDialog }) => {
		if (tags) {
			selection.setTags(tags)
		}
		const given = input()
		inputs.push(...Array.from(given || []))
		const before = toRaw(selection.files.value)
		;(viaDialog ? dialogChange : onDrop)(given)
		const files = toRaw(selection.files.value)
		return {
			files:
				files === null
					? null
					: files.map((f) => ({
							...describeFile(f, inputs),
							keptFromBefore: (before || []).indexOf(f),
						})),
			sameListAsBefore: files === before,
			sameArrayAsInput: files === given,
			onFileDrop: onFileDrop.mock.calls.length,
			onFileSelect: onFileSelect.mock.calls.length,
		}
	})
}

describe('useFileSelection onDrop golden', () => {
	it.each([
		[
			'single, array, two files',
			{ allowMultiple: false },
			[{ input: () => [file('a'), file('b')], tags: ['t'] }],
		],
		[
			'single, FileList, two files',
			{ allowMultiple: false },
			[{ input: () => fileList([file('a'), file('b')]), tags: ['t'] }],
		],
		[
			'single, then a second drop',
			{ allowMultiple: false },
			[{ input: () => [file('a')] }, { input: () => fileList([file('b')]) }],
		],
		[
			'multiple, array onto empty',
			{ allowMultiple: true },
			[{ input: () => [file('a'), file('b')], tags: ['x'] }],
		],
		[
			'multiple, FileList onto empty',
			{ allowMultiple: true },
			[{ input: () => fileList([file('a')]), tags: ['y'] }],
		],
		[
			'multiple, drops onto a filled list with a duplicate name',
			{ allowMultiple: true },
			[
				{ input: () => [file('a')], tags: ['one'] },
				{ input: () => [file('a'), file('b')], tags: ['two'] },
				{ input: () => fileList([file('c'), file('b')]), viaDialog: true },
			],
		],
		[
			'multiple, all duplicates',
			{ allowMultiple: true },
			[{ input: () => [file('a')] }, { input: () => [file('a')] }],
		],
		[
			'empty and missing input',
			{ allowMultiple: true },
			[
				{ input: () => [] },
				{ input: () => null },
				{ input: () => fileList([]) },
			],
		],
		[
			'allowMultiple undefined',
			{},
			[
				{ input: () => [file('a'), file('b')] },
				{ input: () => fileList([file('c')]) },
			],
		],
	])('%s', (_label, options, drops) => {
		expect(run(options, drops)).toMatchSnapshot()
	})

	it('works without callbacks', () => {
		const selection = useFileSelection({ dropzone: {}, allowMultiple: true })
		useDropZone.mock.calls[0][1].onDrop([file('a')])
		expect(selection.files.value.map((f) => f.name)).toEqual(['a'])
	})
})
