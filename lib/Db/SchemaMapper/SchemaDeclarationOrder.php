<?php

/**
 * SchemaDeclarationOrder: the sequence in which a schema save checks its declarations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use LogicException;
use ReflectionClass;

/**
 * One slot per check, in the order they run; the first check that throws refuses the save.
 *
 * The schema mapper fills the slots it checks itself. The slots marked
 * (Processes) are filled by the checks Processes contributes through
 * SchemaDeclarationChecksEvent. The order is the order of the constants.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaDeclarationOrder {

	/**
	 * The lifecycle annotation (Processes).
	 */
	public const LIFECYCLE = 10;

	/**
	 * The declared object actions (Processes).
	 */
	public const DECLARED_ACTIONS = 20;

	/**
	 * The MDTO element mapping.
	 */
	public const MDTO_MAPPING = 30;

	/**
	 * The aggregations, advisory.
	 */
	public const AGGREGATIONS = 40;

	/**
	 * The property calculations (Processes).
	 */
	public const CALCULATIONS = 50;

	/**
	 * The consent declarations.
	 */
	public const CONSENT = 60;

	/**
	 * The relation declarations.
	 */
	public const RELATIONS = 70;

	/**
	 * The dependent values and default expressions (Processes).
	 */
	public const DEPENDENT_VALUES = 80;

	/**
	 * The reference loops through declared actions (Processes).
	 */
	public const REFERENCE_LOOPS = 90;

	/**
	 * The named conditions (Processes).
	 */
	public const NAMED_CONDITIONS = 100;

	/**
	 * The administered validations' shape (Processes).
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public const ADMINISTERED_VALIDATIONS = 102;

	/**
	 * The calendars of relative-time conditions (Processes).
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public const RELATIVE_TIME_CALENDARS = 104;

	/**
	 * The quality annotation, advisory.
	 */
	public const QUALITY = 110;

	/**
	 * The dedup annotation, advisory.
	 */
	public const DEDUP = 120;

	/**
	 * The unique hint.
	 */
	public const UNIQUE_HINT = 130;

	/**
	 * The identity declaration.
	 */
	public const IDENTITY = 140;

	/**
	 * The hinge annotation, advisory.
	 */
	public const HINGE = 150;

	/**
	 * The survivorship annotation, advisory.
	 */
	public const SURVIVORSHIP = 160;

	/**
	 * The merge annotation, advisory.
	 */
	public const MERGE = 170;

	/**
	 * The party annotation, advisory.
	 */
	public const PARTY = 180;

	/**
	 * The notification annotation.
	 */
	public const NOTIFICATIONS = 190;

	/**
	 * The widget annotation.
	 */
	public const WIDGETS = 200;

	/**
	 * The archival annotation.
	 */
	public const ARCHIVAL = 210;

	/**
	 * The reference card.
	 */
	public const REFERENCE_CARD = 220;

	/**
	 * The search result.
	 */
	public const SEARCH_RESULT = 230;

	/**
	 * The team-resources declaration (Platform).
	 */
	public const TEAM_RESOURCES = 235;

	/**
	 * The retention annotation.
	 */
	public const RETENTION = 240;

	/**
	 * The handoff annotation.
	 */
	public const HANDOFF = 250;

	/**
	 * The handoff contract binding.
	 */
	public const HANDOFF_CONTRACT = 260;

	/**
	 * The MCP annotation.
	 */
	public const MCP = 270;

	/**
	 * The registry annotation.
	 */
	public const REGISTRY = 280;

	/**
	 * The external links.
	 */
	public const EXTERNAL_LINKS = 290;

	/**
	 * The extending form.
	 */
	public const EXTENDING_FORM = 300;

	/**
	 * The authorization deny block.
	 */
	public const AUTHORIZATION = 310;

	/**
	 * The hierarchy.
	 */
	public const HIERARCHY = 320;

	/**
	 * The department matrix.
	 */
	public const DEPARTMENT_MATRIX = 330;

	/**
	 * The reveal audit.
	 */
	public const REVEAL_AUDIT = 340;

	/**
	 * The classification.
	 */
	public const CLASSIFICATION = 350;

	/**
	 * The break-glass declaration (grants-that-follow-a-slot-a-relation-or-a-reason).
	 */
	public const BREAK_GLASS = 355;

	/**
	 * The confidentiality.
	 */
	public const CONFIDENTIALITY = 360;

	/**
	 * The reversible bulk actions (Processes).
	 */
	public const REVERSIBILITY = 370;

	/**
	 * The review ownership (Processes).
	 */
	public const REVIEW = 380;

	/**
	 * The first and last slot of what objects of a schema do.
	 */
	public const BEHAVIOUR = [self::LIFECYCLE, self::RELATIVE_TIME_CALENDARS];

	/**
	 * The first and last slot of what the data of a schema means.
	 */
	public const DATA = [self::QUALITY, self::RETENTION];

	/**
	 * The first and last slot of how objects leave or enter.
	 */
	public const EXCHANGE = [self::HANDOFF, self::EXTENDING_FORM];

	/**
	 * The first and last slot of who reaches what.
	 */
	public const ACCESS = [self::AUTHORIZATION, self::REVIEW];

	/**
	 * Whether a position is one of the slots.
	 *
	 * @param int $position The position.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function isSlot(int $position): bool {
		return in_array($position, self::slots(), true);
	}//end isSlot()

	/**
	 * The first and last slot of the section a position falls in.
	 *
	 * @param int $position The position.
	 *
	 * @return array{int, int}
	 *
	 * @throws LogicException When the position is in no section.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function sectionOf(int $position): array {
		foreach ([self::BEHAVIOUR, self::DATA, self::EXCHANGE, self::ACCESS] as $section) {
			if ($position >= $section[0] && $position <= $section[1]) {
				return $section;
			}
		}

		throw new LogicException('No schema declaration section holds slot '.$position);
	}//end sectionOf()

	/**
	 * Every slot, in order, by name.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function slots(): array {
		return array_filter((new ReflectionClass(self::class))->getConstants(), static fn (mixed $value): bool => is_int($value));
	}//end slots()
}//end class
