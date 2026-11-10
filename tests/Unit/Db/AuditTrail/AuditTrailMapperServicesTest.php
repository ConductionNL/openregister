<?php

/**
 * AuditTrailMapperServices unit test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\AuditTrail
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

namespace OCA\OpenRegister\Tests\Unit\Db\AuditTrail;

use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices
 */
final class AuditTrailMapperServicesTest extends TestCase {
	/**
	 * The bundle hands back exactly the services it was built with.
	 *
	 * @return void
	 */
	public function testItHandsBackTheServicesItWasBuiltWith(): void {
		$container = $this->createMock(ContainerInterface::class);
		$logger = new NullLogger();

		$session = $this->createMock(IUserSession::class);
		$request = $this->createMock(IRequest::class);

		$bundle = new AuditTrailMapperServices($container, $logger, $session, $request);

		$this->assertSame($container, $bundle->container);
		$this->assertSame($logger, $bundle->logger);
		$this->assertSame($session, $bundle->userSession);
		$this->assertSame($request, $bundle->request);
	}//end testItHandsBackTheServicesItWasBuiltWith()
}//end class
