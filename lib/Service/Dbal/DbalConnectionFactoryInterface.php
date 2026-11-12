<?php

/**
 * DbalConnectionFactoryInterface: the part of DbalConnectionFactory that the sources controller uses.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use Doctrine\DBAL\Connection;
use OCA\OpenRegister\Db\Source;

/**
 * The methods of DbalConnectionFactory that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface DbalConnectionFactoryInterface {

	/**
	 * Return a cached read-only DBAL connection for the given database source.
	 *
	 * Fails closed: any credential-resolution or connection error raises a
	 * {@see DbalConnectionException}; an unauthenticated connection is never
	 * returned. The password is resolved through the credential custody seam and
	 * never logged.
	 *
	 * @param Source $source The `type: database` source to connect to.
	 *
	 * @return Connection The open (lazy) DBAL connection.
	 *
	 * @throws DbalConnectionException When the source is misconfigured, the
	 *                                 credential cannot be resolved, or DBAL fails.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DriverManager::getConnection is DBAL's
	 *   only public connection entry point.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function getConnection(Source $source): Connection;
}//end interface
