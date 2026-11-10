import { registerWidget } from '@nextcloud/vue-richtext'

// Not installed in this repo's node_modules; the widget API is the edge.
jest.mock('@nextcloud/vue-richtext', () => ({ __esModule: true, registerWidget: jest.fn() }), { virtual: true })
jest.mock('./ObjectReferenceWidget.vue', () => ({ __esModule: true, default: { name: 'ObjectReferenceWidget' } }))

describe('reference widget registration', () => {
	beforeAll(async () => {
		await import('./init.ts')
	})

	it('registers exactly one widget under the openregister-object id', () => {
		expect(registerWidget).toHaveBeenCalledTimes(1)
		expect(registerWidget.mock.calls[0][0]).toBe('openregister-object')
	})

	it('lazily resolves the ObjectReferenceWidget component', async () => {
		const loader = registerWidget.mock.calls[0][1]
		await expect(loader()).resolves.toEqual({ name: 'ObjectReferenceWidget' })
	})
})
