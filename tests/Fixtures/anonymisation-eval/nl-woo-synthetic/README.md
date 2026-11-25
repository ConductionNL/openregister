# nl-woo-synthetic, version 1

A synthetic Dutch evaluation corpus for `occ openregister:anonymisation:evaluate`
(openspec change `anonymisation-discloses-itself`, REQ-ADI-002).

**It holds no real personal data.** Every name, address, organisation, e-mail
address, telephone number, IBAN and BSN was made up for this file:

- Texts were written by hand in the style of Woo decisions and letters.
- Names and organisations are invented; addresses combine common street names
  with postcodes that are not checked against any register.
- E-mail addresses use the reserved `example.org`, `example.com` and
  `example.net` domains (RFC 2606).
- BSNs are taken from the test range 999990000 to 999999999 that is reserved
  for testing, and each passes the elfproef.
- IBANs are built on test account numbers with a computed check digit, so they
  are well-formed but name no real account.

`corpus.json` holds, per document, the text and its gold annotations. Each
annotation has a `type` (PERSON, EMAIL, PHONE, IBAN, BSN, ADDRESS or
ORGANIZATION), the `value`, and `start` and `end` as **byte** offsets into the
UTF-8 text (`end` exclusive), the same unit the detectors report. The offsets
were computed by a script from inline markup, not typed by hand;
`tests/Unit/Service/Anonymisation/EvaluationCorpusTest.php` checks that every
span cuts its `value` out of its text.

Change a text or an annotation and the figures change with it: raise `version`
in `corpus.json`, so a stored evaluation never describes a different corpus.
