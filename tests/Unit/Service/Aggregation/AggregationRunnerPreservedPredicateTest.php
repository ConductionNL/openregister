<?php

/**
 * The aggregation path leaves a preserved object out as a list does, on a
 * schema that offers the preservation regime, so a count beside a list
 * agrees with it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\NativeAggregationQuery;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-APS-006 on the aggregation path.
 */
class AggregationRunnerPreservedPredicateTest extends TestCase {

	/**
	 * A runner whose session holds the given user id (null: nobody signed in).
	 *
	 * @param string|null $uid           The caller's id.
	 * @param bool        $tmloMissing Whether the table lacks the `_tmlo` column.
	 *
	 * @return AggregationRunner
	 */
	private function runner(?string $uid, bool $tmloMissing = false): AggregationRunner {
		$healer = $this->createMock(MagicMetadataColumnHealer::class);
		$healer->method('missingFor')->with('register_1_schema_2')->willReturn($tmloMissing === true ? ['_tmlo'] : ['_other']);
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getMetadataColumnHealer')->willReturn($healer);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new AggregationRunner(
			catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $magicMapper),
			placeholders: new PlaceholderResolver($userSession),
			db: $this->createMock(IDBConnection::class),
			cache: $this->createMock(AggregationCache::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $userSession,
			organisationService: $this->createMock(OrganisationService::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
		);
	}

	/**
	 * The WHERE clause addLifecyclePredicates() builds for a schema on a platform.
	 *
	 * @param AggregationRunner $runner   The runner.
	 * @param Schema            $schema   The schema.
	 * @param string            $platform The platform.
	 *
	 * @return string
	 */
	private function lifecycle(AggregationRunner $runner, Schema $schema, string $platform = 'postgres'): string {
		$query = new NativeAggregationQuery(platform: $platform, quote: '"', tableName: 'register_1_schema_2', metric: 'count');
		(new ReflectionMethod(AggregationRunner::class, 'addLifecyclePredicates'))->invoke($runner, $query, $schema);

		return $query->whereSql();
	}//end lifecycle()

	/**
	 * A schema that offers the regime.
	 *
	 * @return Schema
	 */
	private static function preserving(): Schema {
		$schema = new Schema();
		$schema->setConfiguration([Schema::PRESERVE_ANNOTATION => ['enabled' => true]]);

		return $schema;
	}//end preserving()

	/**
	 * On a schema that offers the regime, the working set leaves preserved rows out.
	 *
	 * @return void
	 */
	public function testAPreservingSchemaCountsTheWorkingSet(): void {
		$this->assertStringEndsWith(
			" AND COALESCE(LOWER(_tmlo::jsonb ->> 'archiefstatus'), '') NOT IN ('semi_static', 'semi_statisch', 'gearchiveerd')",
			$this->lifecycle(runner: $this->runner(uid: null), schema: self::preserving())
		);
		$this->assertStringContainsString(
			"COALESCE(LOWER(CASE WHEN json_valid(_tmlo) THEN json_extract(_tmlo, '$.archiefstatus') END), '') NOT IN (",
			$this->lifecycle(runner: $this->runner(uid: null), schema: self::preserving(), platform: 'sqlite')
		);
	}//end testAPreservingSchemaCountsTheWorkingSet()

	/**
	 * A schema without the regime, or a table without `_tmlo`, counts as before.
	 *
	 * @return void
	 */
	public function testWithoutTheRegimeTheCountIsUnchanged(): void {
		$before = $this->lifecycle(runner: $this->runner(uid: null), schema: new Schema());

		$this->assertStringNotContainsString('archiefstatus', $before);
		$this->assertSame($before, $this->lifecycle(runner: $this->runner(uid: null, tmloMissing: true), schema: self::preserving()));
	}//end testWithoutTheRegimeTheCountIsUnchanged()
}//end class
