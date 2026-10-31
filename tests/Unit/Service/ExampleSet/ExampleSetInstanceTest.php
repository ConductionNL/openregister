<?php

/**
 * Organisation lookups of an example set: only "does not exist" reads as absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Db\Organisation
 */
class ExampleSetInstanceTest extends TestCase {
	/** @var OrganisationMapper&MockObject */
	private $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(OrganisationMapper::class);
	}

	private function instance(): ExampleSetInstance {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->mapper);

		return new ExampleSetInstance(container: $container, config: $this->createMock(IConfig::class));
	}

	public function testALookupThatFailsIsNotReadAsNoOrganisationAndNothingIsInserted(): void {
		$this->mapper->method('findBySlug')->willThrowException(new RuntimeException('database is gone'));
		$this->mapper->expects($this->never())->method('insert');

		$result = $this->instance()->ensure(declared: ['name' => 'Gemeente', 'slug' => 'demo', 'description' => '']);

		$this->assertSame(['uuid' => '', 'created' => false, 'error' => 'database is gone'], $result);
	}

	public function testAnOrganisationThatDoesNotExistIsCreated(): void {
		$this->mapper->method('findBySlug')->willThrowException(new DoesNotExistException('none'));
		$saved = new Organisation();
		$saved->setUuid('org-new');
		$this->mapper->expects($this->once())->method('insert')->willReturn($saved);

		$result = $this->instance()->ensure(declared: ['name' => 'Gemeente', 'slug' => 'demo', 'description' => '']);

		$this->assertSame(['uuid' => 'org-new', 'created' => true, 'error' => ''], $result);
	}

	/**
	 * A partner organisation is written with its type and contact e-mail, so case sharing lists it.
	 *
	 * @return void
	 */
	public function testAPartnerIsCreatedWithItsTypeAndContact(): void {
		$this->mapper->method('findBySlug')->willThrowException(new DoesNotExistException('none'));
		$written = null;
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Organisation $entity) use (&$written): Organisation {
				$written = $entity;
				$entity->setUuid('org-partner');
				return $entity;
			}
		);

		$this->instance()->ensure(declared: ['name' => 'Omgevingsdienst', 'slug' => 'odzhz', 'description' => '', 'type' => 'partner', 'contactEmail' => 'info@odzhz.example']);

		$this->assertSame(expected: ['partner', 'info@odzhz.example'], actual: [$written->getType(), $written->getContactEmail()]);
	}

	/**
	 * An organisation the set made before its quota was declared gains the declared quota where it has none.
	 *
	 * @return void
	 */
	public function testAnOrganisationTheSetMadeGainsItsDeclaredQuota(): void {
		$found = new Organisation();
		$found->setUuid('org-waterland');
		$found->setUserQuota(80);
		$this->mapper->method('findBySlug')->willReturn($found);
		$written = null;
		$this->mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Organisation $entity) use (&$written): Organisation {
				$written = $entity;
				return $entity;
			}
		);

		$declared = [
			'name'        => 'Gemeente Waterland',
			'slug'        => 'waterland',
			'description' => '',
			'quota'       => ['users' => 50, 'objects' => 5000, 'objectsSchema' => 'case'],
		];
		$result   = $this->instance()->ensure(declared: $declared, made: ['org-waterland']);

		$this->assertSame(expected: ['uuid' => 'org-waterland', 'created' => false, 'error' => ''], actual: $result);
		$this->assertSame(expected: [80, 5000, 'case'], actual: [$written->getUserQuota(), $written->getObjectQuota(), $written->getObjectQuotaSchema()]);
	}

	/**
	 * An organisation the set did not make keeps its quota, also where it has none: none can mean unlimited.
	 *
	 * @return void
	 */
	public function testAnOrganisationTheSetDidNotMakeKeepsItsQuota(): void {
		$found = new Organisation();
		$found->setUuid('org-tenant');
		$this->mapper->method('findBySlug')->willReturn($found);
		$this->mapper->expects($this->never())->method('update');

		$declared = ['name' => 'Gemeente Waterland', 'slug' => 'waterland', 'description' => '', 'quota' => ['users' => 50]];
		$result   = $this->instance()->ensure(declared: $declared, made: ['org-other']);

		$this->assertSame(expected: ['uuid' => 'org-tenant', 'created' => false, 'error' => ''], actual: $result);
	}

	/**
	 * A quota write that fails is answered as an error and the organisation is still found.
	 *
	 * @return void
	 */
	public function testAQuotaWriteThatFailsIsAnError(): void {
		$found = new Organisation();
		$found->setUuid('org-waterland');
		$this->mapper->method('findBySlug')->willReturn($found);
		$this->mapper->method('update')->willThrowException(new RuntimeException('database is gone'));

		$declared = ['name' => 'Gemeente Waterland', 'slug' => 'waterland', 'description' => '', 'quota' => ['users' => 50]];
		$result   = $this->instance()->ensure(declared: $declared, made: ['org-waterland']);

		$error = 'its quota could not be written: database is gone';
		$this->assertSame(expected: ['uuid' => 'org-waterland', 'created' => false, 'error' => $error], actual: $result);
	}

	public function testALookupThatFailsIsNotReadAsGoneAndNothingIsForgotten(): void {
		$this->mapper->method('findByUuid')->willThrowException(new RuntimeException('database is gone'));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame(ExampleSetInstance::FAILED, $this->instance()->delete(uuid: 'org-1'));
	}

	public function testAnOrganisationThatDoesNotExistIsGone(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('none'));

		$this->assertSame(ExampleSetInstance::DELETED, $this->instance()->delete(uuid: 'org-1'));
	}
}
