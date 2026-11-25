<?php

/**
 * Golden test for the foreign identifier store's queries: the exact lookup is
 * by issuer AND value, and an object's list is by its UUID.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ForeignIdentifier;
use OCA\OpenRegister\Db\ForeignIdentifierMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForeignIdentifierMapper::class)]
#[UsesClass(ForeignIdentifier::class)]
final class ForeignIdentifierMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/foreign-identifier-mapper-golden.json';

	public function testOutcomesMatchGolden(): void {
		$row = ['id' => 5, 'object_uuid' => 'zaak-1', 'issuer' => 'zgw:gemeente-x', 'value' => '1001', 'created' => null];
		$actual = [
			'find found' => $this->recorded(fn (ForeignIdentifierMapper $mapper): mixed => $mapper->findByIssuerAndValue('zgw:gemeente-x', '1001')?->getObjectUuid(), [[$row]]),
			'find missing' => $this->recorded(fn (ForeignIdentifierMapper $mapper): mixed => $mapper->findByIssuerAndValue('stuf:belastingen', '1001'), [[]]),
			'for object' => $this->recorded(
				fn (ForeignIdentifierMapper $mapper): mixed => array_map(static fn (ForeignIdentifier $found): ?string => $found->getValue(), $mapper->findForObject('zaak-1')),
				[[$row, ['id' => 6, 'object_uuid' => 'zaak-1', 'issuer' => 'openregister', 'value' => '2026-0042', 'created' => null]]]
			),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	private function recorded(callable $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return ['result' => $call(new ForeignIdentifierMapper($db)), 'queries' => $this->queryLog];
	}//end recorded()
}//end class
