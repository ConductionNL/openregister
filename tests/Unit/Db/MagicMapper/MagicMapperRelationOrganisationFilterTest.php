<?php

/**
 * MagicMapper relation lookups: the organisation filter by caller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Without a user, a relation lookup keeps to rows without an organisation, unless the caller is the trusted system.
 */
class MagicMapperRelationOrganisationFilterTest extends TestCase {

	/**
	 * The filter fragment a relation lookup gets.
	 *
	 * @param IUser|null $user          The session user.
	 * @param bool       $trustedSystem What the RBAC handler answers for a user-less caller.
	 *
	 * @return array{string, array}
	 */
	private function filterFor(?IUser $user, bool $trustedSystem): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$mapper = new MagicMapper(
			$this->createMock(IDBConnection::class),
			new MagicMapperLookups($this->createMock(SchemaMapper::class), $this->createMock(RegisterMapper::class), $this->container()),
			new MagicMapperIdentity($session, $this->createMock(IGroupManager::class), $this->createMock(IUserManager::class)),
			new MagicMapperRuntime(
				$this->createMock(IConfig::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(IEventDispatcher::class),
				$this->createMock(LoggerInterface::class)
			)
		);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('isTrustedSystemCaller')->willReturn($trustedSystem);
		(new ReflectionProperty(MagicMapper::class, 'rbacHandler'))->setValue($mapper, $rbac);

		return (new ReflectionMethod(MagicMapper::class, 'buildOrganisationFilterForRelation'))->invoke($mapper);
	}//end filterFor()

	public function testAnAnonymousRequestSeesOnlyRowsWithoutAnOrganisation(): void {
		$this->assertSame([' AND _organisation IS NULL', []], $this->filterFor(user: null, trustedSystem: false));
	}//end testAnAnonymousRequestSeesOnlyRowsWithoutAnOrganisation()

	public function testTheTrustedSystemSeesEveryRelatedRow(): void {
		$this->assertSame(['', []], $this->filterFor(user: null, trustedSystem: true));
	}//end testTheTrustedSystemSeesEveryRelatedRow()

	public function testASignedInUserIsNotFilteredHere(): void {
		$this->assertSame(['', []], $this->filterFor(user: $this->createMock(IUser::class), trustedSystem: false));
	}//end testASignedInUserIsNotFilteredHere()

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): ?object => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizerInterface::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface::class
					=> $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);
		return $container;
	}//end container()
}//end class
