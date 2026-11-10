<?php

/**
 * The defaults ConfigurationMapper::insert() fills on a new configuration.
 *
 * One case per branch: a generated uuid, the `default` type and the caller as
 * owner when absent; given values kept; no owner without a session user; and
 * an entity that is not a Configuration left untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Insert defaults, without a database.
 *
 * @covers \OCA\OpenRegister\Db\ConfigurationMapper
 */
final class ConfigurationMapperInsertDefaultsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper whose session holds the given user, or nobody.
	 *
	 * @param string|null $uid The caller's uid, null for no session user.
	 *
	 * @return ConfigurationMapper The mapper.
	 */
	private function mapper(?string $uid): ConfigurationMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');

		return new ConfigurationMapper($db, new TenancyServices($organisations, $session, $this->createMock(IGroupManager::class)), $this->createMock(EntityEventsInterface::class));
	}//end mapper()

	/**
	 * A bare configuration gets a uuid, the default type, the caller as owner and both stamps.
	 *
	 * @return void
	 */
	public function testABareConfigurationGetsEveryDefault(): void {
		$saved = $this->mapper(uid: 'alice')->insert(new Configuration());

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $saved->getUuid());
		$this->assertSame('default', $saved->getType());
		$this->assertSame('alice', $saved->getOwner());
		$this->assertNotNull($saved->getCreated());
		$this->assertNotNull($saved->getUpdated());
	}//end testABareConfigurationGetsEveryDefault()

	/**
	 * Given values are kept.
	 *
	 * @return void
	 */
	public function testGivenValuesAreKept(): void {
		$configuration = new Configuration();
		$configuration->setUuid('c-1');
		$configuration->setType('app');
		$configuration->setOwner('bob');

		$saved = $this->mapper(uid: 'alice')->insert($configuration);

		$this->assertSame(['c-1', 'app', 'bob'], [$saved->getUuid(), $saved->getType(), $saved->getOwner()]);
	}//end testGivenValuesAreKept()

	/**
	 * Without a session user the owner stays unset; the other defaults still apply.
	 *
	 * @return void
	 */
	public function testNoSessionUserLeavesTheOwnerUnset(): void {
		$saved = $this->mapper(uid: null)->insert(new Configuration());

		$this->assertNull($saved->getOwner());
		$this->assertSame('default', $saved->getType());
	}//end testNoSessionUserLeavesTheOwnerUnset()

	/**
	 * An entity that is not a Configuration gets none of the defaults (and the
	 * Configuration return type then refuses it).
	 *
	 * @return void
	 */
	public function testAnotherEntityGetsNoDefaults(): void {
		$entity = new class () extends Entity {
			/**
			 * @var string|null
			 */
			protected ?string $uuid = null;

			/**
			 * @var string|null
			 */
			protected ?string $owner = null;
		};

		try {
			$this->mapper(uid: 'alice')->insert($entity);
			$this->fail('The Configuration return type refuses another entity.');
		} catch (TypeError $error) {
			$this->assertStringContainsString('must be of type OCA\\OpenRegister\\Db\\Configuration', $error->getMessage());
		}

		$this->assertSame([null, null], [$entity->getUuid(), $entity->getOwner()]);
	}//end testAnotherEntityGetsNoDefaults()
}//end class
