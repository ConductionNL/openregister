/**
 * The connection-test icon renders what each of the file settings' test
 * buttons rendered inline before: the same icon for every combination of
 * answer and running state, in both spinner orders.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a presentational icon.
 */
import { mount } from '@vue/test-utils'
import ConnectionTestIcon from './ConnectionTestIcon.vue'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcLoadingIcon: mockIcon('NcLoadingIcon'),
}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({
	__esModule: true,
	default: mockIcon('AlertCircleIcon'),
}))
jest.mock('vue-material-design-icons/Check.vue', () => ({
	__esModule: true,
	default: mockIcon('CheckIcon'),
}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({
	__esModule: true,
	default: mockIcon('RefreshIcon'),
}))

/**
 * A stand-in icon that renders its name and size.
 *
 * @param {string} name The icon name
 * @return {object} The stub
 */
function mockIcon(name) {
	return {
		name,
		props: { size: { type: Number, default: 0 } },
		template: `<i data-icon="${name}" :data-size="size" />`,
	}
}

const icons = {
	NcLoadingIcon: mockIcon('NcLoadingIcon'),
	AlertCircleIcon: mockIcon('AlertCircleIcon'),
	CheckIcon: mockIcon('CheckIcon'),
	RefreshIcon: mockIcon('RefreshIcon'),
}

// The inline markup the buttons carried before, spinner first ...
const SPINNER_FIRST = `
	<NcLoadingIcon v-if="testing" :size="20" />
	<CheckIcon v-else-if="result === 'success'" :size="20" />
	<AlertCircleIcon v-else-if="result === 'error'" :size="20" />
	<RefreshIcon v-else :size="20" />`
// ... answer first ...
const ANSWER_FIRST = `
	<CheckIcon v-if="result === 'success'" :size="20" />
	<AlertCircleIcon v-else-if="result === 'error'" :size="20" />
	<NcLoadingIcon v-else-if="testing" :size="20" />
	<RefreshIcon v-else :size="20" />`
// ... and without a spinner at all.
const NO_SPINNER = `
	<CheckIcon v-if="result === 'success'" :size="20" />
	<AlertCircleIcon v-else-if="result === 'error'" :size="20" />
	<RefreshIcon v-else :size="20" />`

/**
 * The markup of one rendering, without comment nodes.
 *
 * @param {object} component The component to mount
 * @param {object} props Its props
 * @return {string} The markup
 */
function render(component, props) {
	return mount(component, { props }).html().replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * A component with the old inline markup.
 *
 * @param {string} template The old markup
 * @return {object} The component
 */
function inline(template) {
	return {
		components: icons,
		props: { result: { type: String, default: null }, testing: Boolean },
		template,
	}
}

const RESULTS = [null, undefined, '', 'success', 'error']

describe('ConnectionTestIcon', () => {
	for (const result of RESULTS) {
		for (const testing of [false, true]) {
			it(`renders as before for result ${result}, testing ${testing}`, () => {
				expect(
					render(ConnectionTestIcon, { result, testing, testingFirst: true }),
				).toBe(render(inline(SPINNER_FIRST), { result, testing }))
				expect(render(ConnectionTestIcon, { result, testing })).toBe(
					render(inline(ANSWER_FIRST), { result, testing }),
				)
			})
		}
		it(`renders as before without a spinner for result ${result}`, () => {
			expect(render(ConnectionTestIcon, { result })).toBe(
				render(inline(NO_SPINNER), { result }),
			)
		})
	}

	it('shows each icon at size 20', () => {
		expect(render(ConnectionTestIcon, { testing: true })).toBe(
			'<i data-icon="NcLoadingIcon" data-size="20"></i>',
		)
		expect(render(ConnectionTestIcon, { result: 'success' })).toBe(
			'<i data-icon="CheckIcon" data-size="20"></i>',
		)
		expect(render(ConnectionTestIcon, { result: 'error' })).toBe(
			'<i data-icon="AlertCircleIcon" data-size="20"></i>',
		)
		expect(render(ConnectionTestIcon, {})).toBe(
			'<i data-icon="RefreshIcon" data-size="20"></i>',
		)
	})
})
