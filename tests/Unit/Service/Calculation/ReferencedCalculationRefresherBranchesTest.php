<?php

/**
 * Branch tests for {@see \OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher}:
 * the early returns, the dependency filters, the lookup and save failures,
 * the sync limit and the recursion depth guard.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ReferencedCalculationRefresherBranchesTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ReferencedCalculationRefresherBranchesTest extends TestCase {
	private RegisterMapperInterface&MockObject $registers;
	private SchemaMapperInterface&MockObject $schemas;
	private MagicMapperInterface&MockObject $rows;
	private ObjectOperationsInterface&MockObject $objects;
	private LoggerInterface&MockObject $logger;
	/** @var array<string, Schema|\Throwable> */
	private array $schemaById = [];
	private int $schemaFinds = 0;

	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->rows = $this->createMock(MagicMapperInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$register = new Register();
		$register->setId(7);
		$register->setSlug('zaken');
		$register->setSchemas([1, 2, 99]);
		$this->registers->method('find')->willReturn($register);

		$this->schemaById = ['1' => $this->caseTypeSchema(), '2' => $this->caseSchema(), '99' => new RuntimeException('deleted schema')];
		$this->schemas->method('find')->willReturnCallback(function (string|int $id): Schema {
			$this->schemaFinds++;
			$found = $this->schemaById[(string) $id];
			if ($found instanceof \Throwable) {
				throw $found;
			}
			return $found;
		});
	}

	private function refresher(): ReferencedCalculationRefresher {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectOperationsInterface::class)->willReturn($this->objects);

		return new ReferencedCalculationRefresher(
			new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registers, schemas: $this->schemas, tables: $this->rows),
			$container,
			$this->logger
		);
	}

	private function caseTypeSchema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('caseType');
		$schema->setUuid('ct-uuid');
		return $schema;
	}

	/**
	 * @param array<string, mixed>|null $references  The x-openregister-references, or null for the default.
	 * @param array<string, mixed>|null $calculation The calculation spec, or null for the default.
	 */
	private function caseSchema(?array $references = null, ?array $calculation = null): Schema {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('case');
		$schema->setProperties(['caseType' => ['type' => 'string']]);
		$schema->setConfiguration([
			'x-openregister-references' => $references ?? ['caseType' => ['schema' => 'caseType', 'mode' => 'relatedObject', 'field' => 'caseType']],
			'x-openregister-calculations' => ['flows' => $calculation ?? ['materialise' => true, 'expression' => ['prop' => '@ref.caseType.startableFlows']]],
		]);
		return $schema;
	}

	private function caseType(array $data, string $uuid = 'type-1'): ObjectEntity {
		$type = new ObjectEntity();
		$type->setUuid($uuid);
		$type->setRegister('7');
		$type->setSchema('1');
		$type->setObject($data);
		return $type;
	}

	private function referrer(string $uuid): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid($uuid);
		$case->setRegister('7');
		$case->setSchema('2');
		$case->setObject(['caseType' => 'type-1']);
		return $case;
	}

	public function testAnObjectWithoutUuidRefreshesNothing(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertSame(0, $this->refresher()->refresh($this->caseType(['startableFlows' => [1]], ''), null));
	}

	public function testAnUnresolvableSchemaOrRegisterRefreshesNothing(): void {
		$this->schemaById['1'] = new RuntimeException('gone');
		$this->rows->expects($this->never())->method('findAllInRegisterSchemaTable');

		$this->assertSame(0, $this->refresher()->refresh($this->caseType(['startableFlows' => [1]]), null));
	}

	public function testANewObjectCountsEveryFieldAsChangedAndSkipsBrokenRegisterSchemas(): void {
		$this->rows->expects($this->once())->method('findAllInRegisterSchemaTable')
			->with($this->isInstanceOf(Register::class), $this->isInstanceOf(Schema::class), ReferencedCalculationRefresher::SYNC_LIMIT, null, ['caseType' => 'type-1'])
			->willReturn([$this->referrer('case-1'), $this->referrer('case-2')]);
		$this->objects->expects($this->exactly(2))->method('saveObject');

		$this->assertSame(2, $this->refresher()->refresh($this->caseType(['startableFlows' => [1]]), null));
	}

	public function testDependentsAreLookedUpOncePerRegisterAndTarget(): void {
		$this->rows->method('findAllInRegisterSchemaTable')->willReturn([]);
		$refresher = $this->refresher();

		$refresher->refresh($this->caseType(['startableFlows' => [1]]), null);
		$afterFirst = $this->schemaFinds;
		$refresher->refresh($this->caseType(['startableFlows' => [2]]), null);

		// The second refresh only re-finds the updated object's own schema.
		$this->assertSame($afterFirst + 1, $this->schemaFinds);
	}

	public function testAFailedReferrerLookupIsLoggedAndSavesNothing(): void {
		$this->rows->method('findAllInRegisterSchemaTable')->willThrowException(new RuntimeException('table missing'));
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('warning')->with(
			'[ReferencedCalculationRefresher] referrers could not be read',
			['schema' => 'case', 'reason' => 'table missing']
		);

		$this->assertSame(0, $this->refresher()->refresh($this->caseType(['startableFlows' => [1]]), $this->caseType([])));
	}

	public function testAFailedReSaveIsLoggedAndNotCounted(): void {
		$this->rows->method('findAllInRegisterSchemaTable')->willReturn([$this->referrer('bad'), $this->referrer('good')]);
		$this->objects->method('saveObject')->willReturnCallback(
			static function (mixed ...$args): ObjectEntity {
				if ($args[4] === 'bad') {
					throw new RuntimeException('locked');
				}
				return new ObjectEntity();
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[ReferencedCalculationRefresher] re-save failed', ['uuid' => 'bad', 'reason' => 'locked']);

		$this->assertSame(1, $this->refresher()->refresh($this->caseType(['startableFlows' => [1]]), null));
	}

	public function testReachingTheSyncLimitWarnsAndStillSavesTheBatch(): void {
		$batch = [];
		for ($i = 0; $i < ReferencedCalculationRefresher::SYNC_LIMIT; $i++) {
			$batch[] = $this->referrer('case-'.$i);
		}
		$this->rows->method('findAllInRegisterSchemaTable')->willReturn($batch);
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('more objects point at type-1 than one save refreshes'),
			['register' => 'zaken', 'schema' => 'case']
		);

		$this->assertSame(ReferencedCalculationRefresher::SYNC_LIMIT, $this->refresher()->refresh($this->caseType(['startableFlows' => [1]]), null));
	}

	public function testNestedRefreshesStopAtTheDepthLimit(): void {
		$refresher = $this->refresher();
		$depthsReached = [];
		$this->rows->method('findAllInRegisterSchemaTable')->willReturn([$this->referrer('case-1')]);
		$this->objects->method('saveObject')->willReturnCallback(
			function () use ($refresher, &$depthsReached): ObjectEntity {
				// A save that changes a read field again triggers the next level.
				$depthsReached[] = $refresher->refresh($this->caseType(['startableFlows' => [count($depthsReached)]]), null);
				return new ObjectEntity();
			}
		);

		$this->assertSame(1, $refresher->refresh($this->caseType(['startableFlows' => [9]]), null));
		// Level 2 saves once (its nested call returns 0 at the limit), level 3 is refused.
		$this->assertSame([0, 1], $depthsReached);
	}

	public function testDependenciesIgnoreSchemasWithoutReferences(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-references' => 'not-a-list']);

		$this->assertSame([], $this->refresher()->dependencies($schema, $this->caseTypeSchema()));
	}

	public function testDependenciesMatchTheTargetBySlugIdOrUuid(): void {
		$refresher = $this->refresher();
		$target = $this->caseTypeSchema();

		foreach (['CASETYPE', '1', 'ct-uuid'] as $name) {
			$schema = $this->caseSchema(references: ['caseType' => ['schema' => $name, 'mode' => 'relatedObject', 'field' => 'caseType']]);
			$this->assertSame(['caseType' => ['startableFlows']], $refresher->dependencies($schema, $target), $name);
		}
	}

	public function testDependenciesSkipReferencesThatDoNotPointAtTheTarget(): void {
		$refresher = $this->refresher();
		$target = $this->caseTypeSchema();
		$refusals = [
			'other schema' => ['caseType' => ['schema' => 'person', 'mode' => 'relatedObject', 'field' => 'caseType']],
			'wrong mode' => ['caseType' => ['schema' => 'caseType', 'mode' => 'inverse', 'field' => 'caseType']],
			'no field' => ['caseType' => ['schema' => 'caseType', 'mode' => 'relatedObject', 'field' => 5]],
			'spec not a list' => ['caseType' => 'caseType'],
		];

		foreach ($refusals as $label => $references) {
			$this->assertSame([], $refresher->dependencies($this->caseSchema(references: $references), $target), $label);
		}
	}

	public function testDependenciesOnlyReadMaterialisedEnabledCalculations(): void {
		$refresher = $this->refresher();
		$target = $this->caseTypeSchema();
		$expression = ['prop' => '@ref.caseType.startableFlows'];

		$this->assertSame([], $refresher->dependencies($this->caseSchema(calculation: ['expression' => $expression]), $target));
		$this->assertSame([], $refresher->dependencies($this->caseSchema(calculation: ['materialise' => true, 'enabled' => false, 'expression' => $expression]), $target));
		$this->assertSame([], $refresher->dependencies($this->caseSchema(calculation: ['materialise' => true, 'expression' => ['prop' => '@ref.other.x']]), $target));
	}

	public function testDependenciesToleratesNonListAnnotationAndProperties(): void {
		$schema = new Schema();
		$schema->setConfiguration([
			'x-openregister-references' => ['caseType' => ['schema' => 'caseType', 'mode' => 'relatedObject', 'field' => 'caseType']],
			'x-openregister-calculations' => 'broken',
		]);

		$this->assertSame([], $this->refresher()->dependencies($schema, $this->caseTypeSchema()));
	}

	public function testOnlyDependentsReadingAChangedFieldAreReSaved(): void {
		$second = $this->caseSchema(
			references: ['caseType' => ['schema' => 'caseType', 'mode' => 'relatedObject', 'field' => 'kind']],
			calculation: ['materialise' => true, 'expression' => ['prop' => '@ref.caseType.title']]
		);
		$second->setId(3);
		$second->setSlug('appeal');
		$this->schemaById['3'] = $second;
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$register = new Register();
		$register->setId(8);
		$register->setSchemas([2, 3]);
		$this->registers->method('find')->willReturn($register);
		$this->rows->expects($this->once())->method('findAllInRegisterSchemaTable')
			->with($register, $this->callback(static fn (Schema $s): bool => $s->getSlug() === 'case'), ReferencedCalculationRefresher::SYNC_LIMIT, null, ['caseType' => 'type-1'])
			->willReturn([]);

		$this->refresher()->refresh($this->caseType(['title' => 'same', 'startableFlows' => [1]]), $this->caseType(['title' => 'same']));
	}
}
