 
// Czech register detector for openregister l10n.
//
// Measures the prose register AND the 2sg imperative. Czech labels its buttons
// with the INFINITIVE ("Uložit", "Smazat", "Přidat" — 27 of the resolved action
// keys in core cs, zero imperatives), so a bare 2sg imperative is not a
// competing label convention here, it is familiar address. That is the same
// situation as Slovak and the opposite of hr/ca/et/sl/sr, where counting the
// imperative would flag every button.
//
// THE BOUNDARY GUARD IS THE WHOLE DETECTOR, and Czech makes it more load-bearing
// than any locale done so far. Two independent reasons, both measured:
//
//   • EVERY Czech 2sg imperative is a PROPER PREFIX of its 2pl counterpart,
//     because the 2pl is formed by suffixing -te: vyber/vyberte,
//     zadej/zadejte, přidej/přidejte, nastav/nastavte, zobraz/zobrazte,
//     použij/použijte, ponech/ponechte, zvol/zvolte, smaž/smažte. This bundle
//     contains 64 "vyberte" and core 48 "zadejte". An unguarded 2sg list would
//     therefore score the single commonest FORMAL shape in the corpus as
//     informal and invert the verdict outright — a far bigger hazard than the
//     Slovak "vy-" prefix, because it hits the markers themselves rather than
//     unrelated vocabulary.
//   • The informal possessive "tvá" is a SUBSTRING OF "vytvářet"/"vytváření"
//     ("to create"/"creating"), which a raw scan finds 48 times across core and
//     this bundle and which is one of the commonest verbs in this app. Same
//     polarity-inverting shape. "vytvoř" (2sg imperative) sits inside
//     "vytvoření" the same way.
//
// Several stems are also prefixes of the app's own domain nouns: "nastav" ⊂
// "nastavení" (the commonest noun in the bundle), "zobraz" ⊂ "zobrazení",
// "obnov" ⊂ "obnovení", "ulož" ⊂ "uložené", "smaž" ⊂ "smazané". All are
// must-not-fire controls below.
//
// DIACRITICS ARE NOT FOLDED, as in sk.js and sl.js, and three live distinctions
// ride on that:
//   • "vyber" (2sg imperative) vs "výběr" ("selection", 10 in core / 4 here);
//   • "uprav" (2sg imperative) vs "úprav" (genitive plural of "úprava");
//   • "změň" (2sg imperative) vs "změn" (genitive plural of "změna", 6 in core).
// fold() therefore only lowercases.
//
// BARE "ty" AND "ti" ARE DELIBERATELY UNMATCHED — §8.2. Both are at once the
// informal pronoun and the plural demonstrative ("ty soubory" = "those files",
// "ti uživatelé" = "those users"), and unlike Slovak — where the acute splits
// dative "ti" from demonstrative "tí" — Czech spells the two identically, so
// there is no diacritic to recover the distinction. Measured: "ty" occurs 6
// times in core, every one the demonstrative; "ti" occurs 0 times. Recall is
// recovered from the oblique forms (tě, tebe, tobě, tebou), the possessive and
// the verbs, exactly as §8.2 prescribes for cs/hr/sl.
//
// BARE "si" IS UNMATCHED, and for a DIFFERENT reason from hr/sk/sl. In those
// languages "si" is the 2sg of "to be" as well as the reflexive dative clitic,
// so it is an ambiguous informal marker. Czech's 2sg of "být" is "jsi", so "si"
// is ONLY the reflexive clitic — it carries no address information in either
// direction and there is nothing to disambiguate. It occurs 49 times in core and
// 8 here, always reflexive ("můžete si vybrat"). A useful negative: do not port
// the hr/sk/sl "ambiguous si" reasoning to Czech.
//
// Czech "prosím" ("please") is a 1sg present verb — it inflects for the SPEAKER,
// not the addressee — so unlike Maltese "jekk jogħġbok" it carries no address
// marker and gives no free signal (§8.1). In "Počkejte prosím" the register is
// carried by "počkejte" alone.
//
// JS \b is ASCII-only and would treat "ě" as a boundary, so every guard is
// (?<!\p{L}) … (?!\p{L}) with the u flag.

/**
 *
 * @param s
 */
function fold(s) {
	return String(s).toLowerCase()
}

// vy / formal 2pl.
const FORMAL_RES = [
	// Boundary-guarded so the highly productive vy- verbal prefix cannot match
	// (vybrat, vymazat, vytvořit, vyhledat, vypnout, vyčistit, vypočítat).
	// "vás"/"vám"/"vámi" have no other reading; bare "vy" is only the pronoun.
	/(?<!\p{L})(?:vy|vás|vám|vámi)(?!\p{L})/gu,
	// váš- possessive, all cases. The guard keeps "náš"/"naše" out, and the
	// "váš"/"vaš-" split is real Czech alternation.
	/(?<!\p{L})(?:váš|vaše|vaší|vaši|vašeho|vašemu|vaším|vašem|vašich|vašim|vašimi)(?!\p{L})/gu,
	// closed list of 2pl present-indicative forms. NOT a "-te" rule.
	/(?<!\p{L})(?:můžete|nemůžete|musíte|nemusíte|chcete|nechcete|víte|nevíte|vidíte|nevidíte|máte|nemáte|potřebujete|nepotřebujete|jste|nejste|budete|nebudete|dostanete|používáte|najdete|nenajdete|uvidíte|znáte|smíte|chtěli|přejete)(?!\p{L})/gu,
	// closed list of 2pl imperatives that actually recur in Nextcloud UI prose.
	// Every one is the 2sg form plus -te; see the header on why the trailing
	// guard is what keeps the two lists apart.
	/(?<!\p{L})(?:zadejte|vyberte|zvolte|zkontrolujte|počkejte|klikněte|uložte|smažte|vymažte|přidejte|otevřete|zavřete|použijte|zkuste|pošlete|potvrďte|pokračujte|změňte|odstraňte|odeberte|zkopírujte|podívejte|prohlédněte|nastavte|vytvořte|načtěte|obraťte|postupujte|vyplňte|nainstalujte|zapněte|vypněte|přihlaste|odhlaste|ujistěte|udělejte|přejděte|přečtěte|napište|aktualizujte|kontaktujte|mějte|buďte|spravujte|filtrujte|zobrazte|exportujte|importujte|analyzujte|konfigurujte|ponechte|získejte|začněte|opakujte|obnovte|ověřte|zrušte|dokončete|vložte|přesuňte|přejmenujte|stiskněte|všimněte|zvažte|sledujte|hledejte|najděte|doplňte|upravte|definujte|přiřaďte|nahrajte|stáhněte|spusťte|zastavte|publikujte|testujte|synchronizujte|aktivujte|deaktivujte|zakažte|povolte|rozšiřte|vyčistěte|vypočítejte|extrahujte|generujte|identifikujte|porovnejte|zjistěte|zahrňte|uchovávejte|zapisujte|čekejte|sdílejte|vyhledejte|zapečeťte|archivujte|vyřizujte|zaznamenejte|vraťte|řešte|nahlédněte|zvyšte|snižte)(?!\p{L})/gu,
]

// ty / informal 2sg — the DEVIATION this gate looks for.
const INFORMAL_RES = [
	// Bare "ty"/"ti" are deliberately ABSENT — both are the plural demonstrative
	// as well as the pronoun, and Czech offers no diacritic to split them (§8.2).
	// Only the unambiguous oblique forms are matched.
	/(?<!\p{L})(?:tě|tebe|tobě|tebou)(?!\p{L})/gu,
	// tvůj- possessive, all cases. Enumerated rather than stemmed, because "tvá"
	// is a substring of "vytváření" — see the header.
	/(?<!\p{L})(?:tvůj|tvá|tvé|tvého|tvému|tvým|tvém|tvou|tvých|tvými|tvoje|tvojí|tvoji)(?!\p{L})/gu,
	// 2sg present of the highest-frequency modal/perception verbs. "jsi" is the
	// 2sg of "být" and unambiguous; bare "si" is deliberately absent (header).
	/(?<!\p{L})(?:jsi|nejsi|můžeš|nemůžeš|musíš|nemusíš|chceš|nechceš|víš|nevíš|vidíš|nevidíš|máš|nemáš|potřebuješ|nepotřebuješ|budeš|nebudeš|dostaneš|používáš|najdeš|nenajdeš|uvidíš|znáš|smíš|klikneš|vybereš)(?!\p{L})/gu,
	// closed list of 2sg imperatives. Safe to detect in Czech because the button
	// convention is the infinitive (-t), so no label can be mistaken for one —
	// but ONLY with the trailing guard, since each is a prefix of the 2pl form.
	/(?<!\p{L})(?:ulož|přidej|vyber|odstraň|odeber|uprav|zruš|zavři|otevři|zkus|počkej|zadej|klikni|použij|vytvoř|nastav|zobraz|přečti|napiš|pošli|potvrď|pokračuj|změň|zkopíruj|obnov|ověř|spusť|zastav|smaž|vymaž|načti|vyplň|nainstaluj|zapni|vypni|udělej|přejdi|vyhledej|filtruj|spravuj|analyzuj|konfiguruj|exportuj|importuj|testuj|publikuj|sdílej|vyčisti|vypočítej|začni|opakuj|dokonči|zvol|ponech|získej|hledej|najdi|doplň|definuj|přiřaď|nahraj|stáhni|archivuj|zaznamenej|přesuň|přejmenuj|stiskni|zvaž|sleduj|vrať|řeš|zahrň|porovnej|zjisti)(?!\p{L})/gu,
]

const CONTROLS = [
	// ---- must read FORMAL (vy prose). Every one is a real value from this
	// bundle or from core cs.
	['Vyberte registr', 'formal'],
	['Zadejte popis nastavení (nepovinné)', 'formal'],
	['Počkejte prosím, než načteme vaše konfigurace.', 'formal'],
	['Spravujte své datové registry a jejich konfigurace', 'formal'],
	['Zvolte, které pohledy zahrnout do procesu vektorizace.', 'formal'],
	['Nastavte tokeny API pro propojení s externími službami', 'formal'],
	['Konfigurujte způsob převodu objektů na text před vektorizací.', 'formal'],
	['Opravdu chcete odebrat schéma "{schema}" z registru "{register}"?', 'formal'],
	['Pro přidání pohledů do oblíbených musíte být přihlášeni', 'formal'],
	['Nemáte oprávnění k této akci', 'formal'],
	['Váš klíč API OpenAI. Získejte jej na', 'formal'],
	['Zkontrolujte svou konfiguraci notify_push.', 'formal'],
	['Zapněte tam, kde je vyžadován doklad o souladu.', 'formal'],
	['Dobu uchování lze nastavit u každého schématu a najdete ji v jeho nastavení.', 'formal'],
	['Vyberte výše registr a schéma, abyste zobrazili jeho statistiky kvality.', 'formal'],
	// the reflexive-dative "si" must not flip a formal sentence, and in Czech it
	// is not an informal marker at all — see the header.
	['Pokud jste si jisti, pokračujte', 'formal'],
	['Můžete si vybrat jiný registr', 'formal'],

	// ---- must read INFORMAL (ty prose / 2sg imperative) — the deviation.
	// Constructed, because the corpus contains zero informal markers.
	['Tvůj registr', 'informal'],
	['Můžeš to změnit později', 'informal'],
	['Pokud chceš, zkus to znovu', 'informal'],
	['Ovlivní to tvá data', 'informal'],
	['Posláno tobě', 'informal'],
	['Uvidíš to v přehledu', 'informal'],
	['Jsi přihlášen', 'informal'],
	['Nemáš oprávnění k této akci', 'informal'],
	['Zadej popis', 'informal'],
	['Ulož změny', 'informal'],
	['Přidej aplikaci', 'informal'],
	['Odstraň objekt', 'informal'],
	['Vyber registr', 'informal'],
	['Nastav tokeny API', 'informal'],
	['Zobraz protokol', 'informal'],
	['Zvol typ nastavení', 'informal'],

	// ---- must read NEITHER: the INFINITIVE is the correct Czech label
	// convention, and several begin with the vy- prefix. Without the pronoun
	// guard most of these would score formal.
	['Uložit', 'neither'],
	['Smazat', 'neither'],
	['Přidat', 'neither'],
	['Upravit', 'neither'],
	['Zrušit', 'neither'],
	['Zavřít', 'neither'],
	['Zkopírovat', 'neither'],
	['Vytvořit registr', 'neither'],
	['Vybrat vše', 'neither'],
	['Vyhledat webhooky', 'neither'],
	['Vyčistit staré záznamy', 'neither'],
	['Vypočítat velikosti', 'neither'],
	['Vypnout', 'neither'],
	['Exportovat konfiguraci', 'neither'],
	['Zobrazit dokumentaci API', 'neither'],
	['Hledat nastavení', 'neither'],

	// ---- must read NEITHER: the PREFIX traps. Each of these contains a 2sg
	// imperative or the informal possessive as a leading substring, and each
	// would invert the verdict if the trailing guard were missing. See header.
	['Vytváření {name}…', 'neither'],
	['Automaticky generovat vnoření při vytvoření objektů', 'neither'],
	['Nastavení tokenů API', 'neither'],
	['Zobrazení objektů', 'neither'],
	['Obnovení hesla', 'neither'],
	['Uložené konfigurace vyhledávání', 'neither'],
	['Smazané položky', 'neither'],
	['Načítají se konfigurace...', 'neither'],
	['Archivovat tuto činnost zpracování?', 'neither'],

	// ---- must read NEITHER: the "-š" trap. "náš" is not a 2sg verb. The formal
	// counterpart "váš" is tested above.
	['Náš registr', 'neither'],
	['Naše nastavení', 'neither'],

	// ---- must read NEITHER: third-person prose ABOUT the user. This looks like
	// address and is not — "mohou" is 3pl, and "si"/"svém" are person-neutral, so
	// nothing here names an addressee. A real value from this bundle.
	['Uživatelé si mohou notifikace zapnout ve svém osobním nastavení.', 'neither'],

	// ---- must read NEITHER: the three distinctions carried by diacritics
	// alone. This block is the reason fold() does not strip them.
	['Výběr typu souboru', 'neither'],
	['Vymazat výběr', 'neither'],
	['Seznam změn', 'neither'],
	['Zpracování úprav', 'neither'],

	// ---- mixed sanity: one informal marker inside otherwise formal prose wins
	['Vyberte registr a potom ulož změny', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Dáme ti vědět', 'bare "ti" is at once the dative of "ty" and the masculine '
		+ 'animate nominative plural of "ten" ("ti uživatelé" = "those users"). '
		+ 'Czech spells the two identically — unlike Slovak, where the acute splits '
		+ 'dative "ti" from demonstrative "tí" — so it is left unmatched (§8.2)'],
	['Ty soubory jsou tvoje', 'bare "ty" is the informal nominative AND the plural '
		+ 'demonstrative; all 6 core occurrences are the demonstrative. Only the '
		+ '"tvoje" here is detected, so this particular value is caught, but a '
		+ 'value carrying "ty" alone would not be'],
	['Nezapomeň na to', 'negated 2sg imperatives are formed with ne- on an open set '
		+ 'of verbs; only the affirmative forms are enumerated'],
	['Své nastavení najdeš tam', '"své" is person-neutral, so only the verb carries '
		+ 'the 2sg — covered here by "najdeš", but a nominal sentence would not be'],
	['Udělals to', 'the enclitic -s contraction of "jsi" ("udělal jsi" -> '
		+ '"udělals") is a colloquial form appended to an open set of past '
		+ 'participles, so it cannot be enumerated'],
]

/**
 *
 * @param s
 */
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

/**
 *
 */
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
	reportCoreRegister('cs', scanCoreRegister('cs', score), { formal: 'vy', informal: 'ty' })
}
