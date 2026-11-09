<?php

/**
 * The services the referenced-address notifier reads references through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;

/**
 * The object layer, the schemas and the `$ref` resolver a reference path is walked with.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */
final class ReferenceLookupServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objects The object layer, for the referenced object.
	 * @param SchemaMapperInterface     $schemas The schemas, for the properties of a referenced object.
	 * @param RelationCascadeHandler    $refs    Resolves a property's `$ref` to a schema id.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly SchemaMapperInterface $schemas,
		public readonly RelationCascadeHandler $refs,
	) {
	}//end __construct()
}//end class
