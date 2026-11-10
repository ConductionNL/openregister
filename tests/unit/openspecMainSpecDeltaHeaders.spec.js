/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A main spec under openspec/specs/ carries no OpenSpec delta header
 * (`## ADDED|MODIFIED|REMOVED|RENAMED Requirements`). Those headers belong in
 * openspec/changes/<name>/specs/ only. In a main spec one cuts the parsed
 * `## Requirements` section short, so every requirement after it is invisible
 * to `openspec validate`, `list` and `archive`, and a change against that
 * capability cannot be archived (ConductionNL/hydra#712). The match ignores
 * case, as openspec's own check does.
 */

import * as fs from 'fs'
import * as path from 'path'

const SPECS = path.resolve(__dirname, '../../openspec/specs')
const DELTA_HEADER = /^##\s+(ADDED|MODIFIED|REMOVED|RENAMED)\s+Requirements\b/i

/**
 * Every markdown file under openspec/specs.
 *
 * @param {string} dir The folder to walk.
 * @return {string[]} Paths of spec markdown files.
 */
function specFiles(dir = SPECS) {
	const out = []
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			out.push(...specFiles(full))
		} else if (entry.name.endsWith('.md')) {
			out.push(full)
		}
	}
	return out
}

describe('openspec main specs', () => {
	it('carry no delta header', () => {
		const hits = []
		for (const file of specFiles()) {
			const lines = fs.readFileSync(file, 'utf8').split('\n')
			let inFence = false
			lines.forEach((line, i) => {
				if (line.startsWith('```')) {
					inFence = !inFence
				}
				if (!inFence && DELTA_HEADER.test(line)) {
					hits.push(`${path.relative(SPECS, file)}:${i + 1} ${line}`)
				}
			})
		}
		expect(hits).toEqual([])
	})
})
