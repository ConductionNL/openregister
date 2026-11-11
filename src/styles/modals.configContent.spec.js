/**
 * @jest-environment node
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The config-content mixin emits exactly the scrolling content rule the file,
 * object, LLM and file vectorization settings modals used to spell out by hand.
 *
 * @spec exclude Unit test of a shared modal style mixin.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { compileString } from 'sass'

/**
 * Compile a stylesheet, compressed.
 *
 * @param {string} scss The SCSS
 * @return {string} The compressed CSS
 */
function compile(scss) {
	return compileString(scss, { loadPaths: [__dirname], style: 'compressed' }).css
}

describe('the config-content mixin', () => {
	it.each(['file-config', 'object-config', 'llm-config', 'vectorization-config'])(
		'emits the hand-written rule for .%s-content',
		(name) => {
			expect(
				compile(`@use 'modals.scss' as *;\n@include config-content('${name}');`),
			).toBe(
				compile(`.${name}-content {
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}`),
			)
		},
	)
})
