/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Serbian register detector for openregister l10n.
//
// Measures the PROSE register only. Serbian buttons take the bare 2sg imperative
// regardless of how prose addresses the reader — the ca/et/hr/sl pattern — and
// counting those would be wrong twice over, exactly as in Slovenian:
//
//   1. it is the correct label convention, so every button in the app would flag;
//   2. for the whole -ити verb class the 2sg imperative is spelled exactly like
//      the 3sg present indicative — `уреди` is both "edit!" and "he edits", and so
//      are `обриши`... (no: `обрише` differs), `врати`, `провери`, `валидирај`.
//      The -ати/-овати classes do differ (`додај` vs `додаје`, `копирај` vs
//      `копира`), but the collision class is too large to carve out.
//
// Serbian is written in both alphabets in the wild. THIS bundle is Cyrillic —
// 945 of its 1055 pre-existing values are Cyrillic-only and the 20 Latin-only
// ones are all untranslated English plus two defects — so every word list below
// is Cyrillic. A Latin-script Serbian value is a finding, not a variant; see
// scripts/l10n/script-coverage.js.
//
// Why closed word lists and NOT suffix patterns, specifically for Serbian:
//   • "-ш" looks like the 2sg present ending, but "ваш" (your-FORMAL) and "наш"
//     (our) both end in it, so a "-ш" rule reads the formal possessive as
//     informal and inverts the polarity outright — the same trap as hr, sk, sl
//     and bg.
//   • "-те" is the 2pl ending, present and imperative alike. Serbian has no
//     definite article, so this is NOT the disaster it is in Bulgarian, but it
//     still collides with the feminine accusative plural of "тај" ("те датотеке"
//     = "those files"), so the list is closed anyway.
//   • bare "ти" is unusable: besides informal "you" it is the masculine
//     nominative plural of "тај", so "ти објекти" means "those objects" — the
//     same collision cs has with "ty", hr with "ti" and sl with "ti". Note this
//     is the OPPOSITE of bg, where "ти" is safe because Bulgarian lost its case
//     system; the two languages are neighbours and disagree.
//   • bare "те" is unusable for the mirror reason: 2sg accusative clitic AND the
//     accusative plural of "та".
//   • bare "си" is unusable: besides the 2sg of "бити" it is the reflexive dative
//     clitic, which is at its most frequent in FORMAL prose.
// JS \b is ASCII-only and would treat "ђ" as a boundary, so every guard is
// (?<!\p{L}) … (?!\p{L}) with the u flag.

function fold(s) {
	return String(s).toLowerCase()
}

// Ви / formal 2pl. Serbian capitalises the polite pronoun in careful writing, but
// this bundle writes it lowercase mid-sentence ("Управљајте вашим Регистрима"),
// so the input is folded first and either casing scores the same.
const FORMAL_RES = [
	/(?<!\p{L})(?:ви|вас|вам|вама)(?!\p{L})/gu,
	// ваш- possessive, all cases. The (?<!\p{L}) guard is what keeps this off "наш".
	/(?<!\p{L})ваш(?:а|е|и|ег|ем|им|их|ој|у|ом|ега|ему)?(?!\p{L})/gu,
	// Closed list of 2pl present-indicative forms. NOT a "-те" rule — see header.
	/(?<!\p{L})(?:морате|немате|имате|желите|знате|видите|можете|требате|користите|изаберете|сачувате|добијете|урадите|направите|обрадите|сузите|прецизирате|наведете|уђете|изађете|остварите|потребујете)(?!\p{L})/gu,
	// Closed list of 2pl imperatives. Serbian spells the 2pl imperative and the 2pl
	// present alike for most verbs and both are formal, so the split from the list
	// above is for readability only.
	/(?<!\p{L})(?:изаберите|унесите|проверите|сачекајте|кликните|притисните|сачувајте|обришите|додајте|уклоните|отворите|затворите|копирајте|пошаљите|потврдите|наставите|покушајте|конфигуришите|поставите|управљајте|контролишите|инсталирајте|оставите|прочитајте|укључите|искључите|креирајте|куцајте|прегледајте|филтрирајте|учитајте|упоредите|анализирајте|извезите|увезите|векторизујте|активирајте|деактивирајте|омогућите|онемогућите|ажурирајте|освежите|вратите|изведите|покрените|зауставите|тестирајте|валидирајте|објавите|делите|синхронизујте|започните|идите|будите|имајте|погледајте|обавестите|означите|попуните|пријавите|одјавите|обезбедите|смањите|повећајте|замените|доделите|ограничите|поделите|спојите|одобрите|одбијте|преузмите|отпремите|преименујте|преместите|поновите|довршите|архивирајте|очистите|откријте|издвојите|проширите|примените|прикажите|сакријте|претражите|напишите|наведите|уредите|подесите|пратите|изаберете)(?!\p{L})/gu,
]

// ти / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	// bare "ти", "те" and "си" deliberately absent — all three have common
	// non-2sg readings; see the header. The oblique forms below have no other.
	/(?<!\p{L})(?:тебе|теби|тобом)(?!\p{L})/gu,
	/(?<!\p{L})тво(?:ј|ја|је|ји|га|јег|јем|јим|јих|јој|ју|јом)(?!\p{L})/gu,
	// Closed list of 2sg present-indicative forms. NOT a "-ш" rule, which would
	// score the formal "ваш" as informal. The 2sg present is safe to enumerate
	// where the 2sg IMPERATIVE is not, because the present keeps the -ш that the
	// 3sg drops — `сачуваш` vs `сачува`.
	/(?<!\p{L})(?:мораш|немаш|имаш|желиш|знаш|видиш|можеш|требаш|користиш|изабереш|сачуваш|унесеш|провериш|обришеш|додаш|уклониш|отвориш|затвориш|копираш|пошаљеш|потврдиш|наставиш|покушаш|конфигуришеш|поставиш|управљаш|контролишеш|инсталираш|оставиш|прочиташ|укључиш|искључиш|креираш|куцаш|прегледаш|филтрираш|учиташ|упоредиш|анализираш|извезеш|увезеш|обрадиш|сузиш|активираш|ажурираш|освежиш|вратиш|покренеш|зауставиш|тестираш|валидираш|објавиш|делиш|синхронизујеш|започнеш|идеш|будеш|погледаш|добијеш|урадиш|направиш|наведеш|попуниш|пријавиш|одјавиш|замениш|доделиш|ограничиш|поделиш|спојиш|одобриш|одбијеш|преузмеш|отпремиш|преименујеш|преместиш|поновиш|довршиш|уђеш|изађеш|ниси)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (Ви prose) — all real values from this bundle or core sr
	['Изаберите Регистар', 'formal'],
	['Унесите опис (опционо)...', 'formal'],
	['Сачекајте док преузимамо ваше конфигурације.', 'formal'],
	['Управљајте вашим Регистрима података и њиховим конфигурацијама', 'formal'],
	['Користите филтере да сузите уносе ревизорског трага', 'formal'],
	['Морате бити пријављени да бисте означили приказе као омиљене', 'formal'],
	['Потребан вам је AI агент да започнете разговор.', 'formal'],
	['Ваш OpenAI API кључ. Набавите га на', 'formal'],
	['Покушајте поново касније.', 'formal'],
	['Прочитајте унос пре одлуке.', 'formal'],
	['Куцајте да претражите групе', 'formal'],
	['Да ли сте сигурни да желите да обришете', 'formal'],
	['Нема уноса ревизорског трага који одговарају вашим тренутним филтерима.', 'formal'],
	['Оставите празно да бисте обрадили све приказе на основу ваше конфигурације.', 'formal'],
	['Поставите на 0 да бисте обрадили све Датотеке.', 'formal'],
	['Изаберите које Својство из догађаја треба да се користи', 'formal'],
	// must read informal (ти prose) — the deviation
	['Твој Регистар', 'informal'],
	['Ово можеш касније да промениш', 'informal'],
	['Ако желиш, покушај поново', 'informal'],
	['Ово утиче на твоје податке', 'informal'],
	['Послато теби', 'informal'],
	['Знаш ли где је Датотека?', 'informal'],
	['Ниси пријављен', 'informal'],
	// the two 2sg-PRESENT values this bundle already shipped, both inside the
	// `Select …` family. They are the reason this detector enumerates the 2sg
	// present at all — see locales/sr.json under corrections.
	['Изабери репозиторијум за који имаш приступ за писање', 'informal'],
	['Изабери Регистре и Шеме да сачуваш приказ', 'informal'],
	// must read NEITHER: the bare 2sg imperative IS the correct Serbian label
	// convention, and for the -ити class it is also the 3sg present indicative.
	// If these fired, every button in the app would flag.
	['Сачувај', 'neither'],
	['Обриши', 'neither'],
	['Додај', 'neither'],
	['Креирај', 'neither'],
	['Уреди', 'neither'],
	['Откажи', 'neither'],
	['Затвори', 'neither'],
	['Копирај', 'neither'],
	['Освежи', 'neither'],
	['Врати', 'neither'],
	['Ажурирај', 'neither'],
	['Провери ланац', 'neither'],
	['Изабери све', 'neither'],
	['Прикажи детаље', 'neither'],
	['Додај апликацију', 'neither'],
	['Анализирај Објекте', 'neither'],
	['Изабери грану', 'neither'],
	// must read NEITHER: bare "ти" is the masculine nominative plural of "тај"
	['Ти Објекти су обрисани', 'neither'],
	['Ти Регистри нису пронађени', 'neither'],
	// must read NEITHER: bare "те" is the accusative plural of "та", and also the
	// 2sg accusative clitic — unusable either way
	['Те Датотеке су обрисане', 'neither'],
	['Прикажи те Објекте', 'neither'],
	// must read NEITHER: the reflexive dative "си" must not flip a sentence
	['Изабери себи други Регистар', 'neither'],
	// must read NEITHER: the "-ш" trap. "наш" is not a 2sg verb, and "ваш" must
	// score FORMAL rather than informal — that pair is tested above and here.
	['Наш Регистар', 'neither'],
	['Наша подешавања', 'neither'],
	// must read NEITHER: ordinary third-person prose
	['Ток може сваку од њих за себе да замени', 'neither'],
	['Обрађује Објекте секвенцијално (најбезбедније).', 'neither'],
	// mixed sanity: one informal marker inside otherwise formal prose wins
	['Изаберите Регистар и онда сачуваш измене', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Сачувај своје измене', 'bare 2sg imperative — the correct Serbian button '
		+ 'convention AND, for the -ити class, the 3sg present; "своје" is '
		+ 'person-neutral, so nothing here marks the number'],
	['Покушај поново', 'same: an -ати imperative, and the pre-existing value for '
		+ 'the Retry button'],
	['Изабери модел или унеси прилагођени назив модела', 'a REAL pre-existing '
		+ 'value, and the reason to check what an informal-looking string is made '
		+ 'of before calling it a deviation: "унеси" is the 2sg IMPERATIVE of '
		+ '"унети", not a 2sg present, so the whole string is two coordinated '
		+ 'imperatives — which is the correct label convention, not a register '
		+ 'slip. Its two siblings in the same `Select …` family DO carry 2sg '
		+ 'presents ("имаш", "сачуваш") and were corrected; this one is left alone'],
	['Дај ми то', 'bare "ми"/"ме" 1sg clitics are not address at all, so first-'
		+ 'person phrasing of any register is invisible to this detector'],
	['Ако желите, можете и сами', 'FALSE NEGATIVE the other way — "сами" is a '
		+ 'formal-plural agreement marker this detector does not read; the '
		+ 'sentence scores formal only because of "желите"/"можете"'],
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

	const { scanCoreRegister, reportCoreRegister } = require('../lib.js')
	reportCoreRegister('sr', scanCoreRegister('sr', score), { formal: 'Ви', informal: 'ти' })
}
