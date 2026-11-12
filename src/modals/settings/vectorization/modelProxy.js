/**
 * Two-way binding of a component prop through a computed property.
 *
 * @spec exclude UI plumbing shared by the vectorization form parts.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A computed property that reads a prop and emits its update event.
 *
 * @param {string} prop The prop name
 * @return {{get: function(): *, set: function(*): void}} The computed property
 */
export function modelProxy(prop = 'modelValue') {
	return {
		get() {
			return this[prop]
		},
		set(value) {
			this.$emit(`update:${prop}`, value)
		},
	}
}
