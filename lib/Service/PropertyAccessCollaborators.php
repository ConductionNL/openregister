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
 * Looks up the field-encryption service for the Db layer.
 *
 * The flagged-encryption handler resolves it lazily from the
 * container it holds. This class asks the container under the same id as
 * before and hands back what it returns, so the Db layer does not name the class
 * and the caller's own `instanceof` and failure handling stay as they were.
 *
 * Types only: what is encrypted stays with the caller.
 */
final class PropertyAccessCollaborators {

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
