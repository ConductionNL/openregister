/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The exports area (an-export-is-a-file-with-a-life 4.2) is a manifest index
 * over GET /api/exports. Losing a key here renders an empty or misleading
 * list without any error, so the declaration is pinned.
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-an-exports-area-lists-the-runs
 */

import * as fs from 'fs'
import * as path from 'path'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((candidate) => candidate.id === 'exports')
const column = (key) => page.config.columns.find((candidate) => candidate.key === key)

describe('exports area', () => {
	it('lists the runs from the exports endpoint, which does the scoping', () => {
		expect(page.type).toBe('index')
		expect(page.route).toBe('/exports')
		expect(page.config.entitySource).toBe('endpoint')
		expect(page.config.sourceConfig.url).toBe('/apps/openregister/api/exports')
		expect(page.config.sourceConfig.rowsPath).toBe('results')
		expect(page.config.showAdd).toBe(false)
	})

	it('names an expired run as expired, as a pill and not as a link', () => {
		const status = column('status')
		expect(status.enum).toEqual(['available', 'served', 'expired'])
		expect(status.enumLabels.expired).toBe('Expired')
		expect(status.colorMap.expired).toBe('warning')
		for (const candidate of page.config.columns) {
			expect(candidate.widget).not.toBe('link')
		}
	})

	it('shows the row count, the format, the expiry and the download count', () => {
		expect(page.config.columns.map((candidate) => candidate.key)).toEqual(
			expect.arrayContaining(['rowCount', 'format', 'expiresAt', 'downloadCount']),
		)
		expect(column('expiresAt').format).toBe('date-time')
	})

	it('is reachable from the Data menu', () => {
		const data = manifest.menu.find((item) => item.id === 'DataGroup')
		const entry = data.children.find((item) => item.id === 'Exports')
		expect(entry.route).toBe(page.id)
		expect(entry.icon).toBe('FileExportOutline')
	})
})
