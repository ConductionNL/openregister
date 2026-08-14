/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Estonian register detector for openregister l10n.
//
// Like Catalan, Estonian UI splits by string ROLE, so one global verdict would be
// meaningless: core uses the bare 2sg imperative for buttons ("Salvesta",
// "Kustuta", "Vali") regardless of how prose addresses the user. This detector
// therefore measures the PROSE register only.
//
// Why closed verb lists and not suffix patterns:
//   • "-ge"/"-ke" is not a reliable 2pl-imperative marker — "selge" (clear),
//     "helge" (bright), "märge" (note), "kirge" (passion, gen.) all end that way
//     and are adjectives or nouns.
//   • the bare 2sg imperative is a homograph of nouns and names: "Lisa" is both
//     "add!" and "addition"/a given name, "Ava" is both "open!" and a name,
//     "Vali" is both "choose!" and "loud". It is also the button convention, so
//     counting it as informal would flag every button in the app.
// The unambiguous informal signals are the sina-series pronouns and the 2sg
// present tense in -d, so those carry the verdict.

function fold(s) {
	return String(s).toLowerCase()
}

// teie / formal 2pl
const FORMAL_RES = [
	// "teist" is deliberately EXCLUDED: it is a homograph. As the elative of "teie"
	// it means "of you", but it is also the partitive of "teine" ("another",
	// "second"), which is far more common in UI prose — "Proovi teist otsingut"
	// ("try another search") is informal 2sg and was being scored as formal.
	/(?<!\p{L})(?:teie|teid|teile|teil|teilt|teiega)(?!\p{L})/gu,
	// closed list of 2pl imperatives that actually recur in Nextcloud UI prose
	/(?<!\p{L})(?:oodake|valige|sisestage|vajutage|kontrollige|proovige|salvestage|kustutage|lisage|avage|sulgege|kasutage|veenduge|võtke|tehke|minge|lugege|kirjutage|seadistage|uuendage|laadige|saatke|kinnitage|jätkake|muutke|eemaldage|kopeerige|otsige|vaadake|rakendage|määrake|looge|palun oodake|pöörduge|jälgige|arvestage|täitke|eelistage|paigaldage|lülitage|klõpsake|liikuge)(?!\p{L})/gu,
]

// sina / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	/(?<!\p{L})(?:sina|sinu|sind|sulle|sult|sinuga|sinust)(?!\p{L})/gu,
	// 2sg present tense of the highest-frequency modal/perception verbs. These
	// have no nominal reading, unlike the bare imperatives.
	/(?<!\p{L})(?:saad|võid|pead|tahad|näed|tead|soovid|oled|saaksid|võiksid|peaksid)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (teie prose)
	['Palun oodake', 'formal'],
	['Sisestage oma salasõna', 'formal'],
	['Valige register ja skeem', 'formal'],
	['Kontrollige oma seadistusi', 'formal'],
	['See mõjutab teie kontot', 'formal'],
	['Vaadake dokumentatsiooni', 'formal'],
	// must read informal (sina prose) — the deviation
	['Sisesta oma salasõna, sina', 'informal'],
	['Sa saad seda hiljem muuta', 'informal'],
	['Sinu konto', 'informal'],
	['Kui soovid, proovi uuesti', 'informal'],
	// must read NEITHER: bare 2sg imperative button labels are the CORRECT
	// Estonian UI convention, not an informal-register defect. If these fired the
	// gate would flag every button in the app.
	['Salvesta', 'neither'],
	['Kustuta', 'neither'],
	['Lisa', 'neither'],
	['Vali', 'neither'],
	['Ava', 'neither'],
	['Sulge', 'neither'],
	['Loo register', 'neither'],
	['Muuda nime', 'neither'],
	// must read NEITHER: the -ge/-ke suffix trap on adjectives and nouns
	['Selge vastus', 'neither'],
	['Helge taust', 'neither'],
	['Märge on lisatud', 'neither'],
	['Objekt on kustutatud', 'neither'],
	['Andmete kvaliteet', 'neither'],
	// The "teist" homograph: partitive of "teine" ("another"), NOT elative of
	// "teie". These read as informal to a human — bare 2sg imperative — but carry
	// no DETECTABLE marker, because bare imperatives are excluded on purpose (they
	// are the button convention). "neither" is the correct expectation, and the
	// point of these controls is that they must not score FORMAL.
	['Proovi teist otsingut', 'neither'],
	['Vali teist registrit', 'neither'],
	['Gruppe ei leitud. Proovi teist otsingut.', 'neither'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls: the bare 2sg imperative is both the correct Estonian button
// convention and a homograph of nouns and names ("Lisa" = "add!" / "addition",
// "Ava" = "open!" / a given name), so counting it would flag every button.
const UNDETECTABLE = [
	['Proovi uuesti', 'bare 2sg imperative — correct button style, no marker to match'],
	['Sisesta oma salasõna', '"oma" is person-neutral, so only the bare imperative signals 2sg'],
]

function score(s) {
	const t = fold(s)
	let f = 0
	let i = 0
	// Fresh regex per call: a reused /g/ carries lastIndex and silently turns
	// later matches into misses.
	for (const re of FORMAL_RES) f += (t.match(new RegExp(re.source, re.flags)) || []).length
	for (const re of INFORMAL_RES) i += (t.match(new RegExp(re.source, re.flags)) || []).length
	return { f, i }
}

function runControls() {
	let fail = 0
	for (const [s, want] of CONTROLS) {
		const { f, i } = score(s)
		const got = i > 0 ? 'informal' : f > 0 ? 'formal' : 'neither'
		if (got !== want) {
			fail++
			console.log(`FAIL want=${want} got=${got} f=${f} i=${i}  ${s}`)
		}
	}
	return { fail, total: CONTROLS.length }
}

module.exports = { score, fold, runControls, CONTROLS, UNDETECTABLE }

if (require.main === module) {
	const { fail, total } = runControls()
	console.log(`controls: ${total - fail}/${total} pass`)
	if (fail) process.exitCode = 1

	const fs = require('fs')
	const path = require('path')
	const S = '/home/thijn/nextcloud-docker-dev/workspace/server'
	const files = []
	for (const p of ['core/l10n', 'lib/l10n']) {
		for (const c of ['et', 'et_EE']) {
			const f = path.join(S, p, `${c}.json`)
			if (fs.existsSync(f)) files.push(f)
		}
	}
	for (const a of fs.readdirSync(path.join(S, 'apps'))) {
		for (const c of ['et', 'et_EE']) {
			const f = path.join(S, 'apps', a, 'l10n', `${c}.json`)
			if (fs.existsSync(f)) files.push(f)
		}
	}
	let F = 0
	let I = 0
	let n = 0
	const hits = []
	for (const f of files) {
		let j
		try { j = JSON.parse(fs.readFileSync(f, 'utf8')) } catch { continue }
		for (const v of Object.values(j.translations || {})) {
			for (const x of Array.isArray(v) ? v : [v]) {
				if (typeof x !== 'string') continue
				n++
				const s = score(x)
				F += s.f
				I += s.i
				if (s.i > 0) hits.push([x.slice(0, 100), path.relative(S, f)])
			}
		}
	}
	console.log(`\nscanned ${files.length} et/et_EE files, ${n} values`)
	console.log(`formal (teie) markers:  ${F}`)
	console.log(`informal (sina) markers: ${I}`)
	console.log(`verdict: ${F > I * 3 ? 'FORMAL prose (teie)' : I > F * 3 ? 'INFORMAL prose (sina)' : 'MIXED — inspect'}`)
	for (const [v, f] of hits.slice(0, 15)) console.log(`  informal? ${f}: ${v}`)
}
