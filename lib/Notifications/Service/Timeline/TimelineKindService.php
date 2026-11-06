<?php

/**
 * TimelineKindService: declares entry kinds and validates what they carry.
 *
 * A contactmoment needs a channel and a direction. Today dossiq models it as
 * its own object, which splits the timeline in two: some things are entries
 * and some are objects that look like entries. A kind keeps one timeline and
 * lets the entry carry the fields (D-2).
 *
 * The properties are a JSON Schema `properties` map, so a kind speaks the same
 * language a schema already speaks. Validation here is deliberately the SUBSET
 * an entry field needs — type, enum, required — and not a second full
 * validator: a kind that needs more than that is a schema, and the entry
 * should point at an object instead.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Notifications\Db\TimelineKind;
use OCA\OpenRegister\Notifications\Db\TimelineKindMapper;
use Symfony\Component\Uid\Uuid;

/**
 * Entry kinds and their field validation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineKindService {

	/**
	 * Constructor.
	 *
	 * @param TimelineKindMapper $kindMapper Reads and writes the declarations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TimelineKindMapper $kindMapper,
	) {
	}//end __construct()

	/**
	 * Every kind in scope for a register and schema.
	 *
	 * @param string|null $register The register being written on.
	 * @param string|null $schema   The schema being written on.
	 *
	 * @return array<int, TimelineKind> The declarations.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function listKinds(?string $register = null, ?string $schema = null): array {
		return $this->kindMapper->findAll(register: $register, schema: $schema);
	}//end listKinds()

	/**
	 * One kind by the name entries carry.
	 *
	 * @param string $slug The kind name.
	 *
	 * @return TimelineKind|null The declaration, or null when nobody declared it.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function get(string $slug): ?TimelineKind {
		return $this->kindMapper->findBySlug(slug: $slug);
	}//end get()

	/**
	 * Declare a kind, or rewrite the declaration that already carries the name.
	 *
	 * @param array<string,mixed> $data The declaration: slug, title, description, properties, required, followUp, register, schema.
	 *
	 * @return TimelineKind The stored declaration.
	 *
	 * @throws TimelineValidationException When the declaration names no slug or an unusable properties map.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function declareKind(array $data): TimelineKind {
		$declaration = new TimelineKindDeclaration(data: $data);

		$kind = $this->kindMapper->findBySlug(slug: $declaration->slug());
		if ($kind === null) {
			return $this->kindMapper->insert($declaration->writeOnto(kind: $this->blank(slug: $declaration->slug())));
		}

		return $this->kindMapper->update($declaration->writeOnto(kind: $kind));
	}//end declareKind()

	/**
	 * A kind nobody has declared yet, with its stable id minted.
	 *
	 * @param string $slug The kind name.
	 *
	 * @return TimelineKind The new declaration.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the standard utility pattern in this app
	 */
	private function blank(string $slug): TimelineKind {
		$kind = new TimelineKind();
		$kind->setUuid((string)Uuid::v4());
		$kind->setSlug($slug);
		$kind->setCreated(new DateTime());

		return $kind;
	}//end blank()

	/**
	 * Withdraw a declaration.
	 *
	 * Entries already written as that kind keep their kind and their fields.
	 * A record is what happened, and withdrawing the declaration does not
	 * unwrite it.
	 *
	 * @param string $slug The kind name.
	 *
	 * @return boolean True when a declaration was removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function withdraw(string $slug): bool {
		$kind = $this->kindMapper->findBySlug(slug: $slug);
		if ($kind === null) {
			return false;
		}

		$this->kindMapper->delete($kind);

		return true;
	}//end withdraw()

	/**
	 * Validate the fields an entry carries against the kind it names.
	 *
	 * An UNDECLARED kind is a refusal, not a silent plain note: a caller that
	 * writes `contactmoment` on an instance where nobody declared it has a
	 * typo or a missing configuration, and answering "fine, it is a note" hides
	 * both. Writing no kind at all is the plain note, and takes this path only
	 * to return the empty field set.
	 *
	 * @param string|null         $kindSlug The kind the entry names, or null for a plain note.
	 * @param array<string,mixed> $fields   The values the entry carries.
	 *
	 * @return array<string,mixed> The accepted values, with anything the kind does not declare dropped.
	 *
	 * @throws TimelineValidationException When the kind is undeclared or a value does not fit.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function validateFields(?string $kindSlug, array $fields): array {
		if ($this->namesAKind(kindSlug: $kindSlug) === false) {
			return [];
		}

		$kind = $this->kindMapper->findBySlug(slug: strtolower(trim($kindSlug)));
		if ($kind === null) {
			throw new TimelineValidationException(['kind' => 'No entry kind named '.$kindSlug.' is declared']);
		}

		return (new TimelineKindFields(kind: $kind, fields: $fields))->validated();
	}//end validateFields()

	/**
	 * Whether entries of a kind carry a follow-up state at all.
	 *
	 * @param string|null $kindSlug The kind the entry names.
	 *
	 * @return boolean True when the kind declares a follow-up.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function carriesFollowUp(?string $kindSlug): bool {
		if ($this->namesAKind(kindSlug: $kindSlug) === false) {
			return false;
		}

		$kind = $this->kindMapper->findBySlug(slug: strtolower(trim($kindSlug)));

		return ($kind !== null && $kind->getFollowUp() === true);
	}//end carriesFollowUp()

	/**
	 * Whether an entry names a kind at all.
	 *
	 * @param string|null $kindSlug The kind the entry names.
	 *
	 * @return boolean False for a plain note.
	 */
	private function namesAKind(?string $kindSlug): bool {
		return ($kindSlug !== null && trim($kindSlug) !== '');
	}//end namesAKind()
}//end class
