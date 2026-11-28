<?php

/**
 * The schema baseline authorization is built once per schema per request.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Per-request memo of the schema-or-register baseline, and its eviction.
 *
 * The register mapper is the observable: every baseline build that falls
 * through to the register asks it which register holds the schema.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerBaselineMemoTest extends TestCase {

	/**
	 * How often the register mapper was asked which register holds the schema.
	 *
	 * @var integer
	 */
	private int $lookups = 0;

	/**
	 * Whether the register mapper fails, to model an unresolvable cascade.
	 *
	 * @var boolean
	 */
	private bool $mapperFails = false;

	/**
	 * The handler under test.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $handler;

	/**
	 * Build a handler over a register mapper that counts its lookups.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$register = new Register();
		$register->setId(3);
		$register->setAuthorization(['read' => ['behandelaars']]);

		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->method('getFirstRegisterWithSchema')->willReturnCallback(
			function (): int {
				$this->lookups++;
				if ($this->mapperFails === true) {
					throw new RuntimeException('database gone');
				}

				return 3;
			}
		);
		$mapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => $id === RegisterMapperInterface::class ? $mapper : null
		);

		$this->handler = PermissionHandlerFactory::create(
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$this->createMock(IAppConfig::class),
			new NullLogger(),
			$container
		);
	}//end setUp()

	/**
	 * A schema with the given id and authorization block.
	 *
	 * @param integer|null $id            The id.
	 * @param array        $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(?int $id, array $authorization=[]): Schema {
		$schema = new Schema();
		if ($id !== null) {
			$schema->setId($id);
		}

		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * The baseline of the declared cascade, read through the public resolver.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array|null The declared block.
	 */
	private function declared(Schema $schema): ?array {
		return $this->handler->resolveDeclaredAuthorization(schema: $schema);
	}//end declared()

	/**
	 * Resolving the same schema three times builds its baseline once.
	 *
	 * @return void
	 */
	public function testTheSameSchemaIsBuiltOncePerRequest(): void {
		$first = $this->declared(schema: $this->schema(id: 7));
		$this->declared(schema: $this->schema(id: 7));
		$third = $this->declared(schema: $this->schema(id: 7));

		$this->assertSame(['read' => ['behandelaars']], $first);
		$this->assertSame($first, $third);
		// The baseline asks for the register once; the token narrower is not
		// wired here, so no other path asks.
		$this->assertSame(1, $this->lookups);
	}//end testTheSameSchemaIsBuiltOncePerRequest()

	/**
	 * A different schema id is its own entry.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsBuiltOnItsOwn(): void {
		$this->declared(schema: $this->schema(id: 7));
		$this->declared(schema: $this->schema(id: 8));

		$this->assertSame(2, $this->lookups);
	}//end testAnotherSchemaIsBuiltOnItsOwn()

	/**
	 * A schema whose own block changed mid-request is read afresh, not served stale.
	 *
	 * @return void
	 */
	public function testAChangedSchemaBlockIsNotServedFromTheMemo(): void {
		$this->assertSame(['read' => ['behandelaars']], $this->declared(schema: $this->schema(id: 7)));

		$tightened = $this->declared(schema: $this->schema(id: 7, authorization: ['read' => ['admin']]));

		$this->assertSame(['read' => ['admin']], $tightened);
	}//end testAChangedSchemaBlockIsNotServedFromTheMemo()

	/**
	 * clearPermissionCache(), which the invalidation listener calls, empties the memo.
	 *
	 * @return void
	 */
	public function testClearingThePermissionCacheEmptiesTheMemo(): void {
		$this->declared(schema: $this->schema(id: 7));
		$this->handler->clearPermissionCache();
		$this->declared(schema: $this->schema(id: 7));

		$this->assertSame(2, $this->lookups);
	}//end testClearingThePermissionCacheEmptiesTheMemo()

	/**
	 * A schema without an id is never memoised.
	 *
	 * @return void
	 */
	public function testASchemaWithoutIdIsBuiltEveryTime(): void {
		$this->assertSame(['read' => ['a']], $this->declared(schema: $this->schema(id: null, authorization: ['read' => ['a']])));
		$this->assertSame(['read' => ['b']], $this->declared(schema: $this->schema(id: null, authorization: ['read' => ['b']])));

		$memo = new \ReflectionProperty(PermissionHandler::class, 'baselineMemo');
		$this->assertSame([], $memo->getValue($this->handler));
	}//end testASchemaWithoutIdIsBuiltEveryTime()

	/**
	 * An unresolvable cascade throws every time and is never frozen into the memo.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCascadeIsNotMemoised(): void {
		$this->mapperFails = true;
		try {
			$this->declared(schema: $this->schema(id: 7));
			$this->fail('An unresolvable cascade must throw.');
		} catch (AuthorizationUnresolvableException $e) {
			$this->assertStringContainsString('7', $e->getMessage());
		}

		$this->mapperFails = false;
		$this->assertSame(['read' => ['behandelaars']], $this->declared(schema: $this->schema(id: 7)));
		$this->assertSame(2, $this->lookups);
	}//end testAnUnresolvableCascadeIsNotMemoised()
}//end class
