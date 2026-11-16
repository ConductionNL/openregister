// peercheck.js <package-lock.json>: print "holder\tpeer\trange\tresolved" for every unmet non-optional peer range.
const fs = require('fs')
const path = require('path')
const semver = require(path.join(__dirname, 'tools/node_modules/npm/node_modules/semver'))
const lock = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'))
const pk = lock.packages || {}
function resolve(from, name) {
	let dir = from
	for (;;) {
		const k = (dir ? dir + '/' : '') + 'node_modules/' + name
		if (pk[k]) return pk[k]
		if (!dir) return null
		const i = dir.lastIndexOf('/node_modules/')
		dir = i < 0 ? '' : dir.slice(0, i)
	}
}
for (const [key, v] of Object.entries(pk)) {
	if (!key) continue
	for (const [peer, range] of Object.entries(v.peerDependencies || {})) {
		const r = resolve(key, peer)
		if (!r || !r.version) continue
		if (!semver.satisfies(r.version, range, { includePrerelease: true })) {
			console.log(`${key.replace(/^.*node_modules\//, '')}\t${peer}\t${range}\t${r.version}`)
		}
	}
}
