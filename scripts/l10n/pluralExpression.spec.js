/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

const { compilePluralExpression } = require('./pluralExpression.js')

describe('compilePluralExpression', () => {
	it.each([
		['(n != 1)', [[0, 1], [1, 0], [2, 1]]],
		['(n > 1)', [[0, 0], [1, 0], [2, 1]]],
		['0', [[0, 0], [5, 0]]],
		[
			'(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)',
			[[1, 0], [2, 1], [5, 2], [11, 2], [12, 2], [21, 0], [22, 1], [25, 2]],
		],
		[
			'(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5)',
			[[0, 0], [1, 1], [2, 2], [3, 3], [11, 4], [100, 5], [102, 5]],
		],
		['(n%10!=1 || n%100==11)', [[1, 0], [11, 1], [21, 0], [2, 1]]],
		['!(n == 1) + 0 * 3 - 0 / 2', [[1, 0], [2, 1]]],
	])('evaluates %s like C', (source, cases) => {
		const plural = compilePluralExpression(source)
		for (const [n, index] of cases) {
			expect(plural(n)).toBe(index)
		}
	})

	it.each(['n+', 'alert(1)', 'n ? 1', 'n;1', '(n', 'process.exit()'])('rejects %s', (source) => {
		expect(() => compilePluralExpression(source)).toThrow(SyntaxError)
	})
})
