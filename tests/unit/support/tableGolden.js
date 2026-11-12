/**
 * Helpers for DOM golden tests of list tables.
 *
 * `tableText` reads what a table shows (its header labels and the text of
 * every cell) independent of the markup that draws it, so the same golden
 * record holds for a hand-built `<table>` and for the same table moved onto
 * CnDataTable. `CnDataTableStub` stands in for the library table (jest stubs
 * the whole @conduction/nextcloud-vue package): it draws the columns, rows,
 * `column-<key>`, `row-actions`, `actions-header` and `empty` slots the way
 * CnDataTable 2.175.0 does, without its styling.
 *
 * @spec exclude Test support for DOM golden records.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
const { h } = jest.requireActual('vue')

/**
 * Collapse the whitespace of an element's text.
 *
 * @param {Element} el The element
 * @return {string} Its text on one line
 */
function textOf(el) {
	return el.textContent.replace(/\s+/g, ' ').trim()
}

/**
 * What every table under a wrapper shows: header labels and cell texts.
 *
 * @param {object} wrapper A @vue/test-utils wrapper
 * @return {Array<object>} One `{ headers, rows }` per table
 */
export function tableText(wrapper) {
	return Array.from(wrapper.element.querySelectorAll('table')).map((table) => ({
		headers: Array.from(table.querySelectorAll('thead th')).map(textOf),
		rows: Array.from(table.querySelectorAll('tbody tr')).map((tr) =>
			Array.from(tr.children).map(textOf),
		),
	}))
}

/**
 * A plain stand-in for a library component: a tag named after it, its
 * non-listener attributes, and its default slot.
 *
 * @param {string} name The component name
 * @param {string} [tag] The element to draw
 * @return {object} The stub
 */
export function plainStub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(
					([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'object',
				),
			)
			return h(tag, { 'data-stub': name, ...attrs }, this.$slots.default?.())
		},
	}
}

export const CnDataTableStub = {
	name: 'CnDataTable',
	props: {
		columns: { type: Array, default: () => [] },
		rows: { type: Array, default: () => [] },
		rowKey: { type: String, default: 'id' },
		selectable: { type: Boolean, default: false },
		selectedIds: { type: Array, default: () => [] },
		rowClass: { type: Function, default: null },
		emptyText: { type: String, default: 'No items found' },
		loading: { type: Boolean, default: false },
		hideHeader: { type: Boolean, default: false },
	},
	emits: ['select', 'row-click', 'sort'],
	render() {
		const slots = this.$slots
		const hasActions = !!slots['row-actions']
		const head = h('thead', [
			h('tr', [
				this.selectable ? h('th', { class: 'stub-checkbox' }) : null,
				...this.columns.map((col) => h('th', { key: col.key }, col.label)),
				hasActions ? h('th', slots['actions-header']?.()) : null,
			]),
		])
		const span = this.columns.length + (this.selectable ? 1 : 0) + (hasActions ? 1 : 0)
		const body = this.rows.length === 0
			? [h('tr', [h('td', { colspan: span }, slots.empty ? slots.empty() : this.emptyText)])]
			: this.rows.map((row) => {
				const id = row[this.rowKey]
				const selected = this.selectedIds.includes(id)
				return h(
					'tr',
					{
						key: id,
						class: [selected ? 'stub-selected' : '', this.rowClass ? this.rowClass(row) : ''],
						onClick: () => (this.selectable
							? this.$emit('select', selected
								? this.selectedIds.filter((x) => x !== id)
								: [...this.selectedIds, id])
							: this.$emit('row-click', row)),
					},
					[
						this.selectable ? h('td', { class: 'stub-checkbox' }) : null,
						...this.columns.map((col) => h(
							'td',
							{ key: col.key },
							slots['column-' + col.key]
								? slots['column-' + col.key]({ row, value: row[col.key] })
								: String(row[col.key] ?? ''),
						)),
						hasActions ? h('td', slots['row-actions']({ row })) : null,
					],
				)
			})
		return h('div', { 'data-stub': 'CnDataTable', 'data-loading': String(this.loading) }, [
			h('table', [this.hideHeader ? null : head, h('tbody', body)]),
		])
	},
}
