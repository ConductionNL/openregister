<?php

/**
 * Loading a data subject's objects, scoped and fail-quiet.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Subject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Subject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A lookup by identifier is always RBAC- and tenant-scoped; a missing object is null without noise.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class SubjectObjectLoaderScopeTest extends TestCase {

	private MagicMapperInterface&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private SubjectObjectLoader $loader;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(MagicMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->loader = new SubjectObjectLoader(objectMapper: $this->mapper, logger: $this->logger);
	}//end setUp()

	/**
	 * A lookup by identifier is scoped to the caller's rights and tenant.
	 *
	 * @return void
	 */
	public function testALookupByIdentifierIsScoped(): void {
		$object = new ObjectEntity();
		$this->mapper->expects($this->once())->method('find')
			->with('uuid-1', $this->anything(), $this->anything(), $this->anything(), true, true)
			->willReturn($object);

		$this->assertSame($object, $this->loader->loadByIdentifier(identifier: 'uuid-1'));
	}//end testALookupByIdentifierIsScoped()

	/**
	 * A lookup that fails for any reason is null.
	 *
	 * @return void
	 */
	public function testAFailedLookupByIdentifierIsNull(): void {
		$this->mapper->method('find')->willThrowException(new RuntimeException('denied'));

		$this->assertNull($this->loader->loadByIdentifier(identifier: 'uuid-1'));
	}//end testAFailedLookupByIdentifierIsNull()

	/**
	 * An entry whose object no longer exists is null and is not logged.
	 *
	 * @return void
	 */
	public function testAMissingEntryObjectIsNullWithoutNoise(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->never())->method('debug');

		$this->assertNull($this->loader->loadEntry(entry: ['object_uuid' => 'u', 'object_id' => 3], rbac: true, multitenancy: true));
	}//end testAMissingEntryObjectIsNullWithoutNoise()
	/**
	 * An entry naming neither uuid nor id is never looked up.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutIdentifierIsNotLookedUp(): void {
		$this->mapper->expects($this->never())->method('find');

		$this->assertNull($this->loader->loadEntry(entry: ['object_uuid' => '', 'object_id' => 0], rbac: true, multitenancy: true));
	}//end testAnEntryWithoutIdentifierIsNotLookedUp()

	/**
	 * An entry without uuid is loaded by numeric id with the requested scope.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutUuidIsLoadedByIdWithTheRequestedScope(): void {
		$object = new ObjectEntity();
		$this->mapper->expects($this->once())->method('find')
			->with(42, $this->anything(), $this->anything(), $this->anything(), false, true)
			->willReturn($object);

		$this->assertSame($object, $this->loader->loadEntry(entry: ['object_uuid' => '', 'object_id' => 42], rbac: false, multitenancy: true));
	}//end testAnEntryWithoutUuidIsLoadedByIdWithTheRequestedScope()

	/**
	 * An entry the caller may not read is null and logged at debug with its identifier.
	 *
	 * @return void
	 */
	public function testAnUnauthorisedEntryIsNullAndLogged(): void {
		$this->mapper->method('find')->willThrowException(new RuntimeException('not authorised'));
		$this->logger->expects($this->once())->method('debug')->with(
			'[DSR] Object load skipped (not found or not authorised)',
			['identifier' => 'u-7', 'error' => 'not authorised']
		);

		$this->assertNull($this->loader->loadEntry(entry: ['object_uuid' => 'u-7', 'object_id' => 7], rbac: true, multitenancy: true));
	}//end testAnUnauthorisedEntryIsNullAndLogged()
}//end class
