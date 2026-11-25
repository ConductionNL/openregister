<?php

/**
 * OpenRegister team resource declaration.
 *
 * What a schema says about appearing on Nextcloud team pages: the property of
 * its objects that names the team owning them. A schema that declares nothing
 * stays off every team page (design D-3), and ownership is read from that
 * property alone, never from a membership model of its own (design D-1).
 *
 * @category Reference
 * @package  OCA\OpenRegister\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Reference;

use OCA\OpenRegister\Db\Schema;

/**
 * The team-page declaration a schema makes, read from `x-openregister-team-resources`.
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */
final class TeamResourceDeclaration {

	/**
	 * The annotation key a schema declares its team-page appearance under.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-team-resources';

	/**
	 * Constructor.
	 *
	 * @param string $teamProperty The object property naming the owning team.
	 */
	private function __construct(
		public readonly string $teamProperty,
	) {
	}//end __construct()

	/**
	 * The declaration of a schema, or null when its objects stay off team pages.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return self|null The declaration, or null when the schema declares nothing usable.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public static function of(Schema $schema): ?self {
		$block = (($schema->getConfiguration() ?? [])[self::ANNOTATION] ?? null);
		if (is_array($block) === false) {
			return null;
		}

		$property = ($block['teamProperty'] ?? null);
		if (is_string($property) === false || trim($property) === '') {
			return null;
		}

		return new self(teamProperty: trim($property));
	}//end of()

	/**
	 * What is wrong with a declared block, one message per fault.
	 *
	 * @param mixed                $block      The annotation value as saved.
	 * @param array<string, mixed> $properties The schema's properties, by name.
	 *
	 * @return list<string> The faults; empty when the block is absent or sound.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public static function faults(mixed $block, array $properties): array {
		if ($block === null) {
			return [];
		}

		if (is_array($block) === false) {
			return [self::ANNOTATION . ' must be an object.'];
		}

		$property = ($block['teamProperty'] ?? null);
		if (is_string($property) === false || trim($property) === '') {
			return [self::ANNOTATION . '.teamProperty must name the property holding the owning team.'];
		}

		if (array_key_exists(trim($property), $properties) === false) {
			return [self::ANNOTATION . '.teamProperty "' . $property . '" is not a property of this schema.'];
		}

		return [];
	}//end faults()

	/**
	 * The teams an object's data names as its owners.
	 *
	 * The property holds one team id, or a list of them; anything else names
	 * no team, so an object with an empty or malformed value belongs to none.
	 *
	 * @param array<string, mixed> $objectData The object's own data.
	 *
	 * @return list<string> The owning team ids, without duplicates.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-the-provider-answers-whether-one-object-belongs-to-a-team-req-ptr-002
	 */
	public function teamsOf(array $objectData): array {
		$value = ($objectData[$this->teamProperty] ?? null);
		if (is_array($value) === false) {
			$value = [$value];
		}

		$teams = [];
		foreach ($value as $team) {
			if ((is_string($team) === true || is_int($team) === true) && trim((string)$team) !== '') {
				$teams[] = trim((string)$team);
			}
		}

		return array_values(array_unique($teams));
	}//end teamsOf()
}//end class
