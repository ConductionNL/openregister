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

namespace OCA\OpenRegister\Service\Timeline;

use OCA\OpenRegister\Db\TimelineKind;
use OCA\OpenRegister\Db\TimelineKindMapper;
use Symfony\Component\Uid\Uuid;

/**
 * Entry kinds and their field validation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
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
		$slug = '';
		if (isset($data['slug']) === true && is_string($data['slug']) === true) {
			$slug = strtolower(trim($data['slug']));
		}

		if ($slug === '') {
			throw new TimelineValidationException(['slug' => 'A kind needs a slug']);
		}

		$properties = [];
		if (isset($data['properties']) === true) {
			if (is_array($data['properties']) === false) {
				throw new TimelineValidationException(['properties' => 'Properties must be a map of property names to declarations']);
			}

			$properties = $data['properties'];
		}

		$required = [];
		if (isset($data['required']) === true && is_array($data['required']) === true) {
			$required = array_values(array_filter($data['required'], 'is_string'));
		}

		$kind = $this->kindMapper->findBySlug(slug: $slug);
		$isNew = ($kind === null);
		if ($isNew === true) {
			$kind = new TimelineKind();
			$kind->setUuid((string)Uuid::v4());
			$kind->setSlug($slug);
			$kind->setCreated(new \DateTime());
		}

		$kind->setTitle($this->stringOrNull($data, 'title'));
		$kind->setDescription($this->stringOrNull($data, 'description'));
		$kind->setProperties($properties);
		$kind->setRequired($required);
		$kind->setFollowUp((bool)($data['followUp'] ?? false));
		$kind->setRegister($this->stringOrNull($data, 'register'));
		$kind->setSchema($this->stringOrNull($data, 'schema'));
		$kind->setUpdated(new \DateTime());

		if ($isNew === true) {
			return $this->kindMapper->insert($kind);
		}

		return $this->kindMapper->update($kind);
	}//end declareKind()

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
		if ($kindSlug === null || trim($kindSlug) === '') {
			return [];
		}

		$kind = $this->kindMapper->findBySlug(slug: strtolower(trim($kindSlug)));
		if ($kind === null) {
			throw new TimelineValidationException(['kind' => 'No entry kind named '.$kindSlug.' is declared']);
		}

		$declared = ($kind->getProperties() ?? []);
		$required = ($kind->getRequired() ?? []);
		$accepted = [];
		$errors = [];

		foreach ($declared as $name => $declaration) {
			if (is_string($name) === false) {
				continue;
			}

			if (array_key_exists($name, $fields) === false) {
				if (in_array($name, $required, true) === true) {
					$errors[$name] = 'This field is required by kind '.$kind->getSlug();
				}

				continue;
			}

			$problem = $this->checkValue(
				value: $fields[$name],
				declaration: (is_array($declaration) === true) ? $declaration : []
			);
			if ($problem !== null) {
				$errors[$name] = $problem;
				continue;
			}

			$accepted[$name] = $fields[$name];
		}//end foreach

		if ($errors !== []) {
			throw new TimelineValidationException($errors);
		}

		return $accepted;
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
		if ($kindSlug === null || trim($kindSlug) === '') {
			return false;
		}

		$kind = $this->kindMapper->findBySlug(slug: strtolower(trim($kindSlug)));

		return ($kind !== null && $kind->getFollowUp() === true);
	}//end carriesFollowUp()

	/**
	 * Check one value against one property declaration.
	 *
	 * @param mixed                $value       The value the entry carries.
	 * @param array<string,mixed>  $declaration The property declaration.
	 *
	 * @return string|null The reason it does not fit, or null when it does.
	 */
	private function checkValue(mixed $value, array $declaration): ?string {
		$type = 'string';
		if (isset($declaration['type']) === true && is_string($declaration['type']) === true) {
			$type = $declaration['type'];
		}

		$fits = match ($type) {
			'string' => is_string($value),
			'integer' => is_int($value),
			'number' => (is_int($value) === true || is_float($value) === true),
			'boolean' => is_bool($value),
			'array' => is_array($value),
			'object' => is_array($value),
			default => true,
		};

		if ($fits === false) {
			return 'Expected a value of type '.$type;
		}

		if (isset($declaration['enum']) === true && is_array($declaration['enum']) === true
			&& in_array($value, $declaration['enum'], true) === false
		) {
			return 'Expected one of: '.implode(', ', array_map('strval', $declaration['enum']));
		}

		return null;
	}//end checkValue()

	/**
	 * Read one optional string off a payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param string              $key  The key to read.
	 *
	 * @return string|null The value, or null when it is absent or not a string.
	 */
	private function stringOrNull(array $data, string $key): ?string {
		if (isset($data[$key]) === false || is_string($data[$key]) === false) {
			return null;
		}

		$value = trim($data[$key]);

		return ($value === '') ? null : $value;
	}//end stringOrNull()
}//end class
