/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Bulgarian register detector for openregister l10n.
//
// Measures the PROSE register. Bulgarian labels take the VERBAL NOUN
// (отглаголно съществително, "Запазване" / "Изтриване" / "Създаване"), which is
// person-neutral and therefore invisible to a register detector — the same
// comfortable situation sk has with its infinitive, and the opposite of
// ca/et/hr/sl where the label form is itself a finite verb.
//
// THE 2SG IMPERATIVE IS HALF-DETECTABLE HERE, which is new. The other nine
// recorded locales are all-or-nothing: sk counts the imperative, ca/et/hr/sl
// exclude it entirely. Bulgarian splits along conjugation class:
//
//   • и-conjugation (-я/-иш) imperatives are UNUSABLE. "запази" is at once the
//     2sg imperative, the 3sg present ("може да запази") and the 3sg aorist
//     ("той запази"). Both readings occur in this bundle's own prose:
//     "Анализът завърши:" and "Изтриването завърши" are aorists spelled exactly
//     like the imperatives of "завърша". Same for провери / добави / отвори /
//     потвърди / запази.
//   • а-conjugation (-ай/-вай/-й) imperatives ARE unambiguous, because the 3sg
//     present of those verbs ends in -а/-ва: "опитай" vs "опитва", "изпълнявай"
//     vs "изпълнява", "записвай" vs "записва", "копирай" vs "копира". Nothing
//     else in the language is spelled that way.
//
// So the -й class is enumerated as informal and the -и class is recorded in
// UNDETECTABLE. That split is not cosmetic: it is what catches the two informal
// slips this bundle already shipped ("Изпълнявай надзорни проверки…",
// "Записвай одитен запис…"), which a whole-class exclusion would have missed.
//
// Why closed word lists and NOT suffix patterns, specifically for Bulgarian:
//   • "-те" is the 2pl ending, present and imperative alike — and it is ALSO the
//     DEFINITE PLURAL ARTICLE, the single most common morpheme in the language.
//     "файловете", "обектите", "потребителите", "Членовете", "настройките" are
//     all nouns. A "-те" rule scores essentially every plural noun phrase in the
//     app as formal prose and the measurement becomes noise.
//   • "-ш" looks like the 2sg present ending, but "ваш" (your-FORMAL) and "наш"
//     (our) both end in it, so a "-ш" rule reads the formal possessive as
//     informal and inverts the polarity outright — the same trap as hr, sk and sl.
//   • bare "те" is unusable: besides the 2sg accusative clitic ("изпраща те") it
//     is the 3pl pronoun "they". This bundle already says "Те могат да бъдат
//     възстановени по-късно" about objects.
//   • bare "си" is unusable: besides the 2sg of "съм" it is the reflexive
//     possessive clitic, which is at its most frequent in FORMAL prose —
//     "за да прецизирате търсенето си".
//   • "трябва" is not a person marker at all. It is impersonal 3sg; the register
//     in "Трябва да сте влезли" is carried by "сте", not by "трябва".
// Bare "ти" IS usable, unlike the Slavic locales done so far: Bulgarian lost its
// case system and its demonstrative plural is "тези"/"тия", so "ти" has no
// demonstrative reading the way cs "ty", hr "ti" and sl "ti" do.
//
// JS \b is ASCII-only and would treat "ъ" as a boundary, so every guard is
// (?<!\p{L}) … (?!\p{L}) with the u flag.

function fold(s) {
	return String(s).toLowerCase()
}

// вие / formal 2pl. Bulgarian capitalises the polite pronoun in careful writing
// ("Вие", "Ваш", postposed "Ви"), and this bundle does; either casing is the
// same register in a UI string, so the input is folded first.
const FORMAL_RES = [
	/(?<!\p{L})(?:вие|ви|вас)(?!\p{L})/gu,
	// ваш- possessive with the definite-article endings. The (?<!\p{L}) guard is
	// what keeps this off "наш"; there is no shared prefix to worry about.
	/(?<!\p{L})ваш(?:ият|ия|ата|ето|ите|а|о|е|и)?(?!\p{L})/gu,
	// Closed list of 2pl present-indicative forms. NOT a "-те" rule — see header.
	/(?<!\p{L})(?:имате|нямате|сте|искате|желаете|знаете|виждате|можете|нуждаете|получавате|трябвате|разполагате|очаквате|предпочитате|въвеждате|избирате|използвате|прецизирате|стесните|запазите|решите|видите|започнете|добавяте|обработите|активирате|конфигурирате|намалите|осигурите)(?!\p{L})/gu,
	// Closed list of 2pl imperatives. Bulgarian spells the 2pl imperative and the
	// 2pl present alike for most verbs and both are formal, so the two lists above
	// and below overlap in function and are split only for readability.
	/(?<!\p{L})(?:изберете|въведете|проверете|изчакайте|натиснете|щракнете|кликнете|запазете|изтрийте|добавете|премахнете|отворете|затворете|копирайте|покажете|скрийте|изпратете|потвърдете|продължете|опитайте|използвайте|конфигурирайте|задайте|управлявайте|контролирайте|активирайте|деактивирайте|инсталирайте|деинсталирайте|оставете|прочетете|прегледайте|преглеждайте|анализирайте|създайте|актуализирайте|обновете|включете|изключете|задействайте|филтрирайте|сортирайте|свържете|обърнете|следвайте|попълнете|влезте|излезте|уверете|направете|отидете|напишете|посетете|вижте|помислете|наблюдавайте|търсете|намерете|определете|назначете|изтеглете|качете|стартирайте|спрете|публикувайте|тествайте|синхронизирайте|разрешете|забранете|разширете|валидирайте|архивирайте|опреснете|изчистете|изпълнете|генерирайте|векторизирайте|импортирайте|експортирайте|преименувайте|преместете|възстановете|отменете|повторете|изберете|дайте|бъдете|имайте|съобщете|свържете|уведомете|отбележете|сравнете|разделете|обединете|ограничете|заменете|одобрете|отхвърлете|въведете|очаквайте|подгответе|настройте|нулирайте|препратете|споделете|отпишете|запишете)(?!\p{L})/gu,
]

// ти / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	// bare "те" and bare "си" deliberately absent — both have common non-2sg
	// readings; see the header. Bare "ти" is kept, and is safe in Bulgarian.
	/(?<!\p{L})(?:ти|теб|тебе)(?!\p{L})/gu,
	/(?<!\p{L})тво(?:й|я|ят|ята|ето|ите|и)(?!\p{L})/gu,
	// Closed list of 2sg present-indicative forms. NOT a "-ш" rule, which would
	// score the formal "ваш" as informal.
	/(?<!\p{L})(?:имаш|нямаш|искаш|желаеш|знаеш|виждаш|можеш|нуждаеш|получаваш|разполагаш|очакваш|предпочиташ|въвеждаш|избираш|използваш|прецизираш|стесниш|запазиш|решиш|видиш|започнеш|добавяш|обработиш|активираш|конфигурираш|избереш|провериш|изтриеш|добавиш|премахнеш|отвориш|затвориш|копираш|покажеш|скриеш|изпратиш|потвърдиш|продължиш|опиташ|изчакаш|зададеш|управляваш|инсталираш|прочетеш|създадеш|актуализираш|обновиш|включиш|изключиш|филтрираш|намериш|изтеглиш|стартираш|спреш|публикуваш|тестваш|валидираш|опресниш|изчистиш|изпълниш|влезеш|излезеш|направиш|отидеш|напишеш|посетиш)(?!\p{L})/gu,
	// 2sg imperatives of the а-conjugation ONLY. Unambiguous because the 3sg
	// present of these verbs ends in -а/-ва ("опитва", "изпълнява", "записва"),
	// so no other form in the language collides. The и-conjugation imperative
	// ("запази", "провери") is NOT here — it is a 3sg present AND aorist
	// homograph; see UNDETECTABLE.
	/(?<!\p{L})(?:опитай|използвай|конфигурирай|актуализирай|копирай|изпълнявай|записвай|разглеждай|преглеждай|управлявай|филтрирай|сортирай|тествай|синхронизирай|валидирай|архивирай|стартирай|инсталирай|деинсталирай|активирай|деактивирай|генерирай|векторизирай|анализирай|изчаквай|обработвай|проверявай|добавяй|изтривай|запазвай|показвай|скривай|задавай|избирай|въвеждай|изчиствай|потвърждавай|продължавай|натискай|отваряй|затваряй|изпращай|получавай|следвай|попълвай|чакай|изчакай|гледай|намирай|определяй|назначавай|изтегляй|качвай|спирай|публикувай|разрешавай|разширявай|опреснявай|импортирай|експортирай|преименувай|премествай|възстановявай|отменяй|повтаряй|споделяй|запиши|нулирай|настройвай|уведомявай|отбелязвай|сравнявай|разделяй|обединявай|ограничавай|заменяй|одобрявай|отхвърляй)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (вие prose) — all real values from this bundle or core bg
	['Изберете регистър', 'formal'],
	['Въведете описание (по избор)...', 'formal'],
	['Моля, изчакайте, докато извличаме Вашите конфигурации.', 'formal'],
	['Управлявайте Вашите регистри с данни и техните конфигурации', 'formal'],
	['Използвайте филтри, за да стесните записите на одитни следи', 'formal'],
	['Трябва да сте влезли, за да добавяте изгледи към любими', 'formal'],
	['Нуждаете се от AI агент, за да започнете разговор.', 'formal'],
	['Вашият OpenAI API ключ. Вземете такъв на', 'formal'],
	['Изберете хранилище, до което имате достъп за запис', 'formal'],
	['Моля, опитайте отново по-късно.', 'formal'],
	['Прочетете записа, преди да решите.', 'formal'],
	['Въведете, за да търсите групи', 'formal'],
	['Сигурни ли сте, че искате да изтриете', 'formal'],
	['Няма записи на одитни следи, отговарящи на текущите Ви филтри.', 'formal'],
	['Оставете празно, за да обработите всички изгледи според Вашата конфигурация.', 'formal'],
	['Задайте 0, за да обработите всички файлове.', 'formal'],
	// the reflexive-possessive "си" must not flip a formal sentence to informal
	['Използвайте филтрите по-долу, за да прецизирате търсенето си.', 'formal'],
	// must read informal (ти prose) — the deviation
	['Твоят регистър', 'informal'],
	['Можеш да промениш това по-късно', 'informal'],
	['Ако искаш, опитай отново', 'informal'],
	['Това засяга твоите данни', 'informal'],
	['Изпратено до теб', 'informal'],
	['Знаеш ли къде е файлът?', 'informal'],
	['Провери настройките си, ако имаш проблеми', 'informal'],
	// the two informal slips this bundle actually shipped. а-conjugation
	// imperatives, and the reason the -й class is enumerated at all.
	['Изпълнявай надзорни проверки преди всяка стъпка', 'informal'],
	['Записвай одитен запис за всяка стъпка', 'informal'],
	// must read NEITHER: the verbal noun IS the Bulgarian label convention. If
	// these fired, every button in the app would flag.
	['Запазване', 'neither'],
	['Изтриване', 'neither'],
	['Създаване', 'neither'],
	['Копиране', 'neither'],
	['Опресняване', 'neither'],
	['Актуализиране', 'neither'],
	['Затваряне', 'neither'],
	['Изчистване на всички филтри', 'neither'],
	['Преглед на пълните детайли', 'neither'],
	['Избор на всички', 'neither'],
	// must read NEITHER: the definite-article "-те" trap. Every one of these is a
	// plural noun phrase, not a 2pl verb.
	['Изтриване на всички обекти в тази схема', 'neither'],
	['Членовете на избраните групи могат да получат достъп до този изглед', 'neither'],
	['Настройките на потока са обновени', 'neither'],
	['Неуспешно зареждане на групите в Nextcloud', 'neither'],
	['Забавянията се удвояват с всеки опит', 'neither'],
	// must read NEITHER: bare "те" is the 3pl pronoun "they" — a real value here
	['Те могат да бъдат възстановени по-късно, ако е необходимо.', 'neither'],
	// must read NEITHER: the "-ш" trap. "наш" is not a 2sg verb.
	['Наш регистър', 'neither'],
	['Общ брой на нашите схеми', 'neither'],
	// must read NEITHER: "брой" is the noun "count" as well as an imperative, and
	// it is a noun in every place this bundle uses it
	['Брой обекти за обработка във всяка партида', 'neither'],
	['Максимален брой резултати', 'neither'],
	// must read NEITHER: и-conjugation aorists spelled exactly like imperatives.
	// Both are real values from this bundle.
	['Анализът завърши:', 'neither'],
	['Изтриването завърши', 'neither'],
	['Проверката спря при запис {id}', 'neither'],
	// must read NEITHER: "трябва" is impersonal, and carries no person at all
	['LLM трябва да бъде активиран с конфигуриран доставчик на вграждания', 'neither'],
	// mixed sanity: one informal marker inside otherwise formal prose wins
	['Изберете регистър и след това запиши промените', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Запази промените си', 'и-conjugation 2sg imperative — spelled exactly like '
		+ 'the 3sg present ("може да запази") and the 3sg aorist ("той запази"); '
		+ '"си" is person-neutral, so nothing here marks the number'],
	['Провери веригата', 'same: "провери" is also the 3sg present and aorist of '
		+ '"проверя"'],
	['Добави схема', 'same: "добави" is also the 3sg present and aorist of '
		+ '"добавя"'],
	['Изтрий обекта', 'the -й imperative of an е-conjugation verb. Unambiguous as '
		+ 'a form, but it is ALSO one of the two Bulgarian label conventions core '
		+ 'itself uses (core bg ships "Изтрий" in three catalogues), so counting it '
		+ 'would flag correct buttons rather than register slips'],
	['Вие сте на село', 'FALSE POSITIVE the other way — none of the bg pronouns '
		+ 'has a non-pronoun reading, which is why this locale needs no equivalent '
		+ 'of the sl "vas"/village caveat'],
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
	reportCoreRegister('bg', scanCoreRegister('bg', score), { formal: 'вие', informal: 'ти' })
}
