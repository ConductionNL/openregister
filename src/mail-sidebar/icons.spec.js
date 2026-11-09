import { schemaIconComponent } from './icons.js'

// The icon SFCs are not transformed under jest; each resolves to a stand-in
// that carries its own name, so the lookup result is identifiable.
jest.mock('vue-material-design-icons/AccountGroupOutline.vue', () => ({ __esModule: true, default: { name: 'AccountGroupOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/AccountMultipleOutline.vue', () => ({ __esModule: true, default: { name: 'AccountMultipleOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/AccountOutline.vue', () => ({ __esModule: true, default: { name: 'AccountOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/BellOutline.vue', () => ({ __esModule: true, default: { name: 'BellOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/BookOpenOutline.vue', () => ({ __esModule: true, default: { name: 'BookOpenOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/BriefcaseOutline.vue', () => ({ __esModule: true, default: { name: 'BriefcaseOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/CalendarOutline.vue', () => ({ __esModule: true, default: { name: 'CalendarOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/CardAccountDetailsOutline.vue', () => ({ __esModule: true, default: { name: 'CardAccountDetailsOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/CartOutline.vue', () => ({ __esModule: true, default: { name: 'CartOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/CheckCircleOutline.vue', () => ({ __esModule: true, default: { name: 'CheckCircleOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/ClipboardListOutline.vue', () => ({ __esModule: true, default: { name: 'ClipboardListOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/ClipboardTextOutline.vue', () => ({ __esModule: true, default: { name: 'ClipboardTextOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/ClockOutline.vue', () => ({ __esModule: true, default: { name: 'ClockOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({ __esModule: true, default: { name: 'CubeOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/CurrencyEur.vue', () => ({ __esModule: true, default: { name: 'CurrencyEur' } }), { virtual: true })
jest.mock('vue-material-design-icons/EmailOutline.vue', () => ({ __esModule: true, default: { name: 'EmailOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: { name: 'FileDocumentOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({ __esModule: true, default: { name: 'FileOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({ __esModule: true, default: { name: 'FolderOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/HandshakeOutline.vue', () => ({ __esModule: true, default: { name: 'HandshakeOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/HomeOutline.vue', () => ({ __esModule: true, default: { name: 'HomeOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({ __esModule: true, default: { name: 'LinkVariant' } }), { virtual: true })
jest.mock('vue-material-design-icons/MapMarkerOutline.vue', () => ({ __esModule: true, default: { name: 'MapMarkerOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/OfficeBuildingOutline.vue', () => ({ __esModule: true, default: { name: 'OfficeBuildingOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/PackageVariantClosed.vue', () => ({ __esModule: true, default: { name: 'PackageVariantClosed' } }), { virtual: true })
jest.mock('vue-material-design-icons/PhoneOutline.vue', () => ({ __esModule: true, default: { name: 'PhoneOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/ReceiptTextOutline.vue', () => ({ __esModule: true, default: { name: 'ReceiptTextOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/ScaleBalance.vue', () => ({ __esModule: true, default: { name: 'ScaleBalance' } }), { virtual: true })
jest.mock('vue-material-design-icons/ShapeOutline.vue', () => ({ __esModule: true, default: { name: 'ShapeOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/TagOutline.vue', () => ({ __esModule: true, default: { name: 'TagOutline' } }), { virtual: true })
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({ __esModule: true, default: { name: 'TrendingUp' } }), { virtual: true })

const REGISTERED = ['AccountGroupOutline', 'AccountMultipleOutline', 'AccountOutline', 'BellOutline', 'BookOpenOutline', 'BriefcaseOutline', 'CalendarOutline', 'CardAccountDetailsOutline', 'CartOutline', 'CheckCircleOutline', 'ClipboardListOutline', 'ClipboardTextOutline', 'ClockOutline', 'CubeOutline', 'CurrencyEur', 'EmailOutline', 'FileDocumentOutline', 'FileOutline', 'FolderOutline', 'HandshakeOutline', 'HomeOutline', 'LinkVariant', 'MapMarkerOutline', 'OfficeBuildingOutline', 'PackageVariantClosed', 'PhoneOutline', 'ReceiptTextOutline', 'ScaleBalance', 'TagOutline', 'TrendingUp']

describe('schemaIconComponent', () => {
	it.each(REGISTERED)('returns the %s icon for its name', (name) => {
		expect(schemaIconComponent(name)).toEqual({ name })
	})

	it('falls back to ShapeOutline for an unknown name', () => {
		expect(schemaIconComponent('NoSuchIcon')).toEqual({ name: 'ShapeOutline' })
	})

	it('falls back to ShapeOutline for an empty or missing name', () => {
		expect(schemaIconComponent('')).toEqual({ name: 'ShapeOutline' })
		expect(schemaIconComponent(undefined)).toEqual({ name: 'ShapeOutline' })
	})
})
