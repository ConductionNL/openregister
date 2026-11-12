<?php

/**
 * Golden: which OpenRegister organisations an example set finds, creates, fills a quota on and deletes, and which it
 * leaves, pinned on the exact outcome and every mapper call.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-finds-openregister-organisations-and-creates-the-ones-its-cast-needs-req-exs-016
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 */
class ExampleSetInstanceGoldenTest extends TestCase {
	/**
	 * Mapper calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * An organisation as stored.
	 *
	 * @param string               $uuid    The uuid.
	 * @param string               $slug    The slug.
	 * @param string               $name    The name.
	 * @param array<int, string>   $members The members.
	 * @param array<string, mixed> $quota   The quota setters to call, by setter.
	 *
	 * @return Organisation
	 */
	private static function organisation(string $uuid, string $slug, string $name, array $members=[], array $quota=[]): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setSlug($slug);
		$organisation->setName($name);
		$organisation->setUsers($members);
		foreach ($quota as $setter => $value) {
			$organisation->$setter($value);
		}

		return $organisation;
	}//end organisation()

	/**
	 * The instance over a recording mapper.
	 *
	 * @param array<string, Organisation|RuntimeException> $bySlug  What findBySlug() finds, by slug; absent is none.
	 * @param array<string, Organisation|RuntimeException> $byUuid  What findByUuid() finds, by uuid; absent is none.
	 * @param array<string, bool>                          $failing Which writes throw: insert, update, delete.
	 *
	 * @return ExampleSetInstance
	 */
	private function instance(array $bySlug=[], array $byUuid=[], array $failing=[]): ExampleSetInstance {
		$mapper = $this->createMock(OrganisationMapperInterface::class);
		$find   = function (array $found, string $key): Organisation {
			$hit = ($found[$key] ?? new DoesNotExistException('none'));
			if ($hit instanceof Organisation) {
				return $hit;
			}

			throw $hit;
		};
		$mapper->method('findBySlug')->willReturnCallback(function (string $slug) use ($bySlug, $find): Organisation {
			$this->calls[] = 'findBySlug '.$slug;
			return $find($bySlug, $slug);
		});
		$mapper->method('findByUuid')->willReturnCallback(function (string $uuid) use ($byUuid, $find): Organisation {
			$this->calls[] = 'findByUuid '.$uuid;
			return $find($byUuid, $uuid);
		});
		foreach (['insert', 'update', 'delete'] as $write) {
			$mapper->method($write)->willReturnCallback(function (Organisation $entity) use ($write, $failing): Organisation {
				$this->calls[] = $write.' '.self::describe(organisation: $entity);
				if (($failing[$write] ?? false) === true) {
					throw new RuntimeException($write.' failed');
				}

				if ($write === 'insert') {
					$entity->setUuid('new-1');
				}

				return $entity;
			});
		}

		$mapper->method('findAll')->willReturn(
			[
				self::organisation(uuid: 'u1', slug: 'gemeente', name: 'Gemeente'),
				self::organisation(uuid: 'u2', slug: 'Gemeente', name: 'Other'),
				self::organisation(uuid: 'u3', slug: '', name: 'No slug'),
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		return new ExampleSetInstance(container: $container, config: $this->createMock(IConfig::class));
	}//end instance()

	/**
	 * An organisation as one line.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return string
	 */
	private static function describe(Organisation $organisation): string {
		return (string) json_encode(
			[
				$organisation->getName(),
				$organisation->getSlug(),
				$organisation->getDescription(),
				$organisation->getType(),
				$organisation->getContactEmail(),
				$organisation->getUsers(),
				[
					$organisation->getStorageQuota(),
					$organisation->getRequestQuota(),
					$organisation->getBandwidthQuota(),
					$organisation->getUserQuota(),
					$organisation->getObjectQuota(),
					$organisation->getObjectQuotaSchema(),
				],
			]
		);
	}//end describe()

	/**
	 * Organisations are found by slug and by name; a slug wins over a name of the same text.
	 *
	 * @return void
	 */
	public function testUuids(): void {
		$this->assertSame(['Gemeente' => 'u2', 'Other' => 'u2', 'No slug' => 'u3', 'gemeente' => 'u1'], $this->instance()->uuids());
	}//end testUuids()

	/**
	 * A missing organisation is created with every declared field; a failed lookup creates nothing.
	 *
	 * @return void
	 */
	public function testEnsureCreatesOrRefuses(): void {
		$declared = [
			'slug'         => 'partner',
			'name'         => 'Partner',
			'description'  => 'A partner',
			'type'         => 'partner',
			'contactEmail' => 'info@partner.example',
			'quota'        => ['storage' => 5, 'objects' => 9, 'objectsSchema' => 'case'],
			'members'      => ['alice', 'bob'],
		];
		$this->assertSame(['uuid' => 'new-1', 'created' => true, 'error' => ''], $this->instance()->ensure(declared: $declared));
		$plain = ['slug' => 'plain', 'name' => 'Plain', 'description' => '', 'type' => '', 'contactEmail' => ''];
		$this->assertSame(['uuid' => 'new-1', 'created' => true, 'error' => ''], $this->instance()->ensure(declared: $plain));
		$this->assertSame(
			['uuid' => '', 'created' => false, 'error' => 'insert failed'],
			$this->instance(failing: ['insert' => true])->ensure(declared: $plain)
		);
		$this->assertSame(
			['uuid' => '', 'created' => false, 'error' => 'database down'],
			$this->instance(bySlug: ['plain' => new RuntimeException('database down')])->ensure(declared: $plain)
		);
		$this->assertSame(
			[
				'findBySlug partner',
				'insert ["Partner","partner","A partner","partner","info@partner.example",["alice","bob"],[5,null,null,null,9,"case"]]',
				'findBySlug plain',
				'insert ["Plain","plain","","organisation",null,[],[null,null,null,null,null,null]]',
				'findBySlug plain',
				'insert ["Plain","plain","","organisation",null,[],[null,null,null,null,null,null]]',
				'findBySlug plain',
			],
			$this->calls
		);
	}//end testEnsureCreatesOrRefuses()

	/**
	 * A found organisation the set made gains the quota it lacks, never one it holds; one the set did not make is
	 * left as it is.
	 *
	 * @return void
	 */
	public function testEnsureFillsOnlyMissingQuotaOfAnOrganisationTheSetMade(): void {
		$found    = self::organisation(uuid: 'org-7', slug: 'mine', name: 'Mine', quota: ['setStorageQuota' => 1]);
		$declared = ['slug' => 'mine', 'quota' => ['storage' => 50, 'users' => 3]];
		$this->assertSame(['uuid' => 'org-7', 'created' => false, 'error' => ''], $this->instance(bySlug: ['mine' => $found])->ensure(declared: $declared, made: ['org-7']));
		$this->assertSame(['uuid' => 'org-7', 'created' => false, 'error' => ''], $this->instance(bySlug: ['mine' => $found])->ensure(declared: $declared, made: []));
		$full = self::organisation(uuid: 'org-8', slug: 'full', name: 'Full', quota: ['setStorageQuota' => 1, 'setUserQuota' => 2]);
		$this->assertSame(['uuid' => 'org-8', 'created' => false, 'error' => ''], $this->instance(bySlug: ['full' => $full])->ensure(declared: ['slug' => 'full', 'quota' => ['users' => 9]], made: ['org-8']));
		$again = self::organisation(uuid: 'org-9', slug: 'again', name: 'Again');
		$this->assertSame(
			['uuid' => 'org-9', 'created' => false, 'error' => 'its quota could not be written: update failed'],
			$this->instance(bySlug: ['again' => $again], failing: ['update' => true])->ensure(declared: ['slug' => 'again', 'quota' => ['requests' => 4]], made: ['org-9'])
		);
		$this->assertSame(
			[
				'findBySlug mine',
				'update ["Mine","mine",null,"organisation",null,[],[1,null,null,3,null,null]]',
				'findBySlug mine',
				'findBySlug full',
				'findBySlug again',
				'update ["Again","again",null,"organisation",null,[],[null,4,null,null,null,null]]',
			],
			$this->calls
		);
	}//end testEnsureFillsOnlyMissingQuotaOfAnOrganisationTheSetMade()

	/**
	 * An organisation is deleted only when every member is ignorable; one with another member is left, a missing one
	 * is gone, and any other failure fails.
	 *
	 * @return void
	 */
	public function testDelete(): void {
		$ignorable = static fn (string $member): bool => in_array($member, ['alice', 'gone'], true);
		$byUuid    = [
			'empty'  => self::organisation(uuid: 'empty', slug: 'e', name: 'E'),
			'ours'   => self::organisation(uuid: 'ours', slug: 'o', name: 'O', members: ['alice', 'gone']),
			'tenant' => self::organisation(uuid: 'tenant', slug: 't', name: 'T', members: ['alice', 'carol']),
			'broken' => new RuntimeException('database down'),
		];
		$instance  = $this->instance(byUuid: $byUuid);
		$this->assertSame(ExampleSetInstance::DELETED, $instance->delete(uuid: 'empty'));
		$this->assertSame(ExampleSetInstance::DELETED, $instance->delete(uuid: 'ours', ignorable: $ignorable));
		$this->assertSame(ExampleSetInstance::LEFT, $instance->delete(uuid: 'ours'));
		$this->assertSame(ExampleSetInstance::LEFT, $instance->delete(uuid: 'tenant', ignorable: $ignorable));
		$this->assertSame(ExampleSetInstance::DELETED, $instance->delete(uuid: 'missing', ignorable: $ignorable));
		$this->assertSame(ExampleSetInstance::FAILED, $instance->delete(uuid: 'broken', ignorable: $ignorable));
		$this->assertSame(ExampleSetInstance::FAILED, $this->instance(byUuid: $byUuid, failing: ['delete' => true])->delete(uuid: 'empty'));
		$this->assertSame(
			[
				'findByUuid empty',
				'delete ["E","e",null,"organisation",null,[],[null,null,null,null,null,null]]',
				'findByUuid ours',
				'delete ["O","o",null,"organisation",null,["alice","gone"],[null,null,null,null,null,null]]',
				'findByUuid ours',
				'findByUuid tenant',
				'findByUuid missing',
				'findByUuid broken',
				'findByUuid empty',
				'delete ["E","e",null,"organisation",null,[],[null,null,null,null,null,null]]',
			],
			$this->calls
		);
	}//end testDelete()
}//end class
