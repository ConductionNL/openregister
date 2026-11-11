/**
 * @jest-environment node
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The divided-sections mixin emits exactly the section rules the deleted,
 * audit trail and search trail sidebars used to spell out by hand.
 *
 * @spec exclude Unit test of a shared sidebar style mixin.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { compileString } from 'sass'

/**
 * Compile a stylesheet that uses the sidebar partial.
 *
 * @param {string} body The SCSS after the partial is loaded
 * @return {string} The compressed CSS
 */
function compile(body) {
	return compileString(`@use 'sidebars.scss' as *;\n${body}`, {
		loadPaths: [__dirname],
		style: 'compressed',
	}).css
}

/**
 * The hand-written rules a sidebar carried before the mixin, for the given
 * section classes.
 *
 * @param {Array<string>} sections The section classes
 * @return {string} The compressed CSS
 */
function handWritten(sections) {
	const list = (suffix) => sections.map((s) => `.${s}${suffix}`).join(',\n')
	return compileString(
		`${list('')} {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

${list(':last-child')} {
	border-bottom: none;
}

${list(' h3')} {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}
`,
		{ style: 'compressed' },
	).css
}

describe('divided-sections mixin', () => {
	it('matches the deleted and audit trail sidebar rules', () => {
		expect(
			compile('@include divided-sections(filterSection, statsSection);'),
		).toBe(handWritten(['filterSection', 'statsSection']))
	})

	it('matches the search trail sidebar rules, analytics included', () => {
		expect(
			compile(
				'@include divided-sections(filterSection, statsSection, analyticsSection);',
			),
		).toBe(handWritten(['filterSection', 'statsSection', 'analyticsSection']))
	})

	it('keeps the selectors in the order given', () => {
		expect(
			compile('@include divided-sections(filterSection, statsSection);'),
		).toContain(
			'.filterSection,.statsSection{padding:12px 0;border-bottom:1px solid var(--color-border)}',
		)
	})
})
