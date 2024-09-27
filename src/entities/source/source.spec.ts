import { Source } from './source'
import { mockSourceData } from './source.mock'

describe('Source Entity', () => {
	it('should create a Source entity with full data', () => {
		const source = new Source(mockSourceData()[0])

		expect(source).toBeInstanceOf(Source)
		expect(source).toEqual(mockSourceData()[0])
		expect(source.validate().success).toBe(true)
	})

	// ... existing code ...
})
