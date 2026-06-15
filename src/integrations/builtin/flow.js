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
 * @spec openspec/changes/integration-flow/tasks.md
 * @see ADR-019 Integration Registry
 */

export { flowIntegration as default, flowIntegration } from '@conduction/nextcloud-vue'
