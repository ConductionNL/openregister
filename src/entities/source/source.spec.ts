import { Source } from './source'
import { mockSourceData } from './source.mock'

describe('Source Entity', () => {
	it('should create a Source entity with full data', () => {
		const data = mockSourceData()[0]
		const source = new Source(data)

		expect(source).toBeInstanceOf(Source)
		// Compare field-by-field rather than deep-equal: deep equal
		// trips on `Source { ... }` (prototype) vs plain object, plus
		// `new Date().toISOString()` ms drift between mock invocations.
		expect(source.id).toBe(data.id)
		expect(source.title).toBe(data.title)
		expect(source.databaseUrl).toBe(data.databaseUrl)
		expect(source.validate().success).toBe(true)
	})

	// ... existing code ...
})
