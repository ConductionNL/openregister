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
