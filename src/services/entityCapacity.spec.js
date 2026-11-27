import axios from '@nextcloud/axios'
import {
	canConfirmCapacity,
	CAPACITIES,
	capacityLabel,
	capacityLine,
	confirmCapacity,
} from './entityCapacity.js'

/**
 * The capacity line and confirm control of a detected person (task 2.3).
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.3
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { patch: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path, params) => `/gen${path.replace('{id}', params.id)}`,
}))

describe('entityCapacity', () => {
	beforeEach(() => {
		axios.patch.mockReset()
	})

	it('knows the four capacities the server accepts', () => {
		expect(CAPACITIES).toEqual(['public-office', 'professional', 'private', 'unknown'])
	})

	it('labels each capacity, and shows an unknown value as it is', () => {
		expect(capacityLabel('public-office')).toBe('t:Public office')
		expect(capacityLabel('professional')).toBe('t:Professional capacity')
		expect(capacityLabel('private')).toBe('t:Private person')
		expect(capacityLabel('unknown')).toBe('t:Capacity unknown')
		expect(capacityLabel('alderman')).toBe('alderman')
	})

	it('shows the suggestion while it is unconfirmed', () => {
		expect(capacityLine({ capacity: 'public-office', capacityApplied: null }))
			.toBe('t:Suggested capacity: {capacity}{"capacity":"t:Public office"}')
	})

	it('shows the confirmed capacity over the suggestion', () => {
		expect(capacityLine({ capacity: 'public-office', capacityApplied: 'private' }))
			.toBe('t:Confirmed capacity: {capacity}{"capacity":"t:Private person"}')
	})

	it('shows no capacity line for a relation that is not a person', () => {
		expect(capacityLine({ capacity: null })).toBeNull()
		expect(capacityLine(null)).toBeNull()
	})

	it('offers the confirm control only for an unconfirmed, known suggestion', () => {
		expect(canConfirmCapacity({ capacity: 'professional', capacityApplied: null })).toBe(true)
		expect(canConfirmCapacity({ capacity: 'professional', capacityApplied: 'professional' })).toBe(false)
		expect(canConfirmCapacity({ capacity: null })).toBe(false)
		expect(canConfirmCapacity({ capacity: 'alderman' })).toBe(false)
		expect(canConfirmCapacity(undefined)).toBe(false)
	})

	it('confirms by sending only the capacity, so the server policy decides', async () => {
		axios.patch.mockResolvedValue({ data: { id: 42, capacityApplied: 'public-office', skipAnonymization: true } })

		const answer = await confirmCapacity({ id: 42, capacity: 'public-office', skipAnonymization: false })

		expect(axios.patch).toHaveBeenCalledTimes(1)
		expect(axios.patch).toHaveBeenCalledWith(
			'/gen/apps/openregister/api/entity-relations/42',
			{ capacity: 'public-office' },
		)
		expect(answer).toEqual({ id: 42, capacityApplied: 'public-office', skipAnonymization: true })
	})

	it('lets a refusal reach the caller', async () => {
		axios.patch.mockRejectedValue(new Error('refused'))
		await expect(confirmCapacity({ id: 7, capacity: 'private' })).rejects.toThrow('refused')
	})
})
