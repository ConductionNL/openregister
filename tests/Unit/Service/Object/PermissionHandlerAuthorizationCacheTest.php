<?php

/**
 * The invariant PermissionHandler documented and nothing enforced.
 *
 * `clearInheritFromPublicCache()` says, in its own docblock:
 *
 *   "The cache keys purely on schema id, which is collision-free only while
 *    schema/register/IAppConfig authorization is immutable mid-request. Any path
 *    that mutates authorization and then re-reads it within the same request must
 *    bust this cache to avoid serving a stale verdict."
 *
 * Until AuthorizationCacheInvalidationListener existed, no production code called
 * it — nor its sibling `clearPermissionCache()`. The hazard is therefore not
 * theoretical, and this test states it as an executable fact rather than prose:
 * `testASecondReadAfterAPolicyChangeServesTheSTALEVerdict` asserts the WRONG
 * answer, because that is what the code really does, and it is the reason the
 * evictor has to be wired to something. Remove the memo and that test goes red;
 * remove the evictor and the one after it goes red.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Per-request memoisation of the inheritFromPublic verdict, and its eviction.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerAuthorizationCacheTest extends TestCase {

	/**
	 * The handler under test, built over mocked collaborators.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $handler;

	/**
	 * Build a handler whose only real state is the two per-request memos.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$this->handler = new PermissionHandler(
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			new NullLogger(),
			$this->createMock(ContainerInterface::class)
		);

	}//end setUp()

	/**
	 * A schema whose authorization block pins inheritFromPublic explicitly.
	 *
	 * @param boolean $inherit The pinned value.
	 *
	 * @return Schema The schema.
	 */
	private function schemaPinning(bool $inherit): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setAuthorization(['inheritFromPublic' => $inherit]);

		return $schema;
	}//end schemaPinning()

	/**
	 * The positive control: the schema-level flag is what gets read. Without
	 * this, every assertion below could be satisfied by a resolver that always
	 * answers the same thing.
	 *
	 * @return void
	 */
	public function testTheSchemaLevelFlagIsWhatGetsResolved(): void {
		$this->assertTrue($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: true)));

		$this->handler->clearInheritFromPublicCache();

		$this->assertFalse($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: false)));

	}//end testTheSchemaLevelFlagIsWhatGetsResolved()

	/**
	 * THE HAZARD, stated as an executable fact. Read once, tighten the policy,
	 * read again inside the same request: the memo answers with the verdict from
	 * BEFORE the change. This asserts the wrong answer deliberately — it is the
	 * behaviour that makes the evictor necessary, and if a future change makes
	 * the memo schema-content-aware this test goes red and should be deleted
	 * along with the eviction it justifies.
	 *
	 * @return void
	 */
	public function testASecondReadAfterAPolicyChangeServesTheSTALEVerdict(): void {
		$this->assertTrue($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: true)));

		// Same schema id, authorization now tightened to deny the inheritance.
		$tightened = $this->schemaPinning(inherit: false);

		$this->assertTrue(
			$this->handler->resolveInheritFromPublic(schema: $tightened),
			'The memo keys on schema id alone, so it serves the pre-change verdict. '
			. 'This is the stale grant AuthorizationCacheInvalidationListener exists to prevent.'
		);

	}//end testASecondReadAfterAPolicyChangeServesTheSTALEVerdict()

	/**
	 * THE FIX. Evicting that schema's entry — what the listener does on
	 * SchemaUpdatedEvent — makes the next read see the tightened policy.
	 *
	 * @return void
	 */
	public function testEvictingTheSchemaEntryMakesTheNextReadSeeTheNewPolicy(): void {
		$this->assertTrue($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: true)));

		$this->handler->clearInheritFromPublicCache(schemaId: 7);

		$this->assertFalse(
			$this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: false)),
			'After eviction the resolver must re-read the schema rather than answer from the memo.'
		);

	}//end testEvictingTheSchemaEntryMakesTheNextReadSeeTheNewPolicy()

	/**
	 * Evicting a DIFFERENT schema must not clear this one — otherwise the
	 * targeted eviction is indistinguishable from clearing everything and the
	 * `$schemaId` argument is decorative.
	 *
	 * @return void
	 */
	public function testEvictingAnotherSchemaLeavesThisOneMemoised(): void {
		$this->assertTrue($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: true)));

		$this->handler->clearInheritFromPublicCache(schemaId: 999);

		$this->assertTrue($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: false)));

	}//end testEvictingAnotherSchemaLeavesThisOneMemoised()

	/**
	 * A null id clears the whole map — the fail-safe path the listener uses for
	 * register events and for a schema that carries no id.
	 *
	 * @return void
	 */
	public function testANullSchemaIdClearsEveryEntry(): void {
		$this->assertTrue($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: true)));

		$this->handler->clearInheritFromPublicCache(schemaId: null);

		$this->assertFalse($this->handler->resolveInheritFromPublic(schema: $this->schemaPinning(inherit: false)));

	}//end testANullSchemaIdClearsEveryEntry()
}//end class
