/**
 * The sidebar toggle the list views with a details sidebar share. Spread into
 * `methods`; the view keeps a `sidebarOpen` data flag.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
export const sidebarToggle = {
	/**
	 * Toggle sidebar visibility
	 *
	 * @spec openspec/specs/admin-list-views/spec.md
	 * @return {void}
	 */
	toggleSidebar() {
		this.sidebarOpen = !this.sidebarOpen
	},
}
