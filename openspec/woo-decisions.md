# Woo decisions

Ruben's answers to the thirteen questions the Woo specs could not settle on their own, recorded 2026-10-05 with a second D10 answer on 2026-10-06. The specs in `openspec/changes/` cite them as D1 to D13. Each section keeps the question and its options as they were put, then the answer.

| decision | answer | differs from recommendation |
|---|---|---|
| D1 | dossiq owns the Woo request, its intake and its term | **yes** (recommended opencatalogi) |
| D2 | move filinq's guarantees into OpenRegister's engine | no |
| D3 | refusal grounds: one list in dossiq; TOOI lists: one copy in OpenRegister's concept register | **yes for the grounds** (recommended OpenRegister), no for TOOI |
| D4 | integriq owns the folder watcher | no |
| D5 | every row-wins or spec-wins as recommended | no |
| D6 | as recommended; 9.22 waits on a commissioning decision | no |
| D7 | 17.12 re-rated, not built | no |
| D8 | paired filinq PR and migration | no |
| D9 | opt-in, off by default | no |
| D10 | 9.10 struck, 14.13 to 14.19 unflagged; other flagged rows still await strike or keep | no |
| D11 | a content hit resolves to the document's own public page | no |
| D12 | Woo requests require dossiq; no fallback. The refusal grounds keep a read-only fallback for redaction only | **yes** (recommended a frozen fallback) |
| D13 | dossiq joins the measured stack | new question, not put as a decision before |

## The questions as put, with the answers

Each was a conflict a cloud agent must not resolve alone, so each held its changes until answered. The 'held' line lists what waited on it.

## D1. Who owns the Woo request

_Before the answer it held 8 planned changes closing 22 rows: 7.7, 7.8, 7.9, 7.11, 7.13, 7.14, 7.15, 19.1, 19.2, 19.3, 19.4, 19.7, 19.8, 19.9, 19.10, 19.11, 19.12, 19.13, 19.15, 19.16, 19.17, 19.18. Changes: `dossiq/woo-objection-handling`, `filinq/woo-request-workflow`, `opencatalogi/woo-delivered-set-is-a-record`, `opencatalogi/woo-request-corpus-collection`, `opencatalogi/woo-request-intake-channels`, `opencatalogi/woo-request-routing-and-notices`, `opencatalogi/woo-review-recall-and-stopping`, `opencatalogi/woo-review-triage`._

**Answer (Ruben, 2026-10-05): option 3, dossiq owns the Woo request, its intake and its statutory term. Differs from my recommendation.**

What dossiq's own openspec turned out to hold, read on development at 2c99ae9: a built Woo stack. `specs/woo-case-type` (done) seeds a Woo case type with P28D plus one P14D extension, eight stages, per-stage notices to the requester, per-document assessment with grounds, a generated beschikking and publication through opencatalogi; `lib/Woo/WooRequestIntake.php` is a live second intake that portaliq's dossier flow already uses (`woo-request-from-a-portal-dossier` 4/5, `site-woo-request-in-steps` 7/8); terms run on OpenRegister's engine calendar (`every-term-on-the-engine-calendar` 8/8, `termijnbewaking-op-engine-timers` 15/26, phase 1 built); objections are `specs/bezwaar-beroep-workflow` (done); gathering documents is `woo-requests-gather-documents-from-sources` (0/6). So the answer consolidates two live intakes into the one that already has the case machinery.

How the plan carries it out, in this order, so a citizen's request is never without an armed term:

1. `dossiq/woo-request-takes-over-from-opencatalogi` (wave 2, after dossiq's `intake-says-when-the-term-starts` 5/6 and `woo-request-from-a-portal-dossier` 4/5 finish, and after the dossiq defect changes `woo-requester-notices-really-go-out` and `woo-term-is-computed-and-reported-right` from the final pass): dossiq accepts opencatalogi's request shape, proves term parity against opencatalogi's fixtures, and ships an idempotent import of stored `wooRequest` objects.
2. `opencatalogi/woo-request-intake-hands-over-to-dossiq` (wave 3): `receiveWooRequest()` forwards to dossiq, and a failed forward is an error to retry, never a local mint (D12); a repair step imports every stored `wooRequest` (status mapped, start date, suspensions, extension and deadline carried, the term re-armed on an OpenRegister FlowTimer with its remaining time exactly once, the old reference kept as a searchable alias, the source stamped `migratedTo`).
3. `portaliq/woo-intake-delivers-to-dossiq` (wave 3, beside step 2): the portal uses dossiq's declared Woo contributions and retires `PortalWooRequestDelivery`. While step 2's forward is live, a portal request that still reaches opencatalogi is armed either way.
4. Inside step 2, once the unmigrated count is zero: the `wooRequest` schema goes read-only, and the release after removes opencatalogi's intake and term arming (D12: no fallback for installations without dossiq). opencatalogi keeps publishing the decision and its documents.

filinq `woo-request-workflow` is re-scoped to the one service dossiq does not have: drafting the decision and inventory from an organisation-edited template (7.9). Gathering (7.8) is dossiq's own change. Note `dossiq-decisions-to-decidiq` may move the besluit raise to decidiq, which would then be the template service's caller.

Three specs claim the same record. **opencatalogi** ships it: `specs/woo-request-intake` is built, the request arms a term on OpenRegister's term engine (Easter, non-working days, recompute), and that is what turned 7.3 to 7.6 and 10.8 statutory yes. **filinq** `woo-request-workflow` (0/16, last touched 2026-09-29) specifies its own wooRequest in its dossier register with an app-owned deadline and explicitly refuses the OpenRegister helper. **hydra** `woo-citizen-journey` (0/12) says dossiq creates Woo requests.

1. **Recommended: opencatalogi owns the request and its term.** It is built and statutory-green. filinq stays the document engine the request calls: gathering documents (7.8) and generating the besluit and inventory from templates (7.9) become services opencatalogi invokes, so `woo-request-workflow` is re-scoped to those two and drops its own record and deadline. dossiq handles the objection (7.13) as a case only if Ruben wants case semantics there; `woo-citizen-journey` is amended to say so.
2. filinq owns it. Rebuilds the term logic a second time outside the engine, and the statutory rows would have to be re-proven.
3. dossiq owns it. Consistent with the hydra contract, but dossiq was not read in this round and the 19.x corpus and review rows would move with it.

## D2. Which redaction path the Woo flow uses

_Before the answer it held 6 planned changes closing 20 rows: 4.2, 4.5, 4.7, 4.12, 4.14, 4.15, 4.21, 4.22, 4.24, 4.25, 4.26, 4.27, 14.15, 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7. Changes: `filinq/anonymization-review-workbench`, `filinq/image-redaction`, `opencatalogi/woo-redaction-scans-and-text-layer`, `opencatalogi/woo-review-surface`, `openregister/redaction-policy-as-data`, `openregister/redaction-release-safeguards`._

**Answer (Ruben, 2026-10-05): option 1, as recommended.** Adds `filinq/redaction-guarantees-from-the-engine` (filinq retires its copies) and makes `opencatalogi/woo-redaction-scans-and-text-layer` gate the merged pipeline on the engine's verdict.

opencatalogi `woo-redaction-pipeline` (4/4, merged 2026-10-05) calls OpenRegister's anonymise endpoint directly, counts a redaction as verified when the bytes differ and no residual is reported, and has no person-must-check gate. filinq's pipeline has what the rows ask for: REQ-RWB-01 (the written copy cannot be read back), REQ-RWB-02 (a person decides), always and never-redact term lists, a Woo profile and OCR. Today filinq's guarantees cover only files filinq writes.

1. **Recommended: move the guarantees into OpenRegister's engine** so every path has them: the verifier, the human gate, term lists and policy profiles (`openregister/redaction-release-safeguards`, `redaction-policy-as-data`). filinq then consumes the engine and retires its own copies in a follow-up. This is what the plan assumes.
2. Route the Woo path through filinq. Fewer new specs (18.1 to 18.7 become largely covered), but opencatalogi then depends on filinq being installed, and the merged pipeline is rewritten.
3. Keep both paths as they are. Not recommended: the Woo path stays without an irreversibility proof and without a human gate, which is what 4.5 and 4.27 measure.

## D3. Which copy of a value list wins

_Before the answer it held 3 planned changes closing 13 rows: 4.18, 4.28, 12.29, 13.16, 13.17, 13.28, 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7. Changes: `opencatalogi/woo-value-lists-on-the-concept-register`, `openregister/anonymisation-placeholder-id-scope`, `openregister/redaction-policy-as-data`._

**Answer (Ruben, 2026-10-05): the refusal grounds live in dossiq, one list, seeded from filinq's 19 after someone settles 15 versus 19 against the law; opencatalogi's constant and filinq's copy are retired and both read dossiq's. Differs from my recommendation for the grounds. The TOOI lists follow the recommendation: one copy in OpenRegister's concept register.**

dossiq's openspec adds a third copy: `woo-case-type` enumerates 12 grounds. `dossiq/woo-refusal-grounds-list` retires it too. Reading the grounds makes opencatalogi and filinq depend on dossiq. When dossiq is absent, each uses a read-only statutory snapshot that dossiq's seed produces at release time, for citing a ground on a redacted passage only: grounds can be picked and attached, not edited, and the admin says where they would come from. Nothing blocks a publication or a redaction for want of dossiq. D12 confirmed this fallback for redaction only.

**Exception grounds:** opencatalogi holds 15 Woo art. 5 grounds as a PHP constant (`WooService::WEIGERINGSGRONDEN`), flat and unaudited. filinq `grondslagen-woo-art5` (7/7, built) ships 19, editable and audited, and filinq's redaction bases reference that list. **TOOI lists:** bundled twice, in OpenRegister (SKOS-003) and in opencatalogi (WOO-TOOI-004).

1. **Recommended: one scheme on OpenRegister's concept register**, seeded from filinq's list, read by both apps; opencatalogi's constant and its TOOI copy are retired. Someone with the law in hand settles 15 versus 19 before seeding.
2. filinq's register is the source and opencatalogi reads it across apps. Makes opencatalogi depend on filinq.
3. Keep both. Not recommended: a redaction ground written by filinq may not exist in opencatalogi's list.

## D4. Who watches a folder for intake

_Before the answer it held 1 planned changes closing 1 rows: 1.7. Changes: `integriq/sources-sftp-adapter`._

**Answer (Ruben, 2026-10-05): the recommendation, as written.**

filinq `scan-intake-with-separator-sheets` task 3.2 (open; its own status note says no watched-folder job exists) and integriq `sources-sftp-adapter` (0/11) both lead to a folder watcher. Nothing in the fleet watches a folder today.

1. **Recommended: integriq owns the watcher** (a folder is a source); filinq's scan intake consumes it for separator-sheet splitting.
2. filinq owns it, and integriq's SFTP adapter stays a pull of remote files only.

## D5. Rows that contradict an existing spec

_Before the answer it held 11 planned changes closing 23 rows: 1.18, 4.13, 4.14, 4.15, 4.20, 5.11, 5.13, 5.21, 6.32, 6.33, 11.14, 12.22, 13.12, 13.13, 13.14, 13.15, 13.19, 13.23, 13.32, 14.1, 14.2, 15.3, 15.7. Changes: `filinq/pdfua-verapdf-matterhorn`, `integriq/outbound-call-log-investigation-window`, `opencatalogi/publication-schedule-guards`, `opencatalogi/theme-archive-hotspot`, `opencatalogi/woo-metadata-suggestions`, `opencatalogi/woo-redaction-scans-and-text-layer`, `opencatalogi/woo-value-list-curation`, `openregister/administrative-change-log`, `openregister/anonymisation-image-seam`, `openregister/files-create-from-url`, `openregister/search-dutch-language-quality`._

**Answer (Ruben, 2026-10-05): the recommendation, as written.**

For each: row wins (the spec is amended) or spec wins (the row is re-rated or struck).

| row | contradicts | recommendation |
|---|---|---|
| 1.18 | opencatalogi `integration-publish-by-reference` REQ-PBR-001: a URL is stored as a reference, never fetched | Both: the caller chooses link or copy explicitly. Copy fetches behind `assertSafeFetchUrl` in OpenRegister; link stays REQ-PBR-001. |
| 6.33 | OpenRegister and opencatalogi specs forbid chunk or file text in results, because a snippet from the original leaks redacted values | Row wins only with the passage taken from the redacted copy, and only for public documents. Otherwise spec wins. |
| 11.14 | opencatalogi RET-004: a changed retention default must not retroactively alter existing publications | Row wins outside the defaults mechanism: a hotspot is its own rule reaching publications already filed. RET-004 keeps governing defaults. |
| 13.14 | OpenRegister SKOS-002: a refresh overwrites labels | Row wins: split normative fields (refreshed) from the organisation's own text (kept). |
| 13.19 | opencatalogi REQ-WMS-003: nothing is written without the officer accepting | Row wins narrowly: when exactly one lawful value exists it fills itself, labelled as such. AI suggestions stay accept-only. |
| 13.23 | integriq REQ-OCD-001: every outbound call is recorded with its body, permanently | Row wins: body capture becomes opt-in and time-boxed; status, timing and headers stay permanent. |
| 12.22 | OpenRegister `history-schema-and-settings-edits-audited` and `settings-change-audit` deliberately merge administrative history into the one domain trail | Spec wins: one trail with an administrative category and a filtered view. Re-rate 12.22 against that, unless Ruben wants two stores. |
| 4.15 | filinq `image-redaction` non-goal: no searchable PDF after a burn | Row wins: an OCR text layer over the burned raster, taken after the burn so it cannot carry removed text. |
| 4.20 | filinq `image-redaction` excludes faces; plates are matched as text only | Spec wins for now (signatures in, faces and plates out) until D6 gives the object detector an owner. |
| 5.11 | opencatalogi REQ-PPW-002 specifies 'publish now' on a scheduled publication, and RET-001 forbids a second embargo mechanism | Row wins as a refusal on the publish action for an embargoed record, overridable only by a named right and logged. No second visibility check. |
| 5.5 | opencatalogi RET-001 and RET-006: a withdrawn record is a 404 and invisible everywhere | Row wins with the tombstone scoped as a page about the withdrawal, not the record. |
| 15.7 | filinq `pdfua-verapdf-matterhorn` refuses auto remediation of imported PDFs | Spec wins for imported files; generated documents get PDF/UA. 15.7 then tops out at partial, and the row says so. |
| 13.32 | opencatalogi REQ-WIC-001 builds the harvester surface from the full category set | Row wins: a hidden category serves an empty, valid sitemap index rather than none, so the national harvester sees a stable set. |

## D6. Gaps no app can own yet

_Before the answer it held 5 planned changes closing 7 rows: 1.19, 4.13, 4.20, 6.32, 6.33, 9.22, 9.23. Changes: `integriq/cvdr-regulation-delivery`, `integriq/statutory-gateways-and-frameworks`, `openregister/anonymisation-image-seam`, `openregister/search-dutch-language-quality`, `openregister/upload-malware-scan`._

**Answer (Ruben, 2026-10-05): the recommendations, as written.** 9.23 and 12.25 are struck; 4.20 moves to a new anonymiq change; 9.22 still waits on the commissioning decision, so it is the one change still gated here.

| row | why no owner | recommendation |
|---|---|---|
| 4.13, 4.20 | Detecting and burning an object (face, plate, signature) in a page image needs a vision model. OpenRegister has a text pipeline; anonymiq, the natural home for models, has no openspec at all and has not moved since 2026-08-23 | Give anonymiq an `openspec/` and make it own the detector; OpenRegister owns the seam (`anonymisation-image-seam`). |
| 1.19 | Virus scanning: filinq's checks assume OpenRegister scans, OpenRegister does not, portaliq hands it to Nextcloud server | Require Nextcloud's files_antivirus; OpenRegister checks it is present, refuses uploads when it is not, and feeds it archive members. |
| 6.32, 6.33 | Dutch stemming and highlighting were specified for Solr, which ADR-007 removed | PostgreSQL `dutch` text search configuration, PostgreSQL only, stated. MySQL keeps substring matching. |
| 9.22 | KOOP LVBB and STOP delivery is named in no spec; real conformance needs a KOOP connection (commissioning), which is outside code | Ruben decides whether to commission it. If yes, integriq builds the profile in `statutory-gateways-and-frameworks`. |
| 9.23 | CVDR needs a consolidated regulation text and no app in the fleet produces one | Strike unless decidiq takes regulation consolidation; the transport alone does not close the row. |
| 12.25 | Impersonation exists nowhere and Nextcloud offers none; the triage calls it a liability | Strike, or build a read-only 'see rights as' preview on permission provenance instead of real impersonation. |

## D7. Rows that are not build items

_Held no planned change._

**Answer (Ruben, 2026-10-05): the recommendation, as written.**

**17.12**, a named public organisation runs it in production. The evidence quotes 12 (conduction.nl), 14 and more than 40 (openwebconcept.nl), which count different things. **Recommended:** settle one sourced number in marketing and re-rate; no spec.

## D8. filinq's documentsoort workaround

_Before the answer it held 1 planned changes closing 4 rows: 2.3, 2.13, 2.23, 2.25. Changes: `opencatalogi/diwoo-metadata-on-the-publication`._

**Answer (Ruben, 2026-10-05): the recommendation, as written.**

filinq maps the DiWoo documentsoort onto opencatalogi's `summary` because the publication has no field for it (REQ-DDWPP-005), so the type is lost and summaries are polluted. **Recommended:** `diwoo-metadata-on-the-publication` ships with a paired filinq PR in the same wave that writes the new field, plus a migration that moves documentsoort out of existing summaries. The alternative, leaving filinq for later, keeps writing wrong summaries until it lands.

## D9. Policy choices an organisation makes

_Before the answer it held 2 planned changes closing 4 rows: 6.15, 6.16, 16.10, 16.11. Changes: `opencatalogi/woo-review-reports`, `portaliq/publication-error-reports-and-withheld-notices`._

**Answer (Ruben, 2026-10-05): the recommendation, as written.**

- 6.15: an anonymous channel for reporting an error needs throttling and a moderation owner.
- 6.16: publishing that a withheld record exists is itself a disclosure choice.
- 16.10: a per-reviewer, per-day throughput report is a per-person productivity figure and may need works-council consent.
**Recommended:** build all three as opt-in per organisation, off by default, and name who may read 16.10.

## D10. The flagged deliberately-not-building rows

_Before the answer it held 7 planned changes closing 8 rows: 6.6, 8.12, 9.10, 11.11, 12.25, 14.4, 14.6, 14.20. Changes: `filinq/review-order-learns-from-decisions`, `opencatalogi/oai-pmh-endpoint`, `opencatalogi/semantic-publication-search`, `openregister/check-rights-as-another-user`, `openregister/register-document-store`, `portaliq/search-assistant-from-public-content`, `portaliq/site-honest-without-javascript`._

**Answer (Ruben, 2026-10-05): the recommendation, as written.** 9.10 is struck and `oai-pmh-endpoint` keeps only 8.12; 14.13 to 14.19 are unflagged. The other flagged rows still await strike or keep, so changes made only of them stay gated on D10.

Ruben strikes or keeps each flagged row. Two things changed since the triage was written:

- **9.10 has an open change.** `opencatalogi/oai-pmh-endpoint` (0/20) specifies OAI-PMH, against the triage's reason, and it also carries 8.12 (a changed-since read for re-users). **Recommended:** strike 9.10 and re-scope the change to its DCAT and changed-since half.
- **The area 14 reason no longer fits most of area 14.** 'The AI app is outside the bundle' fits 14.1 to 14.7 and 14.20. 14.13 to 14.19 are about OpenRegister's own anonymisation detector (model versions, accuracy, training exclusion, retention of external services, threshold): compliance disclosures for a detector the stack already runs. **Recommended:** unflag 14.13 to 14.19.

**Second answer (Ruben, 2026-10-06) on the five rows still flagged.** Kept and specified: 6.6 (portal without JavaScript, `portaliq/site-honest-without-javascript`) and 11.11 (replaceable document store, `openregister/register-document-store`). Struck, status `denied`: 14.4 (chat assistant), 14.20 (review order learned from decisions), and 14.6's missing half (search without the typed words; the row stays `partial`). `opencatalogi/semantic-publication-search`, `portaliq/search-assistant-from-public-content` and `filinq/review-order-learns-from-decisions` are therefore not built. D6 row 9.22 (KOOP) stays gated: not commissioned now.

## D11. Are documents search hits of their own

_Before the answer it held 2 planned changes closing 2 rows: 6.2, 6.30. Changes: `portaliq/portal-federated-search`, `portaliq/search-filter-by-kind`._

**Answer (Ruben, 2026-10-05): the recommendation, as written.**

`opencatalogi/add-document-content-search` (6/8) returns content hits as rows of the `document` schema, which `attachments-are-files` retired. 6.2 (search reaches inside documents) and 6.30 (filter by kind: publication, document, subject) both need an answer.

1. **Recommended:** a content hit resolves to the document's own public page, which `publication-detail-page-complete` builds (6.19); the kind filter then has three real kinds. `add-document-content-search` is amended before portaliq relies on it.
2. A content hit resolves to its publication with a 'matched in a document' marker; the 'document' kind is dropped from 6.30.

## D12. What an installation without dossiq does

Raised by the D1 answer: opencatalogi-only installations take Woo requests today.

1. Recommended then: opencatalogi keeps its intake as a frozen fallback while dossiq is absent.
2. Woo requests require dossiq; opencatalogi-only installations lose intake when the removal ships.
3. For the refusal grounds: a read-only snapshot, or refusing to attach a ground without dossiq.

**Answer (Ruben, 2026-10-05): option 2, Woo requests require dossiq, with no fallback. Differs from my recommendation.** Without dossiq there is no Woo request intake and opencatalogi only publishes. `opencatalogi/woo-request-intake-hands-over-to-dossiq` drops both fallbacks: a failed forward is an error to retry, not a local mint, and without dossiq the request routes answer 404, the navigation shows no Woo requests entry, and the Woo settings section tells the administrator that dossiq handles Woo requests and is not installed. The removal no longer waits: once the import reports zero unmigrated, the schema goes read-only and the next release removes the intake.

**The refusal grounds keep their read-only fallback.** D12 covers requests. The grounds are also cited per withheld passage when opencatalogi redacts for publication (row 4.8) and when filinq redacts, with no request involved, so removing the fallback would break redaction without dossiq. The read-only statutory list stays for that use only.

**Consequence Ruben chose, for the release notes:** an installation that runs opencatalogi without dossiq and takes Woo requests today loses Woo request intake when the removal release ships. Its stored requests are migrated only if dossiq is installed before then.

## D13. dossiq joins the measured stack

**Answer (Ruben, 2026-10-05): dossiq is added to our measured column.** The dossiq lane's patch is merged (e58518a): 7.7, 7.14, 7.15 and 10.7 to yes; 7.11, 7.13 and 16.2 to partial. The plan is rebuilt on it: two changes dropped (7.14 and 7.15 intake channels in dossiq, the 10.7 amendment in opencatalogi), the takeover shrank to a supporting change, and dossiq's own defects (notices that are never sent, an unrolled deadline, a report that files every term under unknown, an extension that bypasses the cap, no screens between intake and publication) became dossiq changes in wave 1 and 2, ahead of the migration.
