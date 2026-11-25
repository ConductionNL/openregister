/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Dexie singleton guard reads the version a built chunk embeds, whether
 * minification left it as a literal or as a hoisted constant.
 *
 * @spec exclude Unit test of a build tooling script.
 */
const { chunkVersions } = require('./check-single-dexie.js')

describe('chunkVersions', () => {
	it('reads a semVer literal (dexie up to 4.4.6)', () => {
		expect([...chunkVersions('x={semVer:"4.4.6",version:1}')]).toEqual(['4.4.6'])
	})

	it('follows a semVer identifier to its string literal (dexie 4.4.7 on)', () => {
		const chunk = 'var Jt="4.4.7",Pe="\\uFFFF";D={semVer:Jt,version:Jt.split(".")};if(D.semVer!==x.semVer)throw 1'
		expect([...chunkVersions(chunk)]).toEqual(['4.4.7'])
	})

	it('reports both versions when a chunk carries two copies', () => {
		const chunk = 'var a="4.4.7";A={semVer:a};B={semVer:"4.4.5"}'
		expect([...chunkVersions(chunk)].sort()).toEqual(['4.4.5', '4.4.7'])
	})

	it('finds nothing when no version is recognisable, so the guard fails loudly', () => {
		expect(chunkVersions('D={semVer:getVersion()}').size).toBe(0)
	})

	it('does not take a property assignment of the same name for the constant', () => {
		expect(chunkVersions('o.Jt="9.9.9";D={semVer:Jt}').size).toBe(0)
	})
})
