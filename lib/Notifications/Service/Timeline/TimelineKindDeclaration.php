<?php

/**
 * A kind declaration read off a payload, refused before anything is written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
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

/**
 * The parts of a declaration that have to be right before anything is written.
 *
 * Refused on construction rather than inside the write, so a payload that cannot
 * make a kind never reaches the mapper and never half-writes one.
 */
class TimelineKindDeclaration {
	use OptionalPayloadString;

	/**
	 * The kind name.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * The validated properties map.
	 *
	 * @var array<string,mixed>
	 */
	private array $properties;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data The declaration: slug, title, description, properties, required, followUp, register, schema.
	 *
	 * @throws TimelineValidationException When the slug is missing or the properties are not a map.
	 */
	public function __construct(private readonly array $data) {
		$this->slug = $this->readSlug();
		$this->properties = $this->readProperties();
	}//end __construct()

	/**
	 * The kind name.
	 *
	 * @return string The slug.
	 */
	public function slug(): string {
		return $this->slug;
	}//end slug()

	/**
	 * Write the declaration onto a kind, new or existing.
	 *
	 * @param TimelineKind $kind The kind.
	 *
	 * @return TimelineKind The filled kind, not yet stored.
	 */
	public function writeOnto(TimelineKind $kind): TimelineKind {
		$kind->setTitle($this->stringOrNull(data: $this->data, key: 'title'));
		$kind->setDescription($this->stringOrNull(data: $this->data, key: 'description'));
		$kind->setProperties($this->properties);
		$kind->setRequired($this->requiredNames());
		$kind->setFollowUp((bool)($this->data['followUp'] ?? false));
		$kind->setRegister($this->stringOrNull(data: $this->data, key: 'register'));
		$kind->setSchema($this->stringOrNull(data: $this->data, key: 'schema'));
		$kind->setUpdated(new DateTime());

		return $kind;
	}//end writeOnto()

	/**
	 * The normalised slug.
	 *
	 * @return string The slug.
	 *
	 * @throws TimelineValidationException When the slug is missing.
	 */
	private function readSlug(): string {
		$slug = '';
		if (is_string($this->data['slug'] ?? null) === true) {
			$slug = strtolower(trim($this->data['slug']));
		}

		if ($slug === '') {
			throw new TimelineValidationException(['slug' => 'A kind needs a slug']);
		}

		return $slug;
	}//end readSlug()

	/**
	 * The properties map.
	 *
	 * @return array<string,mixed> The map, empty when none is given.
	 *
	 * @throws TimelineValidationException When the properties are not a map.
	 */
	private function readProperties(): array {
		$properties = ($this->data['properties'] ?? []);
		if (is_array($properties) === false) {
			throw new TimelineValidationException(
				['properties' => 'Properties must be a map of property names to declarations']
			);
		}

		return $properties;
	}//end readProperties()

	/**
	 * The required property names.
	 *
	 * @return array<int,string> The names.
	 */
	private function requiredNames(): array {
		$required = ($this->data['required'] ?? []);
		if (is_array($required) === false) {
			return [];
		}

		return array_values(array_filter($required, 'is_string'));
	}//end requiredNames()
}//end class
