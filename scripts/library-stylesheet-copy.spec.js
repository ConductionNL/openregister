/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The library stylesheet copy writes the sheet css/index.css stands for, and
 * the page links it instead of the main bundle importing it.
 *
 * @spec exclude Unit test of a build tooling script.
 */
const fs = require('fs')
const os = require('os')
const path = require('path')
const LibraryStylesheetCopy = require('./library-stylesheet-copy.js')
const { librarySheet } = LibraryStylesheetCopy

describe('library stylesheet copy', () => {
	let dir

	beforeEach(() => {
		dir = fs.mkdtempSync(path.join(os.tmpdir(), 'lib-css-'))
		fs.mkdirSync(path.join(dir, 'css'))
		fs.mkdirSync(path.join(dir, 'dist'))
		fs.writeFileSync(path.join(dir, 'dist', 'nextcloud-vue.css'), '.cn-a{color:red}')
	})

	afterEach(() => {
		fs.rmSync(dir, { recursive: true, force: true })
	})

	it('follows the @import of the entry sheet', () => {
		fs.writeFileSync(path.join(dir, 'css', 'index.css'), "/* entry */\n@import '../dist/nextcloud-vue.css';\n")
		expect(librarySheet(path.join(dir, 'css', 'index.css'))).toBe(path.join(dir, 'dist', 'nextcloud-vue.css'))
		fs.writeFileSync(path.join(dir, 'css', 'index.css'), '@import url("../dist/nextcloud-vue.css");\n')
		expect(librarySheet(path.join(dir, 'css', 'index.css'))).toBe(path.join(dir, 'dist', 'nextcloud-vue.css'))
	})

	it('takes the entry itself when it imports nothing', () => {
		fs.writeFileSync(path.join(dir, 'css', 'index.css'), '.cn-b{color:blue}')
		expect(librarySheet(path.join(dir, 'css', 'index.css'))).toBe(path.join(dir, 'css', 'index.css'))
	})

	it('writes the sheet to the target after webpack emits', () => {
		fs.writeFileSync(path.join(dir, 'css', 'index.css'), "@import '../dist/nextcloud-vue.css';")
		const target = path.join(dir, 'out', 'css', 'nextcloud-vue.css')
		const plugin = new LibraryStylesheetCopy({ target, resolve: () => path.join(dir, 'css', 'index.css') })
		let hook
		plugin.apply({ hooks: { afterEmit: { tap: (name, fn) => { hook = fn } } } })
		expect(fs.existsSync(target)).toBe(false)
		hook()
		expect(fs.readFileSync(target, 'utf8')).toBe('.cn-a{color:red}')
	})

	it('copies the stylesheet the installed library ships', () => {
		const target = path.join(dir, 'nextcloud-vue.css')
		// Jest maps .css requests to a stub, so name the installed file directly.
		const entry = path.join(__dirname, '..', 'node_modules', '@conduction', 'nextcloud-vue', 'css', 'index.css')
		new LibraryStylesheetCopy({ target, resolve: () => entry }).copy()
		expect(fs.statSync(target).size).toBeGreaterThan(100000)
	})
})

describe('the OpenRegister page and its main bundle', () => {
	const root = path.join(__dirname, '..')

	it('links the library stylesheet before the app stylesheet', () => {
		const template = fs.readFileSync(path.join(root, 'templates', 'index.php'), 'utf8')
		const library = template.indexOf("Util::addStyle($appId, 'nextcloud-vue')")
		expect(library).toBeGreaterThan(-1)
		expect(library).toBeLessThan(template.indexOf("Util::addStyle($appId, 'main')"))
	})

	it('does not insert the library stylesheet from the main bundle', () => {
		const main = fs.readFileSync(path.join(root, 'src', 'main.js'), 'utf8')
		expect(main).not.toMatch(/import\s+['"]@conduction\/nextcloud-vue\/css\/index\.css['"]/)
	})

	it('keeps the copied sheet out of git', () => {
		expect(fs.readFileSync(path.join(root, '.gitignore'), 'utf8')).toMatch(/^\/css\/nextcloud-vue\.css$/m)
	})
})
