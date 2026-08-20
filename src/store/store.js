// The store script handles app wide variables (or state), for the use of these variables and there governing concepts read the design.md
import pinia from '../pinia.js'
import { useApplicationStore } from './modules/application.js'
import { useAuditTrailStore } from './modules/auditTrail.js'
import { useAvgStore } from './modules/avg.js'
import { useConfigurationStore } from './modules/configuration.js'
import { useDashboardStore } from './modules/dashboard.js'
import { useDeletedStore } from './modules/deleted.js'
import { useEndpointStore } from './modules/endpoints.ts'
import { useNavigationStore } from './modules/navigation.js'
import { useObjectStore } from './modules/object.js'
import { useOrganisationStore } from './modules/organisation.js'
import { useQualityStore } from './modules/quality.js'
import { useRegisterStore } from './modules/register.js'
import { useReportsStore } from './modules/reports.js'
import { useSchemaStore } from './modules/schema.js'
import { useSearchStore } from './modules/search.ts'
import { useSearchTrailStore } from './modules/searchTrail.js'
import { useSourceStore } from './modules/source.js'
import { useViewsStore } from './modules/views.js'
import { useWebhookStore } from './modules/webhook.js'

const navigationStore = useNavigationStore(pinia)
const searchStore = useSearchStore(pinia)
const registerStore = useRegisterStore(pinia)
const sourceStore = useSourceStore(pinia)
const schemaStore = useSchemaStore(pinia)
const objectStore = useObjectStore(pinia)
const configurationStore = useConfigurationStore(pinia)
const dashboardStore = useDashboardStore(pinia)
const auditTrailStore = useAuditTrailStore(pinia)
const searchTrailStore = useSearchTrailStore(pinia)
const deletedStore = useDeletedStore(pinia)
const organisationStore = useOrganisationStore(pinia)
const applicationStore = useApplicationStore(pinia)
const viewsStore = useViewsStore(pinia)
const endpointStore = useEndpointStore(pinia)
const avgStore = useAvgStore(pinia)
const reportsStore = useReportsStore(pinia)
const qualityStore = useQualityStore(pinia)
const webhookStore = useWebhookStore(pinia)

export {
	applicationStore,
	auditTrailStore,
	avgStore,
	configurationStore,
	dashboardStore,
	deletedStore,
	endpointStore,
	// generic
	navigationStore,
	objectStore,
	organisationStore,
	qualityStore,
	registerStore,
	reportsStore,
	schemaStore,
	searchStore,
	searchTrailStore,
	sourceStore,
	viewsStore,
	webhookStore,
}
