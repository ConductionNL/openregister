// SPDX-License-Identifier: EUPL-1.2
/**
 * Flow (NC workflowengine) integration registration for OpenRegister.
 *
 * Re-exports the bespoke `flowIntegration` descriptor from
 * `@conduction/nextcloud-vue` so the shared integration registry
 * receives the CnFlowTab / CnFlowCard component pair instead of the
 * generic CnIntegrationTab / CnIntegrationCard fallback.
 *
 * Registration ordering: `registerBuiltinIntegrations()` in
 * `src/integrations/bootstrap.js` already includes this descriptor via
 * the library's `builtinIntegrations[]` array, so no additional call is
 * needed in the OpenRegister bootstrap. This file is the canonical
 * per-integration reference for the flow leaf — consuming apps that
 * want to register only the flow descriptor can import it directly and
 * call `registry.register(flowIntegration)` before
 * `registerBuiltinIntegrations()` to win the AD-13 first-wins collision
 * policy.
 *
 * Descriptor shape (mirrors leaves.js entry for interchangeability):
 *   id:          'flow'
 *   label:       t('nextcloud-vue', 'Flow')
 *   icon:        'SitemapOutline'
 *   requiredApp: 'workflowengine'
 *   order:       64
 *   group:       'workflow'
 *   referenceType: 'flow'
 *   tab:         CnFlowTab   (bespoke)
 *   widget:      CnFlowCard  (bespoke)
 *
 * @module src/integrations/builtin/flow
 * @spec openspec/specs/integration-flow/spec.md
 * @see ADR-019 Integration Registry
 */

import { builtinIntegrations } from '@conduction/nextcloud-vue'

/**
 * The `flow` descriptor, resolved out of the library's `builtinIntegrations[]`.
 *
 * ⚠️ `@conduction/nextcloud-vue` exported a standalone `flowIntegration` name on
 * the Vue 2 (`beta.*`) line; the Vue 3 line (`2.1.0-vue3.13`) does NOT. It still
 * ships the descriptor, but only inside `builtinIntegrations[]` — verified
 * against the published dist, with `registerIcons` as a positive control for
 * the lookup. The old `export { flowIntegration } from …` therefore re-exported
 * `undefined`: a consuming app calling `registry.register(flowIntegration)`
 * would have registered nothing, and ESLint's `import/named` was the only thing
 * that noticed.
 *
 * Resolving by id keeps the documented contract working across both lines.
 *
 * @type {object|undefined}
 */
export const flowIntegration = Array.isArray(builtinIntegrations)
	? builtinIntegrations.find((integration) => integration?.id === 'flow')
	: undefined

export default flowIntegration
