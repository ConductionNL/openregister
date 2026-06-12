module.exports = {
	getBuilder: () => ({
		clearOnLogout: () => ({
			persist: () => ({
				build: () => ({
					getItem: () => null,
					setItem: () => {},
					removeItem: () => {},
					clear: () => {},
					length: 0,
					key: () => null,
				}),
			}),
		}),
	}),
}
