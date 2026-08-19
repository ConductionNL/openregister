// SPDX-License-Identifier: EUPL-1.2
//
// Fixture: the legacy consumer-injected registry. Real apps document the
// runtime resolution order here:
//
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// `LegacyOnlyPanel` is registered HERE and nowhere else. A manifest naming it
// resolves at runtime, so check (f) must not report it. `NotAnywherePanel`,
// by contrast, is in neither file — that one must still FAIL, which is the
// control that keeps this exemption from becoming a blanket amnesty.
import LegacyOnlyPanel from './views/LegacyOnlyPanel.vue'

export default {
	LegacyOnlyPanel,
}
