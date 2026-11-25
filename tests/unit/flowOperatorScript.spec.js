/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * js/openregister-flow-operator.js registers the value input of the
 * "Run an OpenRegister flow" Nextcloud Flow operation. It is a hand-written
 * static script (no build step), so nothing else would notice when it stops
 * registering, registers under an id the PHP operation does not answer to, or
 * stops passing the typed flow name back to the rule builder.
 *
 * @spec openspec/changes/visual-flow-builder/tasks.md
 */

import * as fs from 'fs'
import * as path from 'path'

const ROOT = path.resolve(__dirname, '../..')
const SOURCE = fs.readFileSync(path.join(ROOT, 'js/openregister-flow-operator.js'), 'utf8')
const PHP_INTERFACE = fs.readFileSync(path.join(ROOT, 'lib/WorkflowEngine/RunFlowOperationInterface.php'), 'utf8')

/**
 * Run the script the way the browser does: once, against the current window.
 */
function loadScript() {
	new Function(SOURCE)()
}

/**
 * Render the registered options component with a recording createElement.
 *
 * @param {object} options The `options` component the script registered.
 * @param {string} value The current operation value.
 * @return {{tag: string, data: object, emitted: Array}} What was rendered and emitted.
 */
function render(options, value) {
	const emitted = []
	const self = { value, $emit: (...args) => emitted.push(args) }
	let tag = null
	let data = null
	options.render.call(self, (t, d) => {
		tag = t
		data = d
		return { tag: t }
	})
	return { tag, data, emitted }
}

describe('openregister-flow-operator.js', () => {
	let registerOperator

	beforeEach(() => {
		jest.useFakeTimers()
		registerOperator = jest.fn()
		global.t = jest.fn((app, text) => `[${app}] ${text}`)
		delete window.OCA
	})

	afterEach(() => {
		jest.clearAllTimers()
		jest.useRealTimers()
		delete window.OCA
		delete global.t
	})

	it('registers once, under the id of the PHP operation, when the Flow UI is already loaded', () => {
		window.OCA = { WorkflowEngine: { registerOperator } }

		loadScript()
		jest.advanceTimersByTime(10000)

		expect(registerOperator).toHaveBeenCalledTimes(1)
		const operator = registerOperator.mock.calls[0][0]
		expect(operator.id).toBe('OCA\\OpenRegister\\WorkflowEngine\\RunFlowOperation')
		expect(operator.operation).toBe('')
		expect(operator.options.name).toBe('OpenRegisterRunFlowOperation')
	})

	it('uses the operation id the PHP interface declares', () => {
		// OPERATION_ID = RunFlowOperation::class in the same namespace.
		expect(PHP_INTERFACE).toMatch(/namespace OCA\\OpenRegister\\WorkflowEngine;/)
		expect(PHP_INTERFACE).toMatch(/OPERATION_ID = RunFlowOperation::class;/)
		expect(SOURCE).toContain("id: 'OCA\\\\OpenRegister\\\\WorkflowEngine\\\\RunFlowOperation'")
	})

	it('waits for the Flow UI to load and then registers exactly once', () => {
		loadScript()
		jest.advanceTimersByTime(300)
		expect(registerOperator).not.toHaveBeenCalled()

		window.OCA = { WorkflowEngine: { registerOperator } }
		jest.advanceTimersByTime(100)
		expect(registerOperator).toHaveBeenCalledTimes(1)

		jest.advanceTimersByTime(10000)
		expect(registerOperator).toHaveBeenCalledTimes(1)
	})

	it('stops retrying when the Flow UI never loads', () => {
		window.OCA = { WorkflowEngine: {} }

		expect(() => loadScript()).not.toThrow()
		jest.advanceTimersByTime(60000)

		expect(jest.getTimerCount()).toBe(0)
		window.OCA.WorkflowEngine.registerOperator = registerOperator
		jest.advanceTimersByTime(1000)
		expect(registerOperator).not.toHaveBeenCalled()
	})

	it('renders a text input that shows the current flow name, translated', () => {
		window.OCA = { WorkflowEngine: { registerOperator } }
		loadScript()
		const { options } = registerOperator.mock.calls[0][0]

		const { tag, data } = render(options, 'Stamp the title')

		expect(tag).toBe('input')
		expect(data.attrs.type).toBe('text')
		expect(data.domProps.value).toBe('Stamp the title')
		expect(data.attrs.placeholder).toBe('[openregister] Name of the OpenRegister flow to run')
		expect(data.attrs['aria-label']).toBe('[openregister] OpenRegister flow name')
		expect(options.props.value).toEqual({ type: String, default: '' })
	})

	it('hands the typed flow name back to the rule builder as the operation value', () => {
		window.OCA = { WorkflowEngine: { registerOperator } }
		loadScript()
		const { options } = registerOperator.mock.calls[0][0]

		const { data, emitted } = render(options, '')
		data.on.input({ target: { value: 'Archive case' } })

		expect(emitted).toEqual([['input', 'Archive case']])
	})
})
