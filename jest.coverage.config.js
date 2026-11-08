/**
 * Jest configuration for the line-coverage run behind the coverage ratchet.
 *
 * @spec exclude Test tooling configuration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
const base = require('./jest.config.js')

module.exports = {
	...base,
	testPathIgnorePatterns: [
		...base.testPathIgnorePatterns,
		// Both specs mock a `vue-material-design-icons/*.vue` file and load the
		// real one in a full run with coverage. Each passes on its own.
		'refreshButton\\.spec\\.js$',
		'migrationPackTables\\.dom\\.golden\\.spec\\.js$',
	],
}
