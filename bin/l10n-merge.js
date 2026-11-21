// node l10nmerge.js <repo> <file>... : 3-way merge l10n/*.js from index stages 1/2/3, write file.
const { execFileSync } = require('child_process'); const fs = require('fs'); const os = require('os'); const path = require('path')
const repo = process.argv[2]; const lib = require(path.join(repo, 'scripts/l10n/lib.js'))
for (const f of process.argv.slice(3)) {
  const load = (st) => { try { const t = path.join(os.tmpdir(), 'l10n-' + st + '.js'); fs.writeFileSync(t, execFileSync('git', ['-C', repo, 'show', `:${st}:${f}`])); return lib.loadJsTranslations(t) } catch (e) { return null } }
  const b = load(1), o = load(2), t = load(3)
  const B = b ? b.translations : {}, O = o.translations, T = t.translations
  const out = {}; const same = (x, y) => JSON.stringify(x) === JSON.stringify(y)
  for (const k of new Set([...Object.keys(O), ...Object.keys(T), ...Object.keys(B)])) {
    const inB = k in B, inO = k in O, inT = k in T
    if (inO && inT) { out[k] = same(O[k], B[k]) ? T[k] : same(T[k], B[k]) ? O[k] : T[k] }
    else if (inO && !inT) { if (!inB || !same(O[k], B[k])) out[k] = O[k] }
    else if (inT && !inO) { if (!inB || !same(T[k], B[k])) out[k] = T[k] }
  }
  // keep main's key order for existing keys, append PR keys in sorted position only if main is sorted
  const tSer = lib.serializeJs(t); const tRaw = execFileSync('git', ['-C', repo, 'show', `:3:${f}`]).toString()
  let text
  if (tSer === tRaw) text = lib.serializeJs({ app: t.app, translations: out, pluralForm: t.pluralForm })
  else { // unsorted (en.js): main order, then new keys at end
    const ordered = {}; for (const k of Object.keys(T)) if (k in out) ordered[k] = out[k]; for (const k of Object.keys(out)) if (!(k in ordered)) ordered[k] = out[k]
    const keys = Object.keys(ordered); const lines = keys.map((k, i) => `    ${JSON.stringify(k)} : ${JSON.stringify(ordered[k])}${i === keys.length - 1 ? '' : ','}`)
    text = `OC.L10N.register(\n    ${JSON.stringify(t.app)},\n    {\n${lines.join('\n')}\n},\n${JSON.stringify(t.pluralForm)}\n);\n`
    if (lib.serializeJs(t) !== tRaw) console.error(f + ': main not in sorted layout; kept main order, new keys appended')
  }
  fs.writeFileSync(path.join(repo, f), text); console.log(f, Object.keys(out).length)
}
