<?php

/**
 * The register of officials is read from the configured source (REQ-DKP-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Anonymisation\OfficialsDirectory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class OfficialsDirectoryTest extends TestCase {

	private function user(string $displayName): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);

		return $user;
	}//end user()

	public function testNoSourceListsNoOfficials(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('get');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertSame([], (new OfficialsDirectory($groups, $container, $this->createMock(LoggerInterface::class)))->officials(source: null));
	}//end testNoSourceListsNoOfficials()

	public function testAGroupsSourceListsEachMemberUnderTheGroupsCapacity(): void {
		$college = $this->createMock(IGroup::class);
		$college->method('getUsers')->willReturn([$this->user(displayName: 'P. de Vries'), $this->user(displayName: 'A. Smit')]);
		$staff = $this->createMock(IGroup::class);
		$staff->method('getUsers')->willReturn([$this->user(displayName: 'J. Bakker')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnMap([['college', $college], ['staff', $staff], ['gone', null]]);

		$officials = (new OfficialsDirectory($groups, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class)))->officials(
			source: ['type' => 'groups', 'groups' => ['college' => 'public-office', 'gone' => 'private', 'staff' => 'professional']]
		);

		$this->assertSame(
			[
				['name' => 'P. de Vries', 'capacity' => 'public-office'],
				['name' => 'A. Smit', 'capacity' => 'public-office'],
				['name' => 'J. Bakker', 'capacity' => 'professional'],
			],
			$officials
		);
	}//end testAGroupsSourceListsEachMemberUnderTheGroupsCapacity()

	public function testASchemaSourceListsTheNameAndCapacityPropertiesReadAsTheSystem(): void {
		$alderman = new ObjectEntity();
		$alderman->setObject(['naam' => 'P. de Vries', 'rol' => 'public-office']);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('findAll')
			->with(
				['filters' => ['register' => '5', 'schema' => 'functionarissen'], 'limit' => OfficialsDirectory::LIMIT],
				false,
				false
			)
			->willReturn(
				[
					$alderman,
					['naam' => 'J. Bakker', 'rol' => 'professional'],
					['naam' => 'X. Onbekend', 'rol' => 'koning'],
					['naam' => '  ', 'rol' => 'private'],
					'not an object',
				]
			);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($objectService);

		$officials = (new OfficialsDirectory($this->createMock(IGroupManager::class), $container, $this->createMock(LoggerInterface::class)))->officials(
			source: ['type' => 'schema', 'register' => '5', 'schema' => 'functionarissen', 'nameProperty' => 'naam', 'capacityProperty' => 'rol']
		);

		$this->assertSame(
			[['name' => 'P. de Vries', 'capacity' => 'public-office'], ['name' => 'J. Bakker', 'capacity' => 'professional']],
			$officials
		);
	}//end testASchemaSourceListsTheNameAndCapacityPropertiesReadAsTheSystem()

	public function testAnUnreadableSourceListsNoOfficialsAndIsLogged(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('register gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[OfficialsDirectory] The officials source could not be read; capacity falls back to role words',
			['type' => 'schema', 'error' => 'register gone']
		);

		$officials = (new OfficialsDirectory($this->createMock(IGroupManager::class), $container, $logger))->officials(
			source: ['type' => 'schema', 'register' => '5', 'schema' => '9', 'nameProperty' => 'name', 'capacityProperty' => 'capacity']
		);

		$this->assertSame([], $officials);
	}//end testAnUnreadableSourceListsNoOfficialsAndIsLogged()
}//end class
