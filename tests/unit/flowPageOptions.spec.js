/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow page turns on the Canvas / List switch (`config.listMode`) and the
 * test run on an example payload (`config.testPayload`) of CnFlowEditorPage.
 * Both are opt-in, so losing a key hides the screen without any error. The
 * payload test is drawn in the board look only.
 */

import * as fs from 'fs'
import * as path from 'path'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const flowPages = manifest.pages.filter((page) => page.type === 'flow')

describe('flow page options', () => {
	it('declares a flow page', () => {
		expect(flowPages.length).toBeGreaterThan(0)
	})

	it.each(flowPages.map((page) => [page.id, page]))('%s offers the list view and the payload test', (_id, page) => {
		expect(page.config.listMode).toBe(true)
		expect(page.config.testPayload).toBe(true)
	})
})
