<?php

/**
 * Golden test for the per-file entity reads of EntityRelationMapper.
 *
 * Records every query-builder call and the rows of findEntitiesForFile() and
 * findEntitiesForAnonymization(). Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\EntityRelationMapper
 */
class EntityRelationFileEntitiesGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/entity-relation-file-entities-golden.json';

	public function testFileEntityReadsMatchGolden(): void {
		$rows = [['relation_id' => 1, 'entity_type' => 'person', 'entity_value' => 'A'], ['relation_id' => 2, 'entity_type' => 'email', 'entity_value' => 'b@c']];
		$actual = [];
		foreach (['findEntitiesForFile', 'findEntitiesForAnonymization'] as $method) {
			foreach (['rows' => [$rows], 'none' => [[]]] as $name => $served) {
				$this->queryLog = [];
				$this->builderCount = 0;
				$this->parameterCount = 0;
				$this->queuedRows = $served;
				$db = $this->createMock(IDBConnection::class);
				$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
				$mapper = new EntityRelationMapper(
					$db,
					$this->createMock(AuditTrailMapperInterface::class),
					$this->createMock(IUserSession::class),
					new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)),
					new NullLogger()
				);
				$actual["{$method} / {$name}"] = ['result' => $mapper->$method(fileId: 42), 'queries' => $this->queryLog];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFileEntityReadsMatchGolden()
}//end class
