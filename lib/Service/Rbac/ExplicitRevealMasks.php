<?php

/**
 * ExplicitRevealMasks: the explicit-reveal reads and masks callers outside lib/Service call statically.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * Each method hands its arguments, unchanged, to the ExplicitReveal method of
 * the same name and returns its answer, so callers outside lib/Service name
 * this class rather than ExplicitReveal. Types only: no rule lives here.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Delegates to ExplicitReveal's static reads.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
final class ExplicitRevealMasks {

	/**
	 * Whether a property definition makes the property explicit-reveal.
	 *
	 * @param mixed $definition The property definition.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function isExplicit(mixed $definition): bool {
		return ExplicitReveal::isExplicit(definition: $definition);
	}//end isExplicit()

	/**
	 * The explicit-reveal properties of a schema.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function explicitProperties(?Schema $schema): array {
		return ExplicitReveal::explicitProperties(schema: $schema);
	}//end explicitProperties()

	/**
	 * Mask every explicit-reveal value of an object, with no grant.
	 *
	 * @param Schema|null          $schema The object's schema.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The object, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function maskAll(?Schema $schema, array $object): array {
		return ExplicitReveal::maskAll(schema: $schema, object: $object);
	}//end maskAll()
}//end class
