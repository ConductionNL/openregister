/**
 * Email integration registration for the OpenRegister integration registry.
 *
 * Registers the email integration with id 'email' so that:
 *   - CnEmailTab renders as a sidebar tab when linkedTypes includes 'email'
 *   - CnEmailCard renders on all four widget surfaces
 *   - schema properties with referenceType: 'email' auto-render CnEmailCard
 *
 * Tab and widget components live in @conduction/nextcloud-vue and are resolved
 * by the registry at runtime — this file only declares the metadata and
 * referenceType binding.
 *
 * @see openspec/changes/integration-email/tasks.md#task-5
 * @see ADR-019 (Integration Registry Pattern)
 */

/**
 * Email integration descriptor.
 *
 * @type {object}
 */
const emailIntegration = {
	id: 'email',
	label: t('openregister', 'Emails'),
	icon: 'Email',
	group: 'comms',
	requiredApp: 'mail',
	storageStrategy: 'link-table',
	referenceType: 'email',
}

export default emailIntegration
