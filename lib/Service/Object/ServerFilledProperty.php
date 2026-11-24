<?php

/**
 * ServerFilledProperty: whether the server fills a schema property, so no payload needs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;

/**
 * The one definition of "no field needs to": a default, a computation, a generated identifier, or the serverSet marker.
 *
 * The save path fills each of these on save or through a creating listener. The
 * draft policy's `required` excusals and the form's mapping check both ask it, so
 * a draft that one accepts the other accepts too.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class ServerFilledProperty {

	/**
	 * The property dialect block the serverSet marker lives in.
	 *
	 * @var string
	 */
	public const DIALECT = 'x-openregister';

	/**
	 * Marker: a listener or the server fills this property, so no field needs to.
	 *
	 * @var string
	 */
	public const MARKER_SERVER_SET = 'serverSet';

	/**
	 * Whether the server fills a property: a default, a computation, a generated identifier, or the serverSet marker.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True when no field needs to.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public static function isServerFilled(array $property): bool {
		if (array_key_exists('default', $property) === true || isset($property['computed']) === true) {
			return true;
		}

		// An `x-openregister-generated` identifier is issued by the creating
		// listener, and on the save that leaves draft (decision 227).
		if (array_key_exists(GeneratedIdentifierDeclaration::ANNOTATION, $property) === true) {
			return true;
		}

		$block = ($property[self::DIALECT] ?? null);
		if (is_array($block) === false) {
			return false;
		}

		// Only a literal `true` counts: a marker is a declaration, not a value to coerce.
		return ($block[self::MARKER_SERVER_SET] ?? null) === true;
	}//end isServerFilled()
}//end class
