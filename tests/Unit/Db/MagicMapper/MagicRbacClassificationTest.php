<?php

/**
 * The classification comparison compiled into the list and aggregation queries.
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use PDO;
use Psr\Log\NullLogger;

/**
 * A row above the caller's clearance is excluded inside the query, so the page and the total stay true.
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
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationCaller
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationLimits
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationListTerm
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 * @uses   \OCA\OpenRegister\Service\Rbac\ClearanceResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
 */
class MagicRbacClassificationTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const IN_INTERN = "`vertrouwelijkheidaanduiding` IN ('openbaar', 'intern')";

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
		$conditionMatcher->method('resolveDynamicValue')->willReturnArgument(0);

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
	 * A schema with the four-level block and the given authorization.
	 *
	 * @param array<string, mixed>|null $authorization The authorization block.
	 * @param string                    $ceiling       The level a public ceiling starts at.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(?array $authorization = null, string $ceiling = 'vertrouwelijk'): Schema {
		$schema = new Schema();
		$schema->setId(3301);
		$schema->setProperties(['vertrouwelijkheidaanduiding' => ['type' => 'string']]);
		$schema->setAuthorization($authorization);
		$schema->setConfiguration(
			[
				ClassificationScale::ANNOTATION => [
					'property' => 'vertrouwelijkheidaanduiding',
					'levels' => ['openbaar', 'intern', 'vertrouwelijk', 'geheim'],
					'clearances' => [['group' => 'juristen', 'level' => 'geheim'], ['group' => 'medewerkers', 'level' => 'intern']],
					'default' => 'openbaar',
					'ceilings' => [['scope' => 'public', 'atOrAbove' => $ceiling]],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * An open schema is no longer open past the caller's clearance: the one condition is the classification.
	 *
	 * @return void
	 */
	public function testAnOpenSchemaIsNarrowedToTheCallersLabels(): void {
		$predicate = $this->handlerFor('ann', ['medewerkers'])->buildRbacRowPredicateSql(new RbacAccessCall(schema: self::schema()));

		$this->assertNotNull($predicate);
		$this->assertStringContainsString(' AND ' . self::IN_INTERN . ')', $predicate);
	}//end testAnOpenSchemaIsNarrowedToTheCallersLabels()

	/**
	 * Every OR-ed condition carries the classification, the owner's own rows included: it narrows, never widens.
	 *
	 * @return void
	 */
	public function testEveryConditionIsNarrowedByTheClassification(): void {
		$result = $this->handlerFor('ann', ['medewerkers'])->buildRbacConditionsSql(
			new RbacAccessCall(schema: self::schema(authorization: ['read' => ['medewerkers']]))
		);

		$this->assertFalse($result['bypass']);
		$this->assertNotSame([], $result['conditions']);
		foreach ($result['conditions'] as $condition) {
			$this->assertStringEndsWith(' AND ' . self::IN_INTERN . ')', $condition);
		}
	}//end testEveryConditionIsNarrowedByTheClassification()

	/**
	 * A caller cleared to the top and an administrator are not narrowed by classification.
	 *
	 * @return void
	 */
	public function testTheTopClearanceAndAnAdministratorAreNotNarrowed(): void {
		$top = $this->handlerFor('cas', ['juristen'])->buildRbacRowPredicateSql(new RbacAccessCall(schema: self::schema()));
		$admin = $this->handlerFor('root', ['admin'])->buildRbacRowPredicateSql(new RbacAccessCall(schema: self::schema()));

		$this->assertNotNull($top);
		$this->assertStringNotContainsString('vertrouwelijkheidaanduiding', $top);
		$this->assertNull($admin);
	}//end testTheTopClearanceAndAnAdministratorAreNotNarrowed()

	/**
	 * A ceiling at the bottom leaves an anonymous caller nothing, said as deny-all rather than an empty predicate.
	 *
	 * @return void
	 */
	public function testACeilingAdmittingNoLabelDeniesTheAnonymousList(): void {
		$predicate = $this->handlerFor(null)->buildRbacRowPredicateSql(
			new RbacAccessCall(schema: self::schema(authorization: ['read' => ['public']], ceiling: 'openbaar'))
		);

		$this->assertSame('1 = 0', $predicate);
	}//end testACeilingAdmittingNoLabelDeniesTheAnonymousList()

	/**
	 * The QueryBuilder emitter ANDs the same term onto the query ahead of the access conditions.
	 *
	 * @return void
	 */
	public function testTheQueryBuilderPathAndsTheSameTerm(): void {
		$this->queryLog = [];
		$this->handlerFor('ann', ['medewerkers'])->applyRbacFilters(
			new RbacFilterCall(qb: $this->recordingBuilder(), schema: self::schema(authorization: ['read' => ['medewerkers']]))
		);

		$this->assertNotSame([], $this->queryLog);
		$this->assertStringContainsString("andWhere(t.`vertrouwelijkheidaanduiding` IN ('openbaar', 'intern'))", $this->queryLog[0]);
	}//end testTheQueryBuilderPathAndsTheSameTerm()
	/**
	 * Forty rows, twelve above the caller's clearance: a page of twenty is full and the total is twenty-eight.
	 *
	 * The predicate the handler builds is run as SQL over a real table, which
	 * is the bug a filter applied after the query cannot fix: it would return
	 * a short page and count forty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
	 */
	public function testAPageIsFullAndTheTotalCountsOnlyAdmittedRows(): void {
		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->sqliteCreateFunction('JSON_UNQUOTE', static fn (mixed $value): mixed => $value, 1);
		$db->exec('CREATE TABLE rows (_uuid TEXT, _owner TEXT, _authorization TEXT, _status TEXT, vertrouwelijkheidaanduiding TEXT)');
		$insert = $db->prepare('INSERT INTO rows VALUES (?, ?, NULL, NULL, ?)');
		$labels = array_merge(
			array_fill(0, 16, 'openbaar'),
			array_fill(0, 12, 'intern'),
			array_fill(0, 6, 'vertrouwelijk'),
			array_fill(0, 4, 'geheim'),
			[null, 'onbekend']
		);
		foreach ($labels as $index => $label) {
			$insert->execute(['uuid-' . $index, 'someone-else', $label]);
		}

		$predicate = $this->handlerFor('ann', ['medewerkers'])->buildRbacRowPredicateSql(new RbacAccessCall(schema: self::schema()));

		$page = $db->query('SELECT _uuid FROM rows WHERE ' . $predicate . ' ORDER BY _uuid LIMIT 20')->fetchAll(PDO::FETCH_COLUMN);
		$total = (int)$db->query('SELECT COUNT(*) FROM rows WHERE ' . $predicate)->fetchColumn();
		$above = (int)$db->query(
			"SELECT COUNT(*) FROM rows WHERE ({$predicate}) AND (vertrouwelijkheidaanduiding IS NULL OR vertrouwelijkheidaanduiding NOT IN ('openbaar', 'intern'))"
		)->fetchColumn();

		$this->assertCount(20, $page);
		$this->assertSame(28, $total);
		$this->assertSame(0, $above, 'no row above the clearance, unlabelled or unknown, is in the list');
	}//end testAPageIsFullAndTheTotalCountsOnlyAdmittedRows()
}//end class
