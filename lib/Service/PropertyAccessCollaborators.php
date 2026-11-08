<?php

/**
 * PropertyAccessCollaborators: the property-level services the Db layer looks up, behind their interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Psr\Container\ContainerInterface;

/**
 * Looks up the property read-rule and field-encryption services for the Db layer.
 *
 * The facet and flagged-encryption handlers resolve these lazily from the
 * container they hold. This class asks the container under the same id as
 * before and hands back what it returns, so the Db layer names neither class
 * and the caller's own `instanceof` and failure handling stay as they were.
 *
 * Types only: what is admitted or encrypted stays with the caller.
 */
final class PropertyAccessCollaborators {

	/**
	 * The shared property read-rule handler, exactly as the container returns it.
	 *
	 * @param ContainerInterface|null $container The container the caller holds.
	 *
	 * @return mixed The shared PropertyRbacHandlerInterface instance.
	 */
	public static function propertyRbacFrom(?ContainerInterface $container): mixed {
		return $container->get(PropertyRbacHandler::class);
	}//end propertyRbacFrom()

	/**
	 * The shared field-encryption handler, exactly as the container returns it.
	 *
	 * @param ContainerInterface $container The container the caller holds.
	 *
	 * @return mixed The shared FieldEncryptionHandlerInterface instance.
	 */
	public static function fieldEncryptionFrom(ContainerInterface $container): mixed {
		return $container->get(FieldEncryptionHandler::class);
	}//end fieldEncryptionFrom()
}//end class
