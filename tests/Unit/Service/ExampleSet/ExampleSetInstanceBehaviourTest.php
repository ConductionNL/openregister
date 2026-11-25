<?php

/**
 * Behaviour tests for ExampleSetInstance: the instance values, and how a set's
 * organisations are found, created, topped up and removed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Behaviour of the instance-level organisations and values an example set uses.
 */
class ExampleSetInstanceBehaviourTest extends TestCase {

	/** @var OrganisationMapperInterface&MockObject */
	private OrganisationMapperInterface $mapper;

	/** @var IConfig&MockObject */
	private IConfig $config;

	/**
	 * What the organisation service answers as the default organisation.
	 *
	 * @var Organisation|RuntimeException
	 */
	private Organisation|RuntimeException $default;

	protected function setUp(): void {
		$this->mapper  = $this->createMock(OrganisationMapperInterface::class);
		$this->config  = $this->createMock(IConfig::class);
		$this->default = self::organisation('default-uuid', 'Default Organisation', 'default', ['admin']);
	}//end setUp()

	private function defaultOrganisation(): Organisation {
		if ($this->default instanceof RuntimeException) {
			throw $this->default;
		}

		return $this->default;
	}//end defaultOrganisation()

	/**
	 * The mapper creates a missing organisation as new-uuid.
	 *
	 * @return void
	 */
	private function insertsAsNew(): void {
		$this->mapper->method('findBySlug')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('insert')->willReturnCallback(
			static function (Organisation $organisation): Organisation {
				$saved = clone $organisation;
				$saved->setUuid('new-uuid');
				return $saved;
			}
		);
	}//end insertsAsNew()

	/**
	 * The members of a new organisation join the default organisation, so it stays their active one.
	 *
	 * @spec openspec/changes/cast-members-keep-the-default-organisation/specs/example-sets/spec.md
	 *
	 * @return void
	 */
	public function testMembersOfANewOrganisationJoinTheDefaultOrganisation(): void {
		$this->insertsAsNew();
		$updated = null;
		$this->mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Organisation $organisation) use (&$updated): Organisation {
				$updated = $organisation;
				return $organisation;
			}
		);

		$result = $this->instance()->ensure(self::declared(['members' => ['marieke', 'pieter']]));

		$this->assertSame(['uuid' => 'new-uuid', 'created' => true, 'error' => ''], $result);
		$this->assertSame('default-uuid', $updated->getUuid());
		$this->assertSame(['admin', 'marieke', 'pieter'], array_values($updated->getUsers()));
	}

	/**
	 * Members already in the default organisation write nothing more.
	 *
	 * @spec openspec/changes/cast-members-keep-the-default-organisation/specs/example-sets/spec.md
	 *
	 * @return void
	 */
	public function testMembersAlreadyInTheDefaultOrganisationWriteNothing(): void {
		$this->insertsAsNew();
		$this->default = self::organisation('default-uuid', 'Default Organisation', 'default', ['pieter']);
		$this->mapper->expects($this->never())->method('update');

		$result = $this->instance()->ensure(self::declared(['members' => ['pieter']]));

		$this->assertSame(['uuid' => 'new-uuid', 'created' => true, 'error' => ''], $result);
	}

	/**
	 * A new organisation without members leaves the default organisation alone.
	 *
	 * @spec openspec/changes/cast-members-keep-the-default-organisation/specs/example-sets/spec.md
	 *
	 * @return void
	 */
	public function testANewOrganisationWithoutMembersLeavesTheDefaultAlone(): void {
		$this->insertsAsNew();
		$this->default = new RuntimeException('must not be asked');
		$this->mapper->expects($this->never())->method('update');

		$result = $this->instance()->ensure(self::declared());

		$this->assertSame(['uuid' => 'new-uuid', 'created' => true, 'error' => ''], $result);
	}

	/**
	 * A default organisation that cannot be read is reported, with the uuid of the organisation that was created.
	 *
	 * @spec openspec/changes/cast-members-keep-the-default-organisation/specs/example-sets/spec.md
	 *
	 * @return void
	 */
	public function testAFailedJoinIsReportedWithTheCreatedOrganisation(): void {
		$this->insertsAsNew();
		$this->default = new RuntimeException('no default');

		$result = $this->instance()->ensure(self::declared(['members' => ['pieter']]));

		$this->assertSame(
			['uuid' => 'new-uuid', 'created' => true, 'error' => 'its members could not join the default organisation: no default'],
			$result
		);
	}

	private function instance(): ExampleSetInstance {
		$container = $this->createMock(ContainerInterface::class);
		$service   = $this->createMock(OrganisationServiceInterface::class);
		$service->method('ensureDefaultOrganisation')->willReturnCallback(fn (): Organisation => $this->defaultOrganisation());
		$container->method('get')->willReturnMap(
			[
				[OrganisationServiceInterface::class, $service],
				[OrganisationMapperInterface::class, $this->mapper],
			]
		);
		return new ExampleSetInstance($container, $this->config);
	}//end instance()

	private static function organisation(string $uuid, string $name='', string $slug='', array $users=[]): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName($name);
		$organisation->setSlug($slug);
		foreach ($users as $user) {
			$organisation->addUser($user);
		}

		return $organisation;
	}//end organisation()

	private static function declared(array $override=[]): array {
		return array_merge(['name' => 'Gemeente', 'slug' => 'gemeente', 'description' => 'Demo'], $override);
	}//end declared()

	public function testValuesCarryTheTrimmedBaseUrl(): void {
		$this->config->method('getSystemValueString')->with('overwrite.cli.url', '')->willReturn('https://nc.example/');

		$this->assertSame(['baseUrl' => 'https://nc.example'], $this->instance()->values());
	}

	public function testValuesAreEmptyWithoutABaseUrl(): void {
		$this->config->method('getSystemValueString')->willReturn('');

		$this->assertSame([], $this->instance()->values());
	}

	public function testUuidsIndexByNameAndSlugSkippingEmptyOnes(): void {
		$this->mapper->method('findAll')->willReturn([self::organisation('u1', 'Gemeente', 'gemeente'), self::organisation('u2', '', 'leeg')]);

		$this->assertSame(['Gemeente' => 'u1', 'gemeente' => 'u1', 'leeg' => 'u2'], $this->instance()->uuids());
	}

	public function testUuidsAreEmptyWhenTheMapperFails(): void {
		$this->mapper->method('findAll')->willThrowException(new \RuntimeException('db'));

		$this->assertSame([], $this->instance()->uuids());
	}

	public function testEnsureKeepsAnExistingOrganisationNotMadeByTheSet(): void {
		$this->mapper->method('findBySlug')->with('gemeente')->willReturn(self::organisation('u1'));
		$this->mapper->expects($this->never())->method('update');
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame(
			['uuid' => 'u1', 'created' => false, 'error' => ''],
			$this->instance()->ensure(self::declared(['quota' => ['storage' => 10]]), ['other'])
		);
	}

	public function testEnsureFillsMissingQuotaOfAnOrganisationTheSetMade(): void {
		$found = self::organisation('u1');
		$found->setUserQuota(5);
		$this->mapper->method('findBySlug')->willReturn($found);
		$this->mapper->expects($this->once())->method('update')->with($found);

		$result = $this->instance()->ensure(self::declared(['quota' => ['storage' => 10, 'users' => 99]]), ['u1']);

		$this->assertSame(['uuid' => 'u1', 'created' => false, 'error' => ''], $result);
		$this->assertSame(10, $found->getStorageQuota());
		$this->assertSame(5, $found->getUserQuota());
	}

	public function testEnsureWritesNothingWhenNoQuotaIsMissing(): void {
		$found = self::organisation('u1');
		$found->setStorageQuota(1);
		$this->mapper->method('findBySlug')->willReturn($found);
		$this->mapper->expects($this->never())->method('update');

		$this->assertSame('', $this->instance()->ensure(self::declared(['quota' => ['storage' => 10]]), ['u1'])['error']);
	}

	public function testEnsureReportsAFailedQuotaWrite(): void {
		$this->mapper->method('findBySlug')->willReturn(self::organisation('u1'));
		$this->mapper->method('update')->willThrowException(new \RuntimeException('locked'));

		$this->assertSame(
			'its quota could not be written: locked',
			$this->instance()->ensure(self::declared(['quota' => ['objects' => 3]]), ['u1'])['error']
		);
	}

	public function testEnsureReportsAFailedLookup(): void {
		$this->mapper->method('findBySlug')->willThrowException(new \RuntimeException('db down'));
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame(['uuid' => '', 'created' => false, 'error' => 'db down'], $this->instance()->ensure(self::declared()));
	}

	public function testEnsureCreatesAMissingOrganisationWithTypeContactQuotaAndMembers(): void {
		$this->mapper->method('findBySlug')->willThrowException(new DoesNotExistException('none'));
		$inserted = null;
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Organisation $organisation) use (&$inserted): Organisation {
				$inserted = $organisation;
				$saved    = clone $organisation;
				$saved->setUuid('new-uuid');
				return $saved;
			}
		);

		$result = $this->instance()->ensure(
			self::declared(['type' => 'gemeente', 'contactEmail' => 'info@gemeente.example', 'quota' => ['requests' => 100], 'members' => ['sanne', 'esra']])
		);

		$this->assertSame(['uuid' => 'new-uuid', 'created' => true, 'error' => ''], $result);
		$this->assertSame('Gemeente', $inserted->getName());
		$this->assertSame('gemeente', $inserted->getSlug());
		$this->assertSame('gemeente', $inserted->getType());
		$this->assertSame('info@gemeente.example', $inserted->getContactEmail());
		$this->assertSame(100, $inserted->getRequestQuota());
		$this->assertNull($inserted->getStorageQuota());
		$this->assertSame(['sanne', 'esra'], array_values($inserted->getUsers()));
	}

	public function testEnsureLeavesTypeAndContactUnsetWhenBlank(): void {
		$this->mapper->method('findBySlug')->willThrowException(new DoesNotExistException('none'));
		$inserted = null;
		$this->mapper->method('insert')->willReturnCallback(
			static function (Organisation $organisation) use (&$inserted): Organisation {
				$inserted = $organisation;
				return $organisation;
			}
		);

		$this->instance()->ensure(self::declared(['type' => '', 'contactEmail' => '']));

		$this->assertNull($inserted->getContactEmail());
	}

	public function testEnsureReportsAFailedInsert(): void {
		$this->mapper->method('findBySlug')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('insert')->willThrowException(new \RuntimeException('duplicate slug'));

		$this->assertSame(['uuid' => '', 'created' => false, 'error' => 'duplicate slug'], $this->instance()->ensure(self::declared()));
	}

	public function testDeleteRemovesAnOrganisationWithoutOtherMembers(): void {
		$organisation = self::organisation('u1', users: ['sanne']);
		$this->mapper->method('findByUuid')->with('u1')->willReturn($organisation);
		$this->mapper->expects($this->once())->method('delete')->with($organisation);

		$this->assertSame('deleted', $this->instance()->delete('u1', static fn (string $member): bool => $member === 'sanne'));
	}

	public function testDeleteLeavesAnOrganisationWithOtherMembers(): void {
		$this->mapper->method('findByUuid')->willReturn(self::organisation('u1', users: ['sanne', 'stranger']));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame('left', $this->instance()->delete('u1', static fn (string $member): bool => $member === 'sanne'));
	}

	public function testDeleteWithoutAnIgnoreRuleLeavesAnyMember(): void {
		$this->mapper->method('findByUuid')->willReturn(self::organisation('u1', users: ['sanne']));

		$this->assertSame('left', $this->instance()->delete('u1'));
	}

	public function testDeleteOfAGoneOrganisationIsDone(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$this->assertSame('deleted', $this->instance()->delete('u1'));
	}

	public function testDeleteReportsAFailedLookupOrDelete(): void {
		$this->mapper->method('findByUuid')->willThrowException(new \RuntimeException('db'));
		$this->assertSame('failed', $this->instance()->delete('u1'));
	}

	public function testDeleteReportsAFailedDelete(): void {
		$this->mapper->method('findByUuid')->willReturn(self::organisation('u1'));
		$this->mapper->method('delete')->willThrowException(new \RuntimeException('fk'));

		$this->assertSame('failed', $this->instance()->delete('u1'));
	}
}//end class
