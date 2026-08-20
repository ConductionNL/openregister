/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Belarusian register detector for openregister l10n.
//
// Measures the PROSE register, which is FORMAL: вы / ваш / 2pl verb forms.
// Core be carries ~165 formal markers against ZERO informal over 1873 values in
// 14 catalogues, and this bundle's own 1053 pre-existing values carry 24 formal
// and zero informal. Neither corpus contains a single "ты", "цябе", "табе" or
// "тво-" form, so the verdict rests on a one-sided formal count together with
// an asserted absence rather than on a ratio.
//
// THE 2SG IMPERATIVE IS COUNTED, which is the minority outcome — §6.5 wants
// both tests run and both come out usable here:
//
//   Test 1 — is the imperative the label convention? NO. Belarusian labels are
//   INFINITIVES. Resolving 44 bare action keys against core gives Захаваць /
//   Выдаліць / Дадаць / Скасаваць / Рэдагаваць / Стварыць / Абнавіць /
//   Капіяваць / Перайменаваць / Спампаваць / Пацвердзіць / Прымяніць /
//   Уключыць / Адключыць / Працягнуць / Перамясціць / Усталяваць / Адхіліць /
//   Прапусціць / Скінуць / Адправіць / Аднавіць / Выбраць / Схаваць / Прыняць —
//   forty-odd infinitives and not one imperative. So counting 2sg imperatives
//   does not flag a single button, which is what forces the exclusion in
//   ca/et/hr/sl/sr/ga/mt/mk. This is the sk/rm/is/lb group instead.
//
//   Test 2 — is the 2sg imperative a homograph of a live form? NO, for the
//   closed list below. Belarusian builds the 2pl imperative as the 2sg PLUS
//   "-це" (выберы → выберыце, захавай → захавайце, увядзі → увядзіце), so the
//   two polarities are the same string differing by a suffix. That makes the
//   trailing (?!\p{L}) guard the only thing separating them — §8.1, the West
//   Slavic situation reached in a different family. Write the guard first.
//
// The one form given up is "май" (2sg imperative of "мець"), a homograph of the
// month name; it is in UNDETECTABLE rather than in the list.
//
// Why closed word lists and NOT suffix patterns, specifically for Belarusian:
//   • "-це" looks like the 2pl ending, and it is ALSO the locative singular of
//     every hard-stem masculine noun ending in -т/-с/-к: "фармаце", "праекце",
//     "тэксце", "запыце", "пакеце", "стандарце", "пошце", "даце" all occur in
//     these two corpora. A "-це" rule scores ordinary prepositional phrases —
//     including "у гэтым фармаце" and "у праекце", both live in this bundle —
//     as deferential address.
//   • "-ш" looks like the 2sg present ending, but "ваш" (your-FORMAL) ends in
//     it, so a "-ш" rule reads the formal possessive as informal and inverts the
//     polarity outright. The corpora also carry "больш" (17), "менш", "перш",
//     "найбольш", "клавіш", and the app's own "хэш" (3) and "кэш" — every one a
//     non-verb.
//   • "-ы"/"-і" is the commonest 2sg imperative ending AND the commonest plural
//     and genitive-singular ending in the language, so only the enumerated verb
//     forms below are safe.
//
// Two NEGATIVE results, both measured rather than assumed:
//   • bare "ты" IS usable. Belarusian's demonstrative is "гэты"/"гэтая"/"гэтыя",
//     never bare "ты", so unlike cs "ty", hr "ti" and sl "ti" there is no
//     demonstrative reading to give up. Zero occurrences in the 2928-value
//     corpus, so the assertion is that the token is absent, not that it is
//     disambiguated.
//   • Belarusian's acronym for AI is "ШІ" (штучны інтэлект) — this bundle writes
//     "ШІ-агент", "ШІ-функцыі" — and it is NOT a homograph of any register
//     marker. So the mk "ВИ"/"ви" problem does not replicate here and fold() can
//     lowercase freely. Worth recording as the measured negative it is: the
//     question is per language, and the answer here is no.
//
// The left guard carries the hyphen anyway. Belarusian forms hyphenated acronym
// compounds ("ШІ-агент", "API-ключ", "URL-адрас"), and while a marker after the
// hyphen is measured at zero occurrences in both corpora, the guard costs no
// recall and keeps an acronym-final "вы" or "ты" out of the pronoun bucket.
//
// JS \b is ASCII-only and would treat "ў", "і" and "ё" as boundaries, so every
// guard is (?<![\p{L}-]) … (?!\p{L}) with the u flag.

function fold(s) {
	// No case-sensitive distinction to preserve: Belarusian capitalises the
	// polite "Вы"/"Ваш" optionally, and both casings are the same register in a
	// UI string. Unlike mk there is no all-caps acronym colliding with a marker.
	return String(s).toLowerCase()
}

// вы / formal 2pl — the ordinary register of this bundle.
const FORMAL_RES = [
	/(?<![\p{L}-])(?:вы|вас|вам|вамі)(?!\p{L})/gu,
	// ваш- possessive across its declension. The literal "ваш" stem is what keeps
	// this off "наш" (3 occurrences, both corpora).
	/(?<![\p{L}-])ваш(?:ага|аму|ымі|ыя|ых|ым|ае|ай|ая|ую|а|у|ы|е)?(?!\p{L})/gu,
	// Closed list of 2pl present-indicative forms. NOT a "-це" rule — see header.
	/(?<![\p{L}-])(?:маеце|можаце|зможаце|хочаце|жадаеце|ведаеце|бачыце|робіце|шукаеце|выкарыстоўваеце|выкарыстаеце|атрымаеце|атрымліваеце|выбіраеце|дадаяце|кіруеце|спрабуеце|збіраецеся|працуеце|ствараеце|рэдагуеце|захоўваеце|змяняеце|наладжваеце|чакаеце|разумееце|мяркуеце|плануеце|дазваляеце|вызначаеце|правяраеце|апрацоўваеце|праглядаеце|фільтруеце|пачынаеце|працягваеце|будзеце|пазначаеце|валодаеце|думаеце|лічыце|ідзеце)(?!\p{L})/gu,
	// Closed list of 2pl imperatives. In this locale a 2pl imperative is the
	// ordinary formal shape for a prompt or a section description — "Выберыце"
	// (48), "Кіруйце" (17), "Увядзіце" (16) — not a deviation. The forms that are
	// simultaneously 2pl present and 2pl imperative ("глядзіце", "уводзіце",
	// "знаходзіце") are formal either way, so the ambiguity costs nothing.
	/(?<![\p{L}-])(?:выберыце|абярыце|увядзіце|ўвядзіце|націсніце|праверце|спраўдзіце|захавайце|выдаліце|выдаляйце|дадайце|адкрыйце|зачыніце|закрыйце|скапіюйце|скапіруйце|паспрабуйце|звярніцеся|звярніце|пачакайце|пачніце|працягвайце|спыніце|абнавіце|аднавіце|аднаўляйце|усталюйце|наладзьце|апішыце|напішыце|укажыце|задайце|зрабіце|стварыце|змяніце|перайменуйце|перамясціце|спампуйце|запампуйце|загрузіце|адпраўце|выкарыстоўвайце|глядзіце|паглядзіце|уводзіце|знаходзіце|прачытайце|паведаміце|папрасіце|паўтарыце|перазагрузіце|перазапусціце|уключыце|уключайце|выключыце|адключыце|ачысціце|выканайце|згенеруйце|імпартуйце|экспартуйце|пацвердзіце|адхіліце|ухваліце|прапусціце|завяршыце|наведайце|знайдзіце|вызначце|прызначце|дазвольце|забараніце|пашырце|архівуйце|сінхранізуйце|пратэстуйце|выпраўце|скіньце|скасуйце|пакіньце|трымайце|майце|ігнаруйце|праігнаруйце|увайдзіце|выйдзіце|падпішыцеся|зарэгіструйцеся|пазначце|сартуйце|згрупуйце|пракруціце|кіруйце|праглядайце|фільтруйце|аналізуйце|вектарызуйце|актывуйце|запытайце|пераканайцеся|скарыстайцеся|азнаёмцеся|адрэдагуйце|перацягніце|запоўніце|ацаніце|запусціце|кантралюйце|плануйце|атрымайце|дайце|зменшце|павялічце|дапоўніце|супастаўце|параўнайце)(?!\p{L})/gu,
]

// ты / informal 2sg — the DEVIATION this gate looks for.
const INFORMAL_RES = [
	/(?<![\p{L}-])(?:ты|цябе|табе|табой)(?!\p{L})/gu,
	// твой- possessive. The enumerated endings are what keeps this off the noun
	// "твар" (face) and the verb "тварыць".
	/(?<![\p{L}-])тва(?:йго|йму|ёй|ёю|іх|імі|ім|я|ё|е|ю)(?!\p{L})|(?<![\p{L}-])твой(?!\p{L})/gu,
	// Closed list of 2sg present-indicative forms. NOT a "-ш" rule, which would
	// score the formal "ваш" and the ordinary "больш"/"хэш" as informal.
	/(?<![\p{L}-])(?:маеш|можаш|зможаш|хочаш|жадаеш|ведаеш|бачыш|робіш|шукаеш|выкарыстоўваеш|выкарыстаеш|атрымаеш|атрымліваеш|выбіраеш|дадаеш|кіруеш|спрабуеш|збіраешся|працуеш|ствараеш|рэдагуеш|захоўваеш|змяняеш|наладжваеш|чакаеш|разумееш|мяркуеш|плануеш|дазваляеш|вызначаеш|правяраеш|апрацоўваеш|праглядаеш|фільтруеш|пачынаеш|працягваеш|будзеш|пазначаеш|валодаеш|думаеш|лічыш|ідзеш|выбераш|уведзеш|націснеш|захаваеш|выдаліш|створыш|зменіш|адкрыеш|закрыеш|зробіш|глядзіш|уводзіш|знаходзіш)(?!\p{L})/gu,
	// Closed list of 2sg imperatives — COUNTED here, see §6.5 in the header. Each
	// is the 2pl form above minus "-це", so the trailing guard is load-bearing:
	// without it every "Выберыце" in the bundle would score informal.
	/(?<![\p{L}-])(?:выберы|абяры|увядзі|ўвядзі|націсні|правер|спраўдзі|захавай|выдалі|выдаляй|дадай|адкрый|зачыні|закрый|скапіюй|скапіруй|паспрабуй|звярніся|звярні|пачакай|пачні|працягвай|спыні|абнаві|аднаві|аднаўляй|усталюй|наладзь|апішы|напішы|укажы|задай|зрабі|ствары|змяні|перайменуй|перамясці|спампуй|запампуй|загрузі|адпраў|выкарыстоўвай|глядзі|паглядзі|прачытай|паведамі|папрасі|паўтары|перазагрузі|перазапусці|уключы|уключай|выключы|адключы|ачысці|выканай|згенеруй|імпартуй|экспартуй|пацвердзі|адхілі|ухвалі|прапусці|завяршы|наведай|знайдзі|вызнач|прызнач|дазволь|забарані|пашыр|архівуй|сінхранізуй|пратэстуй|выпраў|скінь|скасуй|пакінь|трымай|ігнаруй|праігнаруй|увайдзі|выйдзі|падпішыся|зарэгіструйся|пазнач|сартуй|згрупуй|пракруці|кіруй|праглядай|фільтруй|аналізуй|вектарызуй|актывуй|запытай|пераканайся|скарыстайся|азнаёмся|адрэдагуй|перацягні|запоўні|ацані|запусці|кантралюй|плануй|атрымай|зменш|павялічы|дапоўні|супастаў|параўнай)(?!\p{L})/gu,
]

const CONTROLS = [
	// ---- must read FORMAL. Every one is a real value from this bundle or core be.
	['Вы ўпэўнены, што хочаце канчаткова выдаліць', 'formal'],
	['Вы можаце закрыць гэта акно.', 'formal'],
	['Вы не маеце доступу да гэтай старонкі.', 'formal'],
	['Вы выбралі SQLite у якасці базы даных.', 'formal'],
	['Выберыце рэпазіторый, да якога ў вас ёсць доступ на запіс', 'formal'],
	['Кіруйце вашымі рэестрамі дадзеных і іх канфігурацыямі', 'formal'],
	['Кіруйце і аднаўляйце мякка выдаленыя элементы з вашых рэестраў', 'formal'],
	['Ніводны прагляд не адпавядае вашаму пошуку', 'formal'],
	['Ніводная ўласцівасць не адпавядае вашым фільтрам.', 'formal'],
	['Вам патрэбны ШІ-агент, каб пачаць размову.', 'formal'],
	['Ваш ключ API Fireworks AI. Атрымайце яго на', 'formal'],
	['Калі ласка, пачакайце, пакуль мы атрымліваем вашы канфігурацыі.', 'formal'],
	['Наладзьце параметры для вектарызацыі аб\'ектаў.', 'formal'],
	['Запусціце захаванне аб\'екта для актывацыі або праверце вашу канфігурацыю notify_push.', 'formal'],
	['Выберыце, якія прагляды ўключыць у працэс вектарызацыі. Пакіньце пустым, каб апрацаваць усе прагляды на падставе вашай канфігурацыі.', 'formal'],
	['Загрузіце дадатковыя фільтры з жывымі дадзенымі з вашага пошукавага індэкса', 'formal'],
	['Націсніце кнопку ніжэй, каб скінуць пароль.', 'formal'],
	['Калі вы не запытвалі скід пароля, праігнаруйце гэты ліст.', 'formal'],
	['Звярніцеся да адміністратара.', 'formal'],
	['Вашы файлы зашыфраваны. Пасля скіду пароля вы не зможаце аднавіць свае даныя.', 'formal'],
	['Калі вы выкарыстоўваеце кліенты для сінхранізацыі файлаў, выкарыстанне SQLite вельмі не рэкамендуецца.', 'formal'],
	['Пошук пачынаецца, як толькі вы пачынаеце ўводзіць тэкст', 'formal'],
	['Глядзіце дакументацыю', 'formal'],
	['Азнаёмцеся з дакументацыяй перад пачаткам', 'formal'],
	// ---- must read INFORMAL — the deviation this gate exists for. Core be
	// contains ZERO informal values, so unlike every other locale these cannot be
	// harvested; each is the 2sg counterpart of a formal control above, which is
	// exactly the slip a translator carrying over Russian or Ukrainian habits
	// would produce.
	['Ты можаш закрыць гэта акно.', 'informal'],
	['Ты не маеш доступу да гэтай старонкі.', 'informal'],
	['Выберы рэестр', 'informal'],
	['Увядзі назву для адлюстравання', 'informal'],
	['Націсні кнопку ніжэй, каб скінуць пароль.', 'informal'],
	['Кіруй тваімі рэестрамі дадзеных', 'informal'],
	['Ніводны прагляд не адпавядае твайму пошуку', 'informal'],
	['Табе патрэбны ШІ-агент, каб пачаць размову.', 'informal'],
	['Твой ключ API Fireworks AI', 'informal'],
	['Калі ласка, пачакай, пакуль мы атрымліваем твае канфігурацыі.', 'informal'],
	['Скінь свой пароль', 'informal'],
	['Праверце гэта, калі ты хочаш атрымліваць апавяшчэнні', 'informal'],
	// ---- must read NEITHER: the label convention is the INFINITIVE, so no bare
	// button may score either polarity.
	['Захаваць', 'neither'],
	['Выдаліць', 'neither'],
	['Дадаць схему', 'neither'],
	['Скасаваць', 'neither'],
	['Рэдагаваць рэестр', 'neither'],
	['Стварыць', 'neither'],
	['Абнавіць', 'neither'],
	['Капіяваць', 'neither'],
	['Перайменаваць', 'neither'],
	['Спампаваць', 'neither'],
	['Выбраць файл', 'neither'],
	['Схаваць', 'neither'],
	['Прымяніць', 'neither'],
	['Аднавіць', 'neither'],
	['Дадаць у абраныя', 'neither'],
	// ---- must read NEITHER: the "-це" LOCATIVE trap. Every one of these is a
	// noun in the prepositional case, not a 2pl verb.
	['Захаваць у гэтым фармаце', 'neither'],
	['Уласцівасць у праекце', 'neither'],
	['Знойдзена ў тэксце', 'neither'],
	['Параметры ў запыце', 'neither'],
	['Файлы ў пакеце', 'neither'],
	['Вызначана ў стандарце', 'neither'],
	['Паведамленне на пошце', 'neither'],
	['Змены ў даце стварэння', 'neither'],
	// ---- must read NEITHER: the "-ш" trap. "ваш" is the formal possessive and
	// would invert the verdict; the rest are ordinary nouns and adverbs.
	['Паказаць больш', 'neither'],
	['Менш падрабязнасцяў', 'neither'],
	['Найбольш ужывальныя', 'neither'],
	['%n запіс яшчэ не мае хэша', 'neither'],
	['Не наладжаны кэш памяці', 'neither'],
	['Спалучэнне клавіш', 'neither'],
	['Перш чым працягнуць', 'neither'],
	['Праверце наш блог', 'formal'],
	['Захавана на нашым серверы', 'neither'],
	// ---- must read NEITHER: "гэты"/"гэтыя" must not surrender a bare "ты", and
	// the "вы-" verbal prefix must not surrender a bare "вы".
	['Гэты запіс нельга аднавіць', 'neither'],
	['Гэтыя аб\'екты будуць выдалены назаўсёды', 'neither'],
	['Вынік вылічэння', 'neither'],
	['Выдаленыя элементы', 'neither'],
	['Выкарыстанне SQLite', 'neither'],
	// ---- must read NEITHER: "ШІ" is the AI acronym, not a marker of any kind.
	['ШІ-агент недаступны', 'neither'],
	['✨ ШІ-функцыі', 'neither'],
	['Канфігурацыя ШІ і ўбудаванняў', 'neither'],
	// ---- must read NEITHER: impersonal and 1pl forms carry no addressee.
	['Трэба наладзіць пастаўшчыка ўбудаванняў', 'neither'],
	['Можна выбраць некалькі схем', 'neither'],
	// ---- THE PREFIX/SUFFIX PAIR. Belarusian builds the 2pl imperative as the 2sg
	// plus "-це", so these differ by one syllable and by two registers. If the
	// trailing guard were dropped, every formal control above would score informal.
	['Выберыце схему', 'formal'],
	['Выберы схему', 'informal'],
	['Захавайце змены', 'formal'],
	['Захавай змены', 'informal'],
	['Увядзіце URL', 'formal'],
	['Увядзі URL', 'informal'],
	// ---- mixed sanity: one informal marker inside otherwise formal prose wins.
	['Выберыце рэестр, а потым захавай свае змены', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Май на ўвазе, што гэта незваротна', '"май" is the 2sg imperative of "мець" '
		+ 'AND the month name. It is the one 2sg imperative left out of the closed '
		+ 'list; its 2pl counterpart "майце" is safe and is counted, so the loss is '
		+ 'one-sided — an informal "май" reads as neither, a formal "майце" reads as '
		+ 'formal'],
	['Гэта датычыцца ўсіх вас', 'FALSE POSITIVE the other way — "вас" is 2pl '
		+ 'accusative whether it defers to one person or addresses several plainly, '
		+ 'and Belarusian gives no way to tell them apart. Counted as formal, which '
		+ 'is right for a single-user UI'],
	['Трэба спачатку наладзіць мадэль', 'impersonal "трэба"/"можна"/"неабходна" '
		+ 'and 1pl "просім" carry no address at all, so a whole screen can be written '
		+ 'impersonally and score zero of either polarity. That is not a defect — it '
		+ 'is the register-neutral shape, the same status the infinitive has in labels'],
	['Кінь гэта і паспрабуй іначай', 'a 2sg imperative outside the enumerated list '
		+ 'is invisible. The list covers the app\'s own action vocabulary and core\'s, '
		+ 'so an informal slip in an ordinary verb the app never uses would be missed; '
		+ 'the "-ы"/"-і" ending cannot be generalised because it is also the commonest '
		+ 'plural and genitive-singular ending in the language'],
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
	reportCoreRegister('be', scanCoreRegister('be', score), { formal: 'вы', informal: 'ты' })
}
