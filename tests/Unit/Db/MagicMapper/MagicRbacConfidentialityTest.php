<?php

/**
 * The confidentiality block's derived rule compiled into the list and aggregation queries.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
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

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;
use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\MagicMapper\RbacFilterCall;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A classified row the caller may not read is excluded inside the query, so the page and the total stay true.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacAccessCall
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacResolvers
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacMatchSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacOperatorTerm
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacCollaborators
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFallbacks
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityReadGate
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityRule
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationCaller
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationLimits
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationListTerm
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 * @uses   \OCA\OpenRegister\Service\Rbac\ClearanceResolver
 *
 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
 */
class MagicRbacConfidentialityTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const NOW = '2026-11-14 12:00:00';

	private const BESLUIT = [
		'tiers' => ['public', 'confidential'],
		'tierProperty' => 'tier',
		'releaseAtProperty' => 'releaseat',
		'clearance' => ['confidential' => 'raadsleden'],
	];

	/**
	 * A handler whose caller is the given user in the given groups, or anonymous.
	 *
	 * @param string|null   $userId The caller, null for anonymous.
	 * @param array<string> $groups The caller's groups.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function handlerFor(?string $userId, array $groups = []): MagicRbacHandler {
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
			$groupManager->method('getUserGroupIds')->willReturn($groups);
		}

		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$conditionMatcher->method('resolveDynamicValue')->willReturnCallback(
			static fn (mixed $value): mixed => ($value === '$now' ? self::NOW : $value)
		);

		return new MagicRbacHandler(
			identity: new MagicMapperIdentity(
				userSession: $userSession,
				groupManager: $groupManager,
				userManager: $this->createMock(IUserManager::class)
			),
			settings: new MagicRbacSettings(appConfig: $this->createMock(IAppConfig::class)),
			support: new MagicRbacSupport(
				conditionMatcher: $conditionMatcher,
				container: $this->createMock(ContainerInterface::class),
				logger: new NullLogger()
			),
			resolvers: new RbacResolvers(
				objectScopeResolver: null,
				objectGrantResolver: null,
				denyResolver: new DenyResolver(new DenyEntryMatcher())
			)
		);
	}//end handlerFor()

	/**
	 * A besluit schema with the given block and authorization.
	 *
	 * @param array<string, mixed>|null $block         The confidentiality block, null for none.
	 * @param array<string, mixed>|null $authorization The authorization block.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(?array $block = self::BESLUIT, ?array $authorization = null): Schema {
		$schema = new Schema();
		$schema->setId(4501);
		$schema->setSlug('besluit');
		$schema->setProperties(['tier' => ['type' => 'string'], 'releaseat' => ['type' => 'string', 'format' => 'date-time']]);
		$schema->setAuthorization($authorization);
		$configuration = [];
		if ($block !== null) {
			$configuration['x-openregister-confidentiality'] = $block;
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * Ten confidential rows, three released, plus two public rows and one owned by pieter.
	 *
	 * @return PDO The database.
	 */
	private static function rows(): PDO {
		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->sqliteCreateFunction('JSON_UNQUOTE', static fn (mixed $value): mixed => $value, 1);
		$db->exec('CREATE TABLE rows (_uuid TEXT, _owner TEXT, _authorization TEXT, _status TEXT, tier TEXT, releaseat TEXT)');
		$insert = $db->prepare('INSERT INTO rows VALUES (?, ?, NULL, NULL, ?, ?)');
		for ($index = 0; $index < 10; $index++) {
			$insert->execute(['confidential-' . $index, 'someone-else', 'confidential', ($index < 3 ? '2020-01-01 00:00:00' : null)]);
		}

		$insert->execute(['future', 'someone-else', 'confidential', '2099-01-01 00:00:00']);
		$insert->execute(['public', 'someone-else', 'public', null]);
		$insert->execute(['untiered', 'someone-else', null, null]);
		$insert->execute(['own', 'pieter', 'confidential', null]);

		return $db;
	}//end rows()

	/**
	 * The uuids the predicate admits, sorted.
	 *
	 * @param string|null $predicate The predicate, null for none.
	 *
	 * @return array<int, string>
	 */
	private static function admitted(?string $predicate): array {
		$where = ($predicate ?? '1 = 1');
		$uuids = self::rows()->query('SELECT _uuid FROM rows WHERE ' . $where . ' ORDER BY _uuid')->fetchAll(PDO::FETCH_COLUMN);

		return array_values($uuids);
	}//end admitted()

	/**
	 * The row predicate a caller's list of the schema gets.
	 *
	 * @param string|null   $userId The caller.
	 * @param array<string> $groups Its groups.
	 * @param Schema|null   $schema The schema.
	 *
	 * @return string|null
	 */
	private function predicate(?string $userId, array $groups = [], ?Schema $schema = null): ?string {
		return $this->handlerFor($userId, $groups)->buildRbacRowPredicateSql(new RbacAccessCall(schema: ($schema ?? self::schema())));
	}//end predicate()

	/**
	 * Below clearance only the released, untiered, public and owned rows are listed, and the total counts only those.
	 *
	 * @return void
	 */
	public function testBelowClearanceOnlyReleasedRowsOfTheTierAreListedAndCounted(): void {
		$predicate = $this->predicate('pieter', ['staff']);
		$db = self::rows();
		$total = (int)$db->query('SELECT COUNT(*) FROM rows WHERE ' . $predicate . " AND tier = 'confidential' AND _owner <> 'pieter'")->fetchColumn();

		$this->assertSame(['confidential-0', 'confidential-1', 'confidential-2', 'own', 'public', 'untiered'], self::admitted($predicate));
		$this->assertSame(3, $total, 'of ten confidential rows of others, the three released ones');
	}//end testBelowClearanceOnlyReleasedRowsOfTheTierAreListedAndCounted()

	/**
	 * With clearance every row is listed.
	 *
	 * @return void
	 */
	public function testWithClearanceEveryRowIsListed(): void {
		$this->assertCount(14, self::admitted($this->predicate('jan', ['raadsleden'])));
	}//end testWithClearanceEveryRowIsListed()

	/**
	 * An anonymous caller sees what everyone may: released, public and untiered rows.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerSeesOnlyTheOpenRows(): void {
		$this->assertSame(['confidential-0', 'confidential-1', 'confidential-2', 'public', 'untiered'], self::admitted($this->predicate(null)));
	}//end testAnAnonymousCallerSeesOnlyTheOpenRows()

	/**
	 * An administrator is not filtered at all.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsNotNarrowed(): void {
		$this->assertNull($this->predicate('root', ['admin']));
	}//end testAnAdministratorIsNotNarrowed()

	/**
	 * The block narrows the schema's authorization, it does not widen it: a caller the rules refuse still gets nothing.
	 *
	 * @return void
	 */
	public function testTheBlockNeverWidensTheSchemasAuthorization(): void {
		$schema = self::schema(authorization: ['read' => ['raadsleden']]);
		$result = $this->handlerFor('pieter', ['staff'])->buildRbacConditionsSql(new RbacAccessCall(schema: $schema));

		foreach ($result['conditions'] as $condition) {
			$this->assertStringContainsString('releaseat', $condition, 'every condition is narrowed by the block');
		}

		$this->assertSame(['own'], self::admitted('(' . implode(' OR ', $result['conditions']) . ')'), 'only the own row; no public or untiered row of others');
	}//end testTheBlockNeverWidensTheSchemasAuthorization()

	/**
	 * A faulty block hides every row with a tier from a cleared caller; untiered and owned rows stay.
	 *
	 * @return void
	 */
	public function testAnInvalidBlockFailsClosed(): void {
		$invalid = array_merge(self::BESLUIT, ['clearance' => ['secret' => 'college']]);
		$predicate = $this->predicate('jan', ['raadsleden'], self::schema($invalid));

		$this->assertSame(['untiered'], self::admitted($predicate));
	}//end testAnInvalidBlockFailsClosed()

	/**
	 * A schema without the block is not narrowed by it.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheBlockIsUnaffected(): void {
		$predicate = $this->predicate('pieter', ['staff'], self::schema(null));

		$this->assertStringNotContainsString('tier', (string)$predicate);
		$this->assertCount(14, self::admitted($predicate));
	}//end testASchemaWithoutTheBlockIsUnaffected()

	/**
	 * Only the read is narrowed.
	 *
	 * @return void
	 */
	public function testOtherActionsAreNotNarrowed(): void {
		$predicate = $this->handlerFor('pieter', ['staff'])->buildRbacRowPredicateSql((new RbacAccessCall(schema: self::schema()))->forAction('update'));

		$this->assertStringNotContainsString('releaseat', (string)$predicate);
	}//end testOtherActionsAreNotNarrowed()

	/**
	 * The QueryBuilder emitter ANDs the same term onto the query ahead of the access conditions.
	 *
	 * @return void
	 */
	public function testTheQueryBuilderPathAndsTheTerm(): void {
		$this->queryLog = [];
		$this->handlerFor('pieter', ['staff'])->applyRbacFilters(
			new RbacFilterCall(qb: $this->recordingBuilder(), schema: self::schema())
		);

		$this->assertNotSame([], $this->queryLog);
		$this->assertStringContainsString('andWhere(', $this->queryLog[0]);
		$this->assertStringContainsString('t.`releaseat` <=', $this->queryLog[0]);
		$this->assertStringContainsString(self::NOW, $this->queryLog[0]);
	}//end testTheQueryBuilderPathAndsTheTerm()
	/**
	 * A schema that also declares a classification scale is narrowed by both: neither block widens the other.
	 *
	 * @return void
	 */
	public function testAClassificationScaleAndTheBlockBothNarrow(): void {
		$schema = self::schema();
		$schema->setProperties(array_merge($schema->getProperties(), ['level' => ['type' => 'string']]));
		$schema->setConfiguration(
			array_merge(
				$schema->getConfiguration(),
				[
					ClassificationScale::ANNOTATION => [
						'property' => 'level',
						'levels' => ['openbaar', 'intern', 'geheim'],
						'clearances' => [['group' => 'staff', 'level' => 'intern']],
						'default' => 'openbaar',
					],
				]
			)
		);

		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->sqliteCreateFunction('JSON_UNQUOTE', static fn (mixed $value): mixed => $value, 1);
		$db->exec('CREATE TABLE rows (_uuid TEXT, _owner TEXT, _authorization TEXT, _status TEXT, tier TEXT, releaseat TEXT, level TEXT)');
		$insert = $db->prepare('INSERT INTO rows VALUES (?, ?, NULL, NULL, ?, ?, ?)');
		$insert->execute(['public-intern', 'someone-else', 'public', null, 'intern']);
		$insert->execute(['public-geheim', 'someone-else', 'public', null, 'geheim']);
		$insert->execute(['released-intern', 'someone-else', 'confidential', '2020-01-01 00:00:00', 'intern']);
		$insert->execute(['unreleased-intern', 'someone-else', 'confidential', null, 'intern']);

		$predicate = $this->predicate('pieter', ['staff'], $schema);
		$listed = $db->query('SELECT _uuid FROM rows WHERE ' . $predicate . ' ORDER BY _uuid')->fetchAll(PDO::FETCH_COLUMN);

		$this->assertSame(['public-intern', 'released-intern'], $listed, 'geheim is above the clearance, the unreleased row below the tier clearance');
	}//end testAClassificationScaleAndTheBlockBothNarrow()
}//end class
