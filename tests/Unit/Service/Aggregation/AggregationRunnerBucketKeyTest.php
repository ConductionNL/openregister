<?php

/**
 * Bucket keys without a time part are midnight UTC, whatever the clock says.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * MySQL and SQLite emit a day bucket as `Y-m-d` and a minute bucket as
 * `Y-m-d H:i`. A key parsed from such text must not take the missing time
 * fields from the wall clock: a day bucket is midnight UTC, a minute bucket
 * has zero seconds.
 */
class AggregationRunnerBucketKeyTest extends TestCase {

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function keys(): array {
		return [
			'date only' => ['2026-05-01', '2026-05-01T00:00:00Z'],
			'date and minute' => ['2026-05-01 10:30', '2026-05-01T10:30:00Z'],
			'date and second' => ['2026-05-01 10:30:15', '2026-05-01T10:30:15Z'],
			'iso without zone' => ['2026-05-01T10:30:15', '2026-05-01T10:30:15Z'],
			'iso with zone' => ['2026-05-01T10:30:15+02:00', '2026-05-01T08:30:15Z'],
		];
	}//end keys()

	#[DataProvider('keys')]
	public function testBucketKeyTakesNothingFromTheClock(string $raw, string $expected): void {
		$method = new ReflectionMethod(AggregationRunner::class, 'coerceBucketKey');
		$this->assertSame($expected, $method->invoke($this->runner(), $raw));
	}//end testBucketKeyTakesNothingFromTheClock()

	/**
	 * A runner with inert collaborators.
	 *
	 * @return AggregationRunner
	 */
	private function runner(): AggregationRunner {
		$session = $this->createMock(IUserSession::class);
		return new AggregationRunner(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
			placeholders: new PlaceholderResolver($session),
			db: $this->createMock(IDBConnection::class),
			cache: $this->createMock(AggregationCache::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $session,
			organisationService: $this->createMock(OrganisationService::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class)
		);
	}//end runner()
}//end class
