/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Turkish register detector, used both to measure core and to gate my own patches.
// Polarity: flags the DEVIATION. Core is formal (siz), so an informal hit is a defect.
//
// Two traps this encodes:
//  1. Turkish plural genitive and 2sg possessive are homographs: "dosyaların" is
//     BOTH "of the files" and "your files". Any noun+lAr+In form is unusable as an
//     informal marker. Only singular-stem 2sg possessives ("şifren") are safe.
//  2. Case folding: JS /i/ui does not match "İ", and 'İ'.toLowerCase() yields
//     i + U+0307. Fold Turkish letters explicitly before matching.

function fold(s) {
	return s.replace(/İ/g, 'i').replace(/I/g, 'ı').toLowerCase()
}

// 2pl (formal) — the marker set is closed and each entry is unambiguous.
const FORMAL_RES = [
	/(?<!\p{L})\p{L}+[ae]bilirsiniz(?!\p{L})/gu,
	/(?<!\p{L})\p{L}+[iıuü]n[iıuü]z(?!\p{L})/gu, // -InIz: 2pl possessive/imperative
	/(?<!\p{L})(?:yapın|girin|seçin|bekleyin|deneyin|kullanın|tıklayın|edin|verin|alın|açın|kapatın|gönderin|kaydedin|silin|ekleyin|okuyun|bulun|başlayın|durdurun|yenileyin|güncelleyin|bırakın|gidin|olun|bakın|yazın|taşıyın|indirin|yükleyin)(?!\p{L})/gu,
	/(?<!\p{L})(?:siz|size|sizin|sizi|sizden|sizde)(?!\p{L})/gu,
]

// 2sg (informal). Deliberately narrow: only forms with no genitive reading.
const INFORMAL_RES = [
	/(?<!\p{L})\p{L}+[ae]bilirsin(?!\p{L})/gu,
	/(?<!\p{L})\p{L}+[iıuü]yorsun(?!\p{L})/gu,
	// Case-inflected 2sg possessives. The trailing [iıea]? picks up the accusative
	// ("şifreni") and dative ("şifrene"), which the bare form misses.
	//
	// Dropped from this list: hesabın, takvimin, oturumun, dosyaların. Turkish
	// 2sg-possessive and 3sg-possessive+accusative are homographs — "hesabını" is
	// both "your account" and "the account of X" — as are plural genitive and 2sg
	// possessive ("dosyaların" = "of the files" / "your files"). Every one of the
	// 35 informal hits in the first pass over core was one of those, i.e. noise.
	// şifre/parola have no such collision: the 3sg forms are şifresini/parolasını.
	/(?<!\p{L})(?:şifren|parolan)[iıea]?(?!\p{L})/gu,
	/(?<!\p{L})(?:sen|sana|senin|seni|senden|sende)(?!\p{L})/gu,
]

function score(s) {
	const t = fold(s)
	let f = 0
	let i = 0
	// Fresh regex per call: a reused /g/ carries lastIndex and turns later
	// matches into silent misses.
	for (const re of FORMAL_RES) f += (t.match(new RegExp(re.source, re.flags)) || []).length
	for (const re of INFORMAL_RES) i += (t.match(new RegExp(re.source, re.flags)) || []).length
	return { f, i }
}

const CONTROLS = [
	// must fire formal
	['Lütfen bekleyin.', 'formal'],
	['Şifrenizi girin', 'formal'],
	['Dosyalarınızı buraya bırakın', 'formal'],
	['Bunu yapabilirsiniz', 'formal'],
	['Hesabınıza gidin', 'formal'],
	['Ayarlarınızı açın', 'formal'],
	// must fire informal
	['Şifreni gir', 'informal'],
	['Şifrene bak', 'informal'],
	['Bunu yapabilirsin', 'informal'],
	['Ne yapıyorsun', 'informal'],
	// must fire neither — genitive homographs and plain prose
	['Dosyanın boyutu', 'neither'],
	['Sunucunun adresi', 'neither'],
	['Kullanıcının adı', 'neither'],
	['Grubun üyeleri', 'neither'],
	['Klasörler dosyaların üzerinde sıralansın', 'neither'],
	['bu paylaşımın geçerlilik süresi dolmuş.', 'neither'],
	['Oturum açmış hesabın e-posta adresi yok', 'neither'],
	['Nesne başarıyla silindi', 'neither'],
	['Ayarlar', 'neither'],
	['Şema', 'neither'],
	['Kayıt defteri', 'neither'],
]

// Informal forms this detector CANNOT see, and why. Recorded rather than left as
// failing controls: no regex over Turkish orthography can resolve them, because
// the 2sg-possessive and the 3sg-possessive+case forms are spelled identically.
// A human reviewing an informal-register complaint should check these by hand.
const UNDETECTABLE = [
	['Hesabına git', '"hesabına" = "to your account" (2sg) and "to the account of X" (3sg)'],
	['Dosyaların paylaşıldı', '"dosyaların" = "your files" (2sg) and "of the files" (plural genitive)'],
	['Takvimini aç', '"takvimini" = "your calendar" (2sg) and "the calendar of X" (3sg)'],
]

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
	reportCoreRegister('tr', scanCoreRegister('tr', score), { formal: 'siz', informal: 'sen' })
}
