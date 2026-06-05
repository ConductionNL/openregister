/**
 * Built-in integration registrations for OpenRegister.
 *
 * Each integration descriptor is exported here so the registry bootstrap can
 * import them in one statement. Tab and widget components are resolved by the
 * registry from @conduction/nextcloud-vue at runtime.
 *
 * @see openspec/changes/integration-email/tasks.md#task-6
 * @see ADR-019 (Integration Registry Pattern)
 */

export { default as emailIntegration } from './builtin/email.js'
