import { defineAsyncComponent } from 'vue'
import registry from './registry.js'

// The real defineAsyncComponent only stores the loader; replace it with a
// recorder so the spec can run each loader and see which module it resolves.
jest.mock('vue', () => ({
	__esModule: true,
	defineAsyncComponent: jest.fn((loader) => ({ asyncLoader: loader })),
}))

// Every lazily-loaded page resolves to a stand-in that names its registry key.
jest.mock('./views/dashboard/DashboardIndex.vue', () => ({ __esModule: true, default: { name: 'Dashboard' } }))
jest.mock('./views/register/RegistersIndex.vue', () => ({ __esModule: true, default: { name: 'RegistersIndex' } }))
jest.mock('./views/register/RegisterDetail.vue', () => ({ __esModule: true, default: { name: 'RegisterDetail' } }))
jest.mock('./views/schema/SchemasIndex.vue', () => ({ __esModule: true, default: { name: 'SchemasIndex' } }))
jest.mock('./views/schema/SchemaDetails.vue', () => ({ __esModule: true, default: { name: 'SchemaDetails' } }))
jest.mock('./views/source/SourcesIndex.vue', () => ({ __esModule: true, default: { name: 'SourcesIndex' } }))
jest.mock('./views/migrationPack/MigrationPacksIndex.vue', () => ({ __esModule: true, default: { name: 'MigrationPacksIndex' } }))
jest.mock('./views/migrationPack/MigrationPackDetail.vue', () => ({ __esModule: true, default: { name: 'MigrationPackDetail' } }))
jest.mock('./views/organisation/OrganisationsIndex.vue', () => ({ __esModule: true, default: { name: 'OrganisationsIndex' } }))
jest.mock('./views/application/ApplicationsIndex.vue', () => ({ __esModule: true, default: { name: 'ApplicationsIndex' } }))
jest.mock('./views/application/ApplicationDetails.vue', () => ({ __esModule: true, default: { name: 'ApplicationDetails' } }))
jest.mock('./views/object/ObjectsIndex.vue', () => ({ __esModule: true, default: { name: 'ObjectsIndex' } }))
jest.mock('./views/search/SearchIndex.vue', () => ({ __esModule: true, default: { name: 'SearchIndex' } }))
jest.mock('./views/files/FilesIndex.vue', () => ({ __esModule: true, default: { name: 'FilesIndex' } }))
jest.mock('./views/configuration/ConfigurationsIndex.vue', () => ({ __esModule: true, default: { name: 'ConfigurationsIndex' } }))
jest.mock('./views/deleted/DeletedIndex.vue', () => ({ __esModule: true, default: { name: 'DeletedIndex' } }))
jest.mock('./views/logs/AuditTrailIndex.vue', () => ({ __esModule: true, default: { name: 'AuditTrailIndex' } }))
jest.mock('./views/logs/SearchTrailIndex.vue', () => ({ __esModule: true, default: { name: 'SearchTrailIndex' } }))
jest.mock('./views/webhooks/WebhooksIndex.vue', () => ({ __esModule: true, default: { name: 'WebhooksIndex' } }))
jest.mock('./views/flows/FlowDetailSidebar.vue', () => ({ __esModule: true, default: { name: 'FlowDetailSidebar' } }))
jest.mock('./views/webhooks/WebhookLogsIndex.vue', () => ({ __esModule: true, default: { name: 'WebhookLogsIndex' } }))
jest.mock('./views/agents/AgentsIndex.vue', () => ({ __esModule: true, default: { name: 'AgentsIndex' } }))
jest.mock('./views/Endpoint/EndpointsIndex.vue', () => ({ __esModule: true, default: { name: 'EndpointsIndex' } }))
jest.mock('./views/entities/EntitiesIndex.vue', () => ({ __esModule: true, default: { name: 'EntitiesIndex' } }))
jest.mock('./views/entities/EntityDetail.vue', () => ({ __esModule: true, default: { name: 'EntityDetail' } }))
jest.mock('./views/templates/TemplatesIndex.vue', () => ({ __esModule: true, default: { name: 'TemplatesIndex' } }))
jest.mock('./views/account/MyAccount.vue', () => ({ __esModule: true, default: { name: 'MyAccount' } }))
jest.mock('./views/avg/AvgIndex.vue', () => ({ __esModule: true, default: { name: 'AvgIndex' } }))
jest.mock('./views/reports/ReportsIndex.vue', () => ({ __esModule: true, default: { name: 'ReportsIndex' } }))
jest.mock('./views/reports/ReportView.vue', () => ({ __esModule: true, default: { name: 'ReportView' } }))
jest.mock('./views/roadmap/FeaturesRoadmapIndex.vue', () => ({ __esModule: true, default: { name: 'FeaturesRoadmapIndex' } }))
jest.mock('./views/integration/IntegrationsView.vue', () => ({ __esModule: true, default: { name: 'IntegrationsView' } }))
jest.mock('./views/quality/QualityIndex.vue', () => ({ __esModule: true, default: { name: 'QualityIndex' } }))
jest.mock('./views/quality/DuplicatesIndex.vue', () => ({ __esModule: true, default: { name: 'DuplicatesIndex' } }))
jest.mock('./views/quality/MasterEntitiesIndex.vue', () => ({ __esModule: true, default: { name: 'MasterEntitiesIndex' } }))
jest.mock('./views/quality/QueueHealthIndex.vue', () => ({ __esModule: true, default: { name: 'QueueHealthIndex' } }))
jest.mock('./views/operations/OperationsConsoleIndex.vue', () => ({ __esModule: true, default: { name: 'OperationsConsoleIndex' } }))
jest.mock('./views/quality/MergeOperationsIndex.vue', () => ({ __esModule: true, default: { name: 'MergeOperationsIndex' } }))

const EXPECTED = [
	['Dashboard', 'Dashboard'],
	['RegistersIndex', 'RegistersIndex'],
	['RegisterDetail', 'RegisterDetail'],
	['SchemasIndex', 'SchemasIndex'],
	['SchemaDetails', 'SchemaDetails'],
	['SourcesIndex', 'SourcesIndex'],
	['MigrationPacksIndex', 'MigrationPacksIndex'],
	['MigrationPackDetail', 'MigrationPackDetail'],
	['OrganisationsIndex', 'OrganisationsIndex'],
	['ApplicationsIndex', 'ApplicationsIndex'],
	['ApplicationDetails', 'ApplicationDetails'],
	['ObjectsIndex', 'ObjectsIndex'],
	['SearchIndex', 'SearchIndex'],
	['FilesIndex', 'FilesIndex'],
	['ConfigurationsIndex', 'ConfigurationsIndex'],
	['DeletedIndex', 'DeletedIndex'],
	['AuditTrailIndex', 'AuditTrailIndex'],
	['SearchTrailIndex', 'SearchTrailIndex'],
	['WebhooksIndex', 'WebhooksIndex'],
	['FlowDetailSidebar', 'FlowDetailSidebar'],
	['WebhookLogsIndex', 'WebhookLogsIndex'],
	['AgentsIndex', 'AgentsIndex'],
	['EndpointsIndex', 'EndpointsIndex'],
	['EntitiesIndex', 'EntitiesIndex'],
	['EntityDetail', 'EntityDetail'],
	['TemplatesIndex', 'TemplatesIndex'],
	['MyAccount', 'MyAccount'],
	['AvgIndex', 'AvgIndex'],
	['ReportsIndex', 'ReportsIndex'],
	['ReportView', 'ReportView'],
	['FeaturesRoadmapIndex', 'FeaturesRoadmapIndex'],
	['IntegrationsView', 'IntegrationsView'],
	['QualityIndex', 'QualityIndex'],
	['DuplicatesIndex', 'DuplicatesIndex'],
	['MasterEntitiesIndex', 'MasterEntitiesIndex'],
	['QueueHealthIndex', 'QueueHealthIndex'],
	['OperationsConsoleIndex', 'OperationsConsoleIndex'],
	['MergeOperationsIndex', 'MergeOperationsIndex'],
]

describe('page registry', () => {
	it('registers exactly the expected page keys', () => {
		expect(Object.keys(registry).sort()).toEqual(EXPECTED.map(([key]) => key).sort())
	})

	it('wraps every lazy loader in defineAsyncComponent as a page entry', () => {
		expect(defineAsyncComponent).toHaveBeenCalledTimes(EXPECTED.length)
		Object.values(registry).forEach((entry) => {
			expect(entry.kind).toBe('page')
			expect(typeof entry.component.asyncLoader).toBe('function')
		})
	})

	it.each(EXPECTED)('%s lazily loads its own view module', async (key, name) => {
		const mod = await registry[key].component.asyncLoader()
		expect(mod.default).toEqual({ name })
	})
})
