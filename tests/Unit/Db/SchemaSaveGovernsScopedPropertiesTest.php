<?php

/**
 * The save path asks the scope questions, rather than the questions existing
 * only in a test.
 *
 * 🔴 A CHECK WITH NO CALLER IS THE SAME SHAPE AS NO CHECK AT ALL.
 * `ScopedPropertyGovernance` can answer "may this person add at this scope" and
 * "is this scope full" perfectly and still protect nothing, because a schema
 * saves through `SchemaMapper` and not through the governance. So this suite
 * drives the mapper's own guard, not the governance behind it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * `SchemaMapper::assertScopedPropertiesAreGoverned()`.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 */
class SchemaSaveGovernsScopedPropertiesTest extends TestCase {

	/**
	 * A mapper whose caller is the given user.
	 *
	 * @param string|null   $userId  The caller.
	 * @param array<string> $groups  Their groups.
	 * @param int           $ceiling The configured ceiling.
	 *
	 * @return SchemaMapper The mapper.
	 */
	private function mapperFor(?string $userId, array $groups = [], int $ceiling = 25): SchemaMapper {
		$session = $this->createMock(IUserSession::class);
		if ($userId === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn($ceiling);

		return new SchemaMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(IEventDispatcher::class),
			new PropertyValidatorHandler(),
			$this->createMock(OrganisationMapper::class),
			$session,
			$groupManager,
			$appConfig,
			new NullLogger()
		);
	}//end mapperFor()

	/**
	 * Run the mapper's guard over a schema.
	 *
	 * @param SchemaMapper         $mapper     The mapper.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return void
	 */
	private function govern(SchemaMapper $mapper, array $properties): void {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setProperties($properties);

		$method = new ReflectionMethod(SchemaMapper::class, 'assertScopedPropertiesAreGoverned');
		$method->setAccessible(true);
		$method->invoke($mapper, $schema);
	}//end govern()

	/**
	 * 🔴 SAVING A SCOPED PROPERTY YOU ARE NOT IN THE SCOPE OF IS REFUSED.
	 *
	 * @return void
	 */
	public function testSavingAScopeYouAreNotInIsRefused(): void {
		$this->expectException(ScopedPropertyException::class);

		$this->govern(
			$this->mapperFor('bob', ['team-b']),
			['salary' => ['type' => 'number', 'scope' => 'team-a']]
		);
	}//end testSavingAScopeYouAreNotInIsRefused()

	/**
	 * A member of the scope saves it.
	 *
	 * The control: without it, a guard that always threw would pass the test
	 * above while making the feature unusable.
	 *
	 * @return void
	 */
	public function testAMemberOfTheScopeSavesIt(): void {
		$this->govern(
			$this->mapperFor('alice', ['team-a']),
			['salary' => ['type' => 'number', 'scope' => 'team-a']]
		);

		$this->expectNotToPerformAssertions();
	}//end testAMemberOfTheScopeSavesIt()

	/**
	 * A schema carrying no scope is untouched, whoever saves it.
	 *
	 * The loop must find nothing, or every existing schema in the fleet would
	 * start being gated on a key it does not have.
	 *
	 * @return void
	 */
	public function testASchemaWithoutScopesIsUntouched(): void {
		$this->govern(
			$this->mapperFor(null),
			['name' => ['type' => 'string'], 'age' => ['type' => 'number']]
		);

		$this->expectNotToPerformAssertions();
	}//end testASchemaWithoutScopesIsUntouched()

	/**
	 * A scope at its ceiling is refused on save, naming the ceiling.
	 *
	 * @return void
	 */
	public function testAFullScopeIsRefusedOnSave(): void {
		$properties = [];
		for ($i = 0; $i < 3; $i++) {
			$properties['f' . $i] = ['type' => 'string', 'scope' => 'team-a'];
		}

		try {
			$this->govern($this->mapperFor('alice', ['team-a'], 2), $properties);
			$this->fail('A scope above its ceiling must be refused at save.');
		} catch (ScopedPropertyException $e) {
			$this->assertStringContainsString('ceiling', $e->getMessage());
		}
	}//end testAFullScopeIsRefusedOnSave()

	/**
	 * 🔑 THE GUARD RUNS ON UPDATE AS WELL AS INSERT.
	 *
	 * Derived from the mapper's own source rather than restated. Only on insert,
	 * a scope could be added to an existing schema by anybody, and the ceiling
	 * could be walked past one edit at a time.
	 *
	 * @return void
	 */
	public function testTheGuardRunsOnBothSavePaths(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Db/SchemaMapper.php');

		$calls = substr_count($source, '$this->assertScopedPropertiesAreGoverned(entity:');

		$this->assertSame(
			2,
			$calls,
			'Expected the guard on both insert and update; on insert alone the ceiling is walked past one edit at a time.'
		);
	}//end testTheGuardRunsOnBothSavePaths()
}//end class
