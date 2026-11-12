import { mount } from '@vue/test-utils'
import Modals from './Modals.vue'
import { navigationStore } from '../store/store.js'

jest.mock('../store/store.js', () => {
	const { reactive } = require('vue')
	return { __esModule: true, navigationStore: reactive({ modal: false, dialog: false }) }
})
// Every child modal is replaced by a marker element carrying its own name, so
// the spec sees exactly which modals the host mounts for each navigation key.
jest.mock('./application/EditApplication.vue', () => ({ __esModule: true, default: { name: 'EditApplication', template: '<i class="EditApplication" />' } }))
jest.mock('./configuration/DeleteConfiguration.vue', () => ({ __esModule: true, default: { name: 'DeleteConfiguration', template: '<i class="DeleteConfiguration" />' } }))
jest.mock('./configuration/EditConfiguration.vue', () => ({ __esModule: true, default: { name: 'EditConfiguration', template: '<i class="EditConfiguration" />' } }))
jest.mock('./configuration/ExportConfiguration.vue', () => ({ __esModule: true, default: { name: 'ExportConfiguration', template: '<i class="ExportConfiguration" />' } }))
jest.mock('./configuration/ImportConfiguration.vue', () => ({ __esModule: true, default: { name: 'ImportConfiguration', template: '<i class="ImportConfiguration" />' } }))
jest.mock('./configuration/PublishConfiguration.vue', () => ({ __esModule: true, default: { name: 'PublishConfiguration', template: '<i class="PublishConfiguration" />' } }))
jest.mock('./configuration/ViewConfiguration.vue', () => ({ __esModule: true, default: { name: 'ViewConfiguration', template: '<i class="ViewConfiguration" />' } }))
jest.mock('./deleted/PurgeMultiple.vue', () => ({ __esModule: true, default: { name: 'PurgeMultiple', template: '<i class="PurgeMultiple" />' } }))
jest.mock('./deleted/RestoreMultiple.vue', () => ({ __esModule: true, default: { name: 'RestoreMultiple', template: '<i class="RestoreMultiple" />' } }))
jest.mock('./endpoint/DeleteEndpoint.vue', () => ({ __esModule: true, default: { name: 'DeleteEndpoint', template: '<i class="DeleteEndpoint" />' } }))
jest.mock('./endpoint/EditEndpoint.vue', () => ({ __esModule: true, default: { name: 'EditEndpoint', template: '<i class="EditEndpoint" />' } }))
jest.mock('./file/UploadFiles.vue', () => ({ __esModule: true, default: { name: 'UploadFiles', template: '<i class="UploadFiles" />' } }))
jest.mock('./object/CopyObject.vue', () => ({ __esModule: true, default: { name: 'CopyObject', template: '<i class="CopyObject" />' } }))
jest.mock('./object/DeleteObject.vue', () => ({ __esModule: true, default: { name: 'DeleteObject', template: '<i class="DeleteObject" />' } }))
jest.mock('./object/DownloadObject.vue', () => ({ __esModule: true, default: { name: 'DownloadObject', template: '<i class="DownloadObject" />' } }))
jest.mock('./object/LockObject.vue', () => ({ __esModule: true, default: { name: 'LockObject', template: '<i class="LockObject" />' } }))
jest.mock('./object/MassDeleteObject.vue', () => ({ __esModule: true, default: { name: 'MassDeleteObject', template: '<i class="MassDeleteObject" />' } }))
jest.mock('./object/MassValidateObjects.vue', () => ({ __esModule: true, default: { name: 'MassValidateObjects', template: '<i class="MassValidateObjects" />' } }))
jest.mock('./object/MergeObject.vue', () => ({ __esModule: true, default: { name: 'MergeObject', template: '<i class="MergeObject" />' } }))
jest.mock('./object/MigrationObject.vue', () => ({ __esModule: true, default: { name: 'MigrationObject', template: '<i class="MigrationObject" />' } }))
jest.mock('./object/UploadObject.vue', () => ({ __esModule: true, default: { name: 'UploadObject', template: '<i class="UploadObject" />' } }))
jest.mock('./object/ViewObject.vue', () => ({ __esModule: true, default: { name: 'ViewObject', template: '<i class="ViewObject" />' } }))
jest.mock('./objectAuditTrail/ViewObjectAuditTrail.vue', () => ({ __esModule: true, default: { name: 'ViewObjectAuditTrail', template: '<i class="ViewObjectAuditTrail" />' } }))
jest.mock('./organisation/DeleteOrganisation.vue', () => ({ __esModule: true, default: { name: 'DeleteOrganisation', template: '<i class="DeleteOrganisation" />' } }))
jest.mock('./organisation/EditOrganisation.vue', () => ({ __esModule: true, default: { name: 'EditOrganisation', template: '<i class="EditOrganisation" />' } }))
jest.mock('./organisation/JoinOrganisation.vue', () => ({ __esModule: true, default: { name: 'JoinOrganisation', template: '<i class="JoinOrganisation" />' } }))
jest.mock('./organisation/ManageOrganisationRoles.vue', () => ({ __esModule: true, default: { name: 'ManageOrganisationRoles', template: '<i class="ManageOrganisationRoles" />' } }))
jest.mock('./register/DeleteRegister.vue', () => ({ __esModule: true, default: { name: 'DeleteRegister', template: '<i class="DeleteRegister" />' } }))
jest.mock('./register/ExportRegister.vue', () => ({ __esModule: true, default: { name: 'ExportRegister', template: '<i class="ExportRegister" />' } }))
jest.mock('./register/ImportRegister.vue', () => ({ __esModule: true, default: { name: 'ImportRegister', template: '<i class="ImportRegister" />' } }))
jest.mock('./register/PublishRegister.vue', () => ({ __esModule: true, default: { name: 'PublishRegister', template: '<i class="PublishRegister" />' } }))
jest.mock('./schema/DeleteSchema.vue', () => ({ __esModule: true, default: { name: 'DeleteSchema', template: '<i class="DeleteSchema" />' } }))
jest.mock('./schema/DeleteSchemaObjects.vue', () => ({ __esModule: true, default: { name: 'DeleteSchemaObjects', template: '<i class="DeleteSchemaObjects" />' } }))
jest.mock('./schema/DeleteSchemaProperty.vue', () => ({ __esModule: true, default: { name: 'DeleteSchemaProperty', template: '<i class="DeleteSchemaProperty" />' } }))
jest.mock('./schema/EditSchema.vue', () => ({ __esModule: true, default: { name: 'EditSchema', template: '<i class="EditSchema" />' } }))
jest.mock('./schema/EditSchemaProperty.vue', () => ({ __esModule: true, default: { name: 'EditSchemaProperty', template: '<i class="EditSchemaProperty" />' } }))
jest.mock('./schema/ExploreSchema.vue', () => ({ __esModule: true, default: { name: 'ExploreSchema', template: '<i class="ExploreSchema" />' } }))
jest.mock('./schema/UploadSchema.vue', () => ({ __esModule: true, default: { name: 'UploadSchema', template: '<i class="UploadSchema" />' } }))
jest.mock('./schema/ValidateSchema.vue', () => ({ __esModule: true, default: { name: 'ValidateSchema', template: '<i class="ValidateSchema" />' } }))
jest.mock('./source/DeleteSource.vue', () => ({ __esModule: true, default: { name: 'DeleteSource', template: '<i class="DeleteSource" />' } }))
jest.mock('./source/EditSource.vue', () => ({ __esModule: true, default: { name: 'EditSource', template: '<i class="EditSource" />' } }))
jest.mock('./source/ViewSource.vue', () => ({ __esModule: true, default: { name: 'ViewSource', template: '<i class="ViewSource" />' } }))
jest.mock('./webhook/EditWebhook.vue', () => ({ __esModule: true, default: { name: 'EditWebhook', template: '<i class="EditWebhook" />' } }))
jest.mock('./webhook/ViewWebhookLog.vue', () => ({ __esModule: true, default: { name: 'ViewWebhookLog', template: '<i class="ViewWebhookLog" />' } }))

// Mounted regardless of the navigation state (they gate themselves).
const ALWAYS = [
	'ImportRegister',
	'ExportRegister',
	'PublishRegister',
	'DeleteRegister',
	'DeleteConfiguration',
	'ImportConfiguration',
	'ExportConfiguration',
	'PublishConfiguration',
	'DeleteSchema',
	'UploadSchema',
	'DeleteSchemaProperty',
	'EditSource',
	'DeleteSource',
	'DeleteObject',
	'LockObject',
	'ViewObject',
	'UploadFiles',
	'ViewSource',
	'ViewConfiguration',
]

// Mounted only while navigationStore[key] === value.
const GATED = [
	['EditConfiguration', 'modal', 'editConfiguration'],
	['ExploreSchema', 'dialog', 'exploreSchema'],
	['EditSchema', 'modal', 'editSchema'],
	['ValidateSchema', 'dialog', 'validateSchema'],
	['DeleteSchemaObjects', 'dialog', 'deleteSchemaObjects'],
	['EditSchemaProperty', 'modal', 'editSchemaProperty'],
	['MergeObject', 'modal', 'mergeObject'],
	['MigrationObject', 'modal', 'migrationObject'],
	['CopyObject', 'dialog', 'copyObject'],
	['DownloadObject', 'modal', 'downloadObject'],
	['UploadObject', 'modal', 'uploadObject'],
	['ViewObjectAuditTrail', 'modal', 'viewObjectAuditTrail'],
	['MassDeleteObject', 'dialog', 'massDeleteObject'],
	['MassValidateObjects', 'dialog', 'massValidateObjects'],
	['RestoreMultiple', 'dialog', 'restoreMultiple'],
	['PurgeMultiple', 'dialog', 'permanentlyDeleteMultiple'],
	['JoinOrganisation', 'modal', 'joinOrganisation'],
	['EditOrganisation', 'modal', 'editOrganisation'],
	['DeleteOrganisation', 'modal', 'deleteOrganisation'],
	['ManageOrganisationRoles', 'modal', 'manageOrganisationRoles'],
	['EditApplication', 'modal', 'editApplication'],
	['EditWebhook', 'modal', 'editWebhook'],
	['ViewWebhookLog', 'modal', 'viewWebhookLog'],
	['EditEndpoint', 'modal', 'editEndpoint'],
	['DeleteEndpoint', 'dialog', 'deleteEndpoint'],
]

/**
 * The names of the child modals currently rendered.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<string>}
 */
function rendered(wrapper) {
	return wrapper.findAll('i').map((el) => el.classes()[0])
}

describe('Modals host', () => {
	beforeEach(() => {
		navigationStore.modal = false
		navigationStore.dialog = false
	})

	it('mounts only the self-gating modals when nothing is open', () => {
		expect(rendered(mount(Modals)).sort()).toEqual([...ALWAYS].sort())
	})

	it.each(GATED)('mounts %s only while navigation %s is %s', (name, key, value) => {
		navigationStore[key] = value
		const extra = rendered(mount(Modals)).filter((n) => !ALWAYS.includes(n))
		expect(extra).toEqual([name])
	})

	it('does not mount a gated modal for the other navigation key', () => {
		navigationStore.dialog = 'editSchema'
		expect(rendered(mount(Modals))).not.toContain('EditSchema')
	})
})
