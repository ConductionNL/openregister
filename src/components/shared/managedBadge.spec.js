/**
 * ManagedBadge and the managedConfiguration predicates replace the badge
 * markup and isManagedBy* bodies RegistersIndex and SchemasIndex each carried.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a presentational badge and display predicates.
 */
import { mount } from '@vue/test-utils'
import ManagedBadge from './ManagedBadge.vue'
import {
	isExternalConfiguration,
	isLocalConfiguration,
	managedRowClass,
} from '../../composables/managedConfiguration.js'

jest.mock('vue-material-design-icons/CogOutline.vue', () => ({
	name: 'CogOutline',
	render: () => null,
}))

describe('managedConfiguration predicates', () => {
	it.each([
		[null, false, false, ''],
		[{}, false, false, ''],
		[{ sourceType: 'github' }, true, false, 'viewTableRow--managed'],
		[{ sourceType: 'gitlab' }, true, false, 'viewTableRow--managed'],
		[{ sourceType: 'url' }, true, false, 'viewTableRow--managed'],
		[{ isLocal: false }, true, false, 'viewTableRow--managed'],
		[{ sourceType: 'local' }, false, true, 'viewTableRow--local'],
		[{ sourceType: 'manual' }, false, true, 'viewTableRow--local'],
		[{ isLocal: true }, false, true, 'viewTableRow--local'],
	])('%j → external %s, local %s', (config, external, local, rowClass) => {
		expect(Boolean(isExternalConfiguration(config))).toBe(external)
		expect(Boolean(isLocalConfiguration(config))).toBe(local)
		expect(managedRowClass(config)).toBe(rowClass)
	})
})

describe('ManagedBadge', () => {
	it('renders the external badge', () => {
		const w = mount(ManagedBadge, { props: { config: { sourceType: 'url' } } })
		expect(w.classes()).toEqual(['managedBadge', 'managedBadge--external'])
		expect(w.text()).toBe('Managed')
	})

	it('renders the local badge', () => {
		const w = mount(ManagedBadge, { props: { config: { sourceType: 'local' } } })
		expect(w.classes()).toEqual(['managedBadge', 'managedBadge--local'])
		expect(w.text()).toBe('Local')
	})

	it('renders nothing when unmanaged', () => {
		const w = mount(ManagedBadge, { props: { config: null } })
		expect(w.find('.managedBadge').exists()).toBe(false)
	})
})
