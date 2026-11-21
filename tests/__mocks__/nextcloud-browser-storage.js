const storage = {
	getItem: () => null,
	setItem: () => {},
	removeItem: () => {},
	clear: () => {},
	length: 0,
	key: () => null,
}

// The builder is fluent: clearOnLogout() and persist() chain in any order.
const builder = {
	clearOnLogout: () => builder,
	persist: () => builder,
	build: () => storage,
}

module.exports = {
	getBuilder: () => builder,
}
