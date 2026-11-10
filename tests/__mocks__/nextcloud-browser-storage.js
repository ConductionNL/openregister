const storage = {
	getItem: () => null,
	setItem: () => {},
	removeItem: () => {},
	clear: () => {},
	length: 0,
	key: () => null,
}

// The builder chains in any order: @nextcloud/auth calls `getBuilder().persist().build()`.
const builder = {
	clearOnLogout: () => builder,
	persist: () => builder,
	build: () => storage,
}

module.exports = {
	getBuilder: () => builder,
}
