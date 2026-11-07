<?php

/**
 * AnnotationVocabulary: the declared x-openregister-* annotation keys.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;

/**
 * The `x-openregister-*` keys a schema configuration keeps.
 *
 * Schema::ANNOTATION_VOCABULARY is this list.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class AnnotationVocabulary {
	/**
	 * Declared `x-openregister-*` annotation keys.
	 *
	 * Keys outside this set are dropped at save time so a typo
	 * (e.g. `x-openregister-lifecycl` instead of `…-lifecycle`) is
	 * caught early instead of silently round-tripping through the
	 * configuration column and having the corresponding listener
	 * never fire.
	 *
	 * PUBLIC because the import path needs it too: ImportHandler compares an
	 * incoming schema's annotations against the stored ones to decide whether
	 * a version-skipped import is really a no-op, and it must compare ONLY the
	 * keys that can actually persist. Comparing a key outside this vocabulary
	 * would make the comparison differ forever — the key is dropped on every
	 * save — and re-import the schema on every single settings load.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [
		'x-openregister-lifecycle',
		'x-openregister-aggregations',
		'x-openregister-calculations',
		'x-openregister-references',
		'x-openregister-aggregate-refs',
		'x-openregister-notifications',
		'x-openregister-widgets',
		'x-openregister-relations',
		// The named relation vocabulary: a list of
		// {key, label, inverseLabel, symmetric, inherits} that several `$ref`
		// properties can point at by key, so an administrator edits "blocked
		// by" in one place instead of on every property that means it. Read by
		// RelationTypeResolver and refused by RelationAnnotationValidator.
		//
		// ⚠️ Absent from this list it is dropped by setConfiguration(), and the
		// failure is the quiet one this list exists to prevent: every property
		// naming a key would resolve to nothing, so a typed relation would
		// render as the generic "referenced by" fallback forever while its
		// author reads a 200 and believes it saved. The comments below record
		// the same bug five times over.
		'x-openregister-relation-types',
		'x-openregister-processing-activity',
		// Doelbinding: whether a read of this schema has to name an
		// administered purpose. Absent from this list setConfiguration() would
		// silently DROP it, and a schema whose author had just turned
		// doelbinding on would keep answering every unbound read with a 200 —
		// which is precisely the lawful-basis gap the annotation exists to
		// close, wearing the appearance of a saved setting. The comments around
		// this list record that same loss six times over.
		'x-openregister-purpose-required',
		// Read by ProcessingLogService::ANNOTATION_KEY (the AVG `logReads`
		// dialect). Was absent from this list, so setConfiguration() silently
		// DROPPED it and per-schema read-logging could never be enabled —
		// register-level worked, so the capability looked healthy.
		'x-openregister-processing',
		'x-openregister-archival',
		// The links out of a record, each a title plus a URL template whose
		// placeholders fill from the object's own values
		// (api-as-a-versioned-surface, ADR-031). Read by
		// ExternalLinkResolver and refused at save by
		// ExternalLinkAnnotationValidator.
		//
		// ⚠️ Absent from this list setConfiguration() drops it, and the drop is
		// invisible in the worst way this feature has: a declaration that is
		// gone and a declaration whose placeholder cannot be filled both render
		// as no link at all. An author would read a 200, see nothing on the
		// object, and conclude their template was wrong.
		'x-openregister-external-links',
		// Whether this schema's objects can be archived by hand:
		// `{"enabled": true}`. Distinct from `x-openregister-archival` above,
		// which is about legal retention. Absent from this list
		// setConfiguration() would silently DROP it, a schema editor would
		// report a saved opt-in, and the archive endpoint would answer 422 on
		// a schema whose author had just enabled it — the same or#460/#462-class
		// loss the comments around this list record five times over.
		Schema::ARCHIVE_ANNOTATION,
		// Which object property fills which MDTO element. Absent from this list
		// setConfiguration() would DROP it, so a schema editor would report a
		// saved mapping, the annotation would not be there, and the transfer
		// refusal it exists to drive would never fire. The three comments above
		// record that same loss three times.
		'x-openregister-mdto-mapping',
		'x-openregister-object-source',
		// A type whose objects are the rows of a saved view: `{"view": "<id or
		// uuid>"}` (modelling-query-backed-type). Read by getObjectSource().
		// Absent from this list it would be dropped and the type would read as
		// an ordinary, empty, writable schema.
		'x-openregister-view',
		'x-openregister-quality',
		'x-openregister-dedup',
		// Which properties a schema nominates as effectively unique, so a save
		// whose value already exists elsewhere warns. Read by
		// UniqueHintChecker. Absent from this list it would be dropped in
		// silence and the alert would simply never fire — the same trap the
		// comments above this list record three separate times.
		'x-openregister-unique-hint',
		'x-openregister-flows',
		'x-openregister-survivorship',
		'x-openregister-merge',
		'x-openregister-handoff',
		'x-openregister-mcp',
		// The extending-form declaration: which property-vocabulary keys an
		// app's own property form forwards. Absent from this list it would be
		// silently DROPPED by setConfiguration(), and the narrowing the
		// annotation exists to make visible would be invisible again, which is
		// the exact failure the three comments below record.
		'x-openregister-extends-form',
		// The extensible action vocabulary: a map of action key →
		// {name, description} that `validateAuthorizationRules()` reads to
		// decide which non-CRUD actions an authorization block may name.
		//
		// ⚠️ Absent from this list it would be DROPPED by setConfiguration(),
		// and the failure would be the worst shape available: every declared
		// action would vanish, so every authorization naming one would fail the
		// import with "Invalid authorization action" — pointing the author at
		// their authorization block when the real loss happened silently one
		// key earlier. The two comments above record the same bug twice.
		'x-openregister-action',
		'x-openregister-approval-chains',
		// What makes an object unread again, and which sub-resources badge a
		// tab (`object-read-state`). Read by SubstantiveChangeEvaluator. Absent
		// from this list the key would be silently dropped, every schema would
		// fall back to "any non-computed property is news", and the annotation
		// that exists to stop a nightly recalculation marking four hundred
		// cases unread would never fire. The same or#460/#462-class trap the
		// four comments above record.
		'x-openregister-read-state',
		// Per-schema opt-in for OCP\ContextChat content submission (default
		// OFF — see ContentProvider / ContextChatSubmissionListener). Absent
		// from the vocabulary means the key round-trips through
		// setConfiguration() and gets silently dropped, so the opt-in could
		// never take effect (same or#460/#462-class bug the vocabulary
		// exists to prevent).
		'x-openregister-contextchat',
		// How many of this schema's objects one organisation may hold
		// (`{"perOrganisation": N}`), enforced on create by ObjectQuotaListener.
		// Absent from this list the key is dropped on save and the quota never
		// fires, the same or#460/#462-class trap as the entries above.
		'x-openregister-quota',
		// Nested write-only dot-paths. Unlike every other entry in this list
		// this key is NOT a plain pass-through: validateConfigurationEntry has
		// a dedicated branch above that validates its shape, and
		// validateConfigurationArray re-throws instead of dropping it.
		Schema::WRITEONLY_PATHS_ANNOTATION,
		// Federated configuration sharing (ADR pending): a schema opts its
		// objects into the shared GitHub sharing engine by carrying either
		// `true` or an `{id, topic, name}` refinement here. Without this entry
		// setConfiguration() would silently drop the marker and the schema
		// would never surface as a shareable type — the same or#460/#462-class
		// trap the vocabulary exists to catch. Read by
		// SchemaShareableConfigScanner.
		'x-openregister-shareable',
		// Declarative bound on what object data may reach an LLM: the
		// per-schema allowlist Hermiq's AgentContextBuilder (and its JS twin
		// src/utils/agentContext.js) reads to build an agent's context.
		// Absent from this list, setConfiguration() silently DROPPED it, so
		// every agent leaf on every schema fleet-wide resolved an EMPTY
		// context — fail-closed, so never a leak, but the capability was
		// wholly inert while schemas saved with HTTP 200 and no error. Same
		// or#460/#462-class trap as `x-openregister-processing` and
		// `x-openregister-contextchat` above. See or#2164.
		'x-openregister-agent-context',
		// Registry-subscriptions: names the external registry (brp/kvk/...)
		// that owns a subset of this schema's properties, the identity
		// property, and the owned property list. Absent from this list,
		// setConfiguration() would silently DROP it and a schema author
		// could never opt an object into a subscription — same
		// or#460/#462-class trap as every entry above.
		'x-openregister-registry',
		// Who owns a decision on this schema's records (open, or the decider
		// plus supervisors and administrators) and whether one reviewer's
		// decisions are hidden from another (reviewer-owns-their-decisions).
		// Read by ReviewPolicyResolver, refused at save by
		// ReviewAnnotationValidator. Absent from this list it would be dropped,
		// and a four-eyes review its author had just switched on would stay open.
		'x-openregister-review',
		// A property that shows a referenced record's own field, live. Read by
		// LensResolver at render time; the value is never stored. Absent from
		// this list, setConfiguration() would drop the block and every lens
		// would render empty, which reads exactly like "there is no besluit".
		Schema::LENS_ANNOTATION,
		// The columns a list surface shows and the fields it searches on, so an
		// object type is as usable as a case list with no page of its own.
		Schema::LIST_ANNOTATION,
		// The reference properties a record collects map features from, each
		// feature naming the relation it arrived through.
		Schema::GEO_INHERITANCE_ANNOTATION,
		// Declares that objects of this schema ARE parties: which kind of
		// party, and which of its properties carry the name, the addresses,
		// the indicators and the parent. Absent from this list,
		// setConfiguration() would DROP it and the party model would report
		// "this schema is not a party schema" for a schema that says it is —
		// the same silent no-op class as every entry above.
		'x-openregister-party',
		// The reasons a value may be recorded as not supplied, keyed by code.
		// Absent from this list, setConfiguration() would DROP it, every
		// not-supplied write would be refused as "no reasons administered",
		// and the schema author would be reading a 200 on the list they had
		// just saved. Same silent no-op class as every entry above.
		Schema::NOT_SUPPLIED_REASONS_ANNOTATION,
		// The library of named conditions a rule, guard or field rule may
		// reference by name (row 11.40). Absent from this list,
		// setConfiguration() would DROP it, and every rule referencing a name
		// would then REFUSE — fail-closed, so not a silent no-op this time,
		// but a schema whose author had just saved the library reading a 200
		// and watching every one of their rules stop working. Same class of
		// trap as every entry above, arriving from the other side.
		'x-openregister-conditions',
		// The checks an administrator adds, with the sentence each one says
		// when it refuses (row 11.53). Absent from this list,
		// setConfiguration() DROPS it and the schema's author reads a 200 on
		// the save while every violating object keeps saving happily — a
		// missing CONTROL rather than a missing feature, which is the worst
		// member of the silent no-op class this list exists to prevent.
		'x-openregister-validations',
		// The edge a GRANT travels down: which property points at the parent,
		// and which actions descend. Read by HierarchyGrantExpander and
		// refused at save by SchemaMapper::validateHierarchyAnnotation().
		//
		// ⚠️ It was absent from this list, and that is the seventh time the
		// trap the comments above describe actually fired. setConfiguration()
		// DROPPED the block, so `getConfiguration()` answered null, the
		// save-time validator returned early on a key that could never be
		// there, and the expander found nothing to descend: a grant on a root
		// stopped at the root while its author read a 201. Measured on a live
		// instance 2026-09-19 — POST with the annotation returned 201 and the
		// configuration column was empty.
		HierarchyGrantExpander::ANNOTATION,
		// Whether this schema's objects may invite an external
		// (non-Nextcloud-user) participant into a linked Talk room by email,
		// via TalkLinkService::inviteExternalParticipant() — default OFF.
		// Absent from this list setConfiguration() would silently DROP it,
		// so an author enabling guardian participation would read a 200 and
		// every subsequent invite would answer 403 "does not allow", the
		// same silent no-op class every entry above records.
		'x-openregister-talk-participants',
	];


	/**
	 * Whether a key is an `x-openregister-*` annotation outside the vocabulary.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return bool True for a key that is dropped and recorded on save.
	 */
	public function isUnknownAnnotation(string $key): bool {
		return str_starts_with($key, 'x-openregister-') === true
			&& in_array($key, self::KEYS, true) === false;
	}//end isUnknownAnnotation()
}//end class
