/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

const TOKEN = /\s*(\d+|n|\|\||&&|[=!<>]=|[-+*/%<>!?:()])/y

/**
 * Compiles the C expression of a gettext `plural=` header into a function of `n`,
 * without evaluating it as code. It accepts what plural headers use: integers,
 * `n`, parentheses, `! * / % + -`, comparisons, `&& ||` and `? :`.
 *
 * @param {string} source The expression after `plural=`.
 * @return {(n: number) => number} The plural index for a count; a boolean result is 0 or 1.
 * @throws {SyntaxError} When the expression holds anything else.
 */
function compilePluralExpression(source) {
	const tokens = []
	const text = String(source).trim()
	TOKEN.lastIndex = 0
	while (TOKEN.lastIndex < text.length) {
		const match = TOKEN.exec(text)
		if (!match) {
			throw new SyntaxError(`unexpected input in plural expression at ${TOKEN.lastIndex}: ${text}`)
		}
		tokens.push(match[1])
	}

	let pos = 0
	const peek = () => tokens[pos]
	const take = (expected) => {
		const token = tokens[pos++]
		if (expected !== undefined && token !== expected) {
			throw new SyntaxError(`expected ${expected} in plural expression: ${text}`)
		}
		return token
	}

	const binary = (next, operators) => () => {
		let left = next()
		while (operators[peek()]) {
			const apply = operators[take()]
			const right = next()
			const lhs = left
			left = (n) => apply(lhs(n), right(n))
		}
		return left
	}

	const primary = () => {
		const token = take()
		if (token === '(') {
			const inner = ternary()
			take(')')
			return inner
		}
		if (token === '!') {
			const operand = primary()
			return (n) => Number(!operand(n))
		}
		if (token === '-') {
			const operand = primary()
			return (n) => -operand(n)
		}
		if (token === 'n') {
			return (n) => n
		}
		if (/^\d+$/.test(token ?? '')) {
			const value = Number(token)
			return () => value
		}
		throw new SyntaxError(`unexpected ${token ?? 'end'} in plural expression: ${text}`)
	}

	const product = binary(primary, {
		'*': (a, b) => a * b,
		'/': (a, b) => Math.trunc(a / b),
		'%': (a, b) => a % b,
	})
	const sum = binary(product, { '+': (a, b) => a + b, '-': (a, b) => a - b })
	const relation = binary(sum, {
		'<': (a, b) => Number(a < b),
		'>': (a, b) => Number(a > b),
		'<=': (a, b) => Number(a <= b),
		'>=': (a, b) => Number(a >= b),
	})
	const equality = binary(relation, { '==': (a, b) => Number(a === b), '!=': (a, b) => Number(a !== b) })
	const and = binary(equality, { '&&': (a, b) => Number(a && b) })
	const or = binary(and, { '||': (a, b) => Number(a || b) })

	/**
	 * Parses `condition ? a : b`, right-associative, the loosest level.
	 *
	 * @return {(n: number) => number} The compiled sub-expression.
	 */
	function ternary() {
		const condition = or()
		if (peek() !== '?') {
			return condition
		}
		take('?')
		const whenTrue = ternary()
		take(':')
		const whenFalse = ternary()
		return (n) => (condition(n) ? whenTrue(n) : whenFalse(n))
	}

	const compiled = ternary()
	if (pos !== tokens.length) {
		throw new SyntaxError(`unexpected ${tokens[pos]} in plural expression: ${text}`)
	}
	return (n) => Number(compiled(n))
}

module.exports = { compilePluralExpression }
