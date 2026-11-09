/**
 * Stand-ins for golden DOM records of views, modals and dialogs: a library whose
 * every component renders its tag, plain attributes and every slot, and a
 * normaliser that makes the markup comparable across refactors.
 *
 * @spec exclude Test support for golden records of client-side markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
const { h } = require('vue')

/**
 * Slot props that name themselves, so a scoped slot renders every field it reads.
 */
const SLOT_PROPS = new Proxy(
	{},
	{
		get: (target, key) => (typeof key === 'string' ? `[${key}]` : undefined),
		has: () => true,
	},
)

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [
						key,
						typeof value === 'function'
							? 'fn'
							: typeof value === 'object'
								? JSON.stringify(value)
								: value,
					]),
			)
			return h(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => h(`slot-${slot}`, this.$slots[slot](SLOT_PROPS))),
			)
		},
	}
}

/**
 * A component library whose every named export is a stub of that name.
 *
 * @return {object} The module
 */
function library() {
	const made = {}
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || stub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A component module whose default export is a stub.
 *
 * @param {string} name The component name
 * @return {object} The module
 */
function stubModule(name) {
	return { __esModule: true, default: stub(name) }
}

/**
 * Markup without scope ids, comments and the whitespace next to a tag, one tag
 * per line, so only a change to elements, attributes or text shows.
 *
 * @param {object} wrapper The mounted wrapper, or one found inside it
 * @return {string} The normalised markup
 */
function normalise(wrapper) {
	return wrapper
		.html({ raw: true })
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/>\s+/g, '>')
		.replace(/\s+</g, '<')
		.replace(/></g, '>\n<')
}

module.exports = { library, normalise, stub, stubModule }
