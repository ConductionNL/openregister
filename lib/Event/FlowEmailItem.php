<?php

/**
 * The item a sent flow email was about: its register, schema and object uuid when it is an object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * The item a sent flow email was about: its register, schema and object uuid when it is an object.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */
final class FlowEmailItem {
	/**
	 * Constructor.
	 *
	 * @param string|null $register The register of the item, when the item is an object.
	 * @param string|null $schema The schema of the item, when the item is an object.
	 * @param string|null $objectUuid The uuid of the object.
	 */
	public function __construct(
		public readonly ?string $register,
		public readonly ?string $schema,
		public readonly ?string $objectUuid,
	) {
	}//end __construct()
}//end class
