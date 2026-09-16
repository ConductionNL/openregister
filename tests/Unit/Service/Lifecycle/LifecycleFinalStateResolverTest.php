<?php

declare(strict_types=1);

/**
 * LifecycleFinalStateResolver tests.
 *
 * Pins the dossiq-shaped case: `status` is a `$ref` to a `statusType` row, the
 * lifecycle value is that row's uuid, and `isFinal` on the row is what says the
 * case has ended. The static list of state strings cannot express that, and the
 * failure it produced was silent, so the tests that matter here are the ones
 * where nothing resolves.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
 */

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LifecycleFinalStateResolver.
 */
class LifecycleFinalStateResolverTest extends TestCase {

	private MagicMapper&MockObject $objects;
	private LoggerInterface&MockObject $logger;
	private LifecycleFinalStateResolver $resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			// onlyMethods, never addMethods: a double that invents a method the
			// real class lacks can only ever pass.
			->onlyMethods(['findAcrossAllSources'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->resolver = new LifecycleFinalStateResolver($this->objects, $this->logger);
	}

	/**
	 * A statusType row, as the lookup hands it back.
	 *
	 * @param array<string, mixed> $data       The row's data.
	 * @param string               $schemaSlug The schema the row belongs to.
	 *
	 * @return array{object: ObjectEntity, register: null, schema: Schema} The lookup result.
	 */
	private function row(array $data, string $schemaSlug = 'statusType'): array {
		$object = new ObjectEntity();
		$object->setUuid('9f1c0a2e-0c3a-4f62-9a9d-6a0f1d2b3c44');
		$object->setObject($data);

		// A real entity, not a double: `getSlug()` and friends are Entity magic
		// methods, so a double could only ADD them, and a method a double adds
		// can never disagree with the real class.
		$schema = new Schema();
		$schema->setId(7);
		$schema->setUuid('schema-uuid');
		$schema->setSlug($schemaSlug);
		$schema->setTitle('Status Type');

		return ['object' => $object, 'register' => null, 'schema' => $schema];
	}

	/**
	 * The dossiq declaration.
	 *
	 * @return array{from: string, field: string} The `final` block.
	 */
	private function declaration(): array {
		return ['from' => 'statusType', 'field' => 'isFinal'];
	}

	public function testTheReferenceFormIsToldApartFromAListOfStates(): void {
		$this->assertTrue($this->resolver->isReferenceForm(['from' => 'statusType', 'field' => 'isFinal']));
		$this->assertTrue($this->resolver->isReferenceForm(['from' => 'statusType']));
		$this->assertFalse($this->resolver->isReferenceForm(['afgehandeld', 'ingetrokken']));
		$this->assertFalse($this->resolver->isReferenceForm([]));
		$this->assertFalse($this->resolver->isReferenceForm('afgehandeld'));
		$this->assertFalse($this->resolver->isReferenceForm(null));
	}

	public function testARowThatSaysItIsFinalEndsTheLifecycle(): void {
		$this->objects->method('findAcrossAllSources')
			->willReturn($this->row(['name' => 'Afgehandeld', 'isFinal' => true]));

		$this->assertTrue(
			$this->resolver->isFinalByReference(
				declaration: $this->declaration(),
				state: '9f1c0a2e-0c3a-4f62-9a9d-6a0f1d2b3c44'
			)
		);
	}

	public function testARowThatSaysItIsNotFinalDoesNot(): void {
		$this->objects->method('findAcrossAllSources')
			->willReturn($this->row(['name' => 'In behandeling', 'isFinal' => false]));

		$this->assertFalse(
			$this->resolver->isFinalByReference(declaration: $this->declaration(), state: 'uuid-1')
		);
	}

	/**
	 * JSON storage and form posts both flatten booleans, so the stored value is
	 * as often `"true"` or `1` as it is `true`. `"false"` is the one string that
	 * looks true to PHP and is not, which is why this is not a bare cast.
	 *
	 * @return void
	 */
	public function testAStoredBooleanIsReadInEverySpellingItArrivesIn(): void {
		foreach ([[true, true], ['true', true], ['1', true], [1, true], ['ja', true]] as [$stored, $expected]) {
			$resolver = new LifecycleFinalStateResolver(
				$this->mapperReturning($this->row(['isFinal' => $stored])),
				$this->logger
			);
			$this->assertSame(
				$expected,
				$resolver->isFinalByReference(declaration: $this->declaration(), state: 'uuid-' . var_export($stored, true))
			);
		}

		foreach ([false, 'false', '0', 0, '', 'nee', null] as $stored) {
			$resolver = new LifecycleFinalStateResolver(
				$this->mapperReturning($this->row(['isFinal' => $stored])),
				$this->logger
			);
			$this->assertFalse(
				$resolver->isFinalByReference(declaration: $this->declaration(), state: 'uuid-' . var_export($stored, true)),
				var_export($stored, true) . ' must not end a lifecycle'
			);
		}
	}

	/**
	 * A mapper double answering one lookup result.
	 *
	 * @param array<string, mixed> $result What the lookup returns.
	 *
	 * @return MagicMapper&MockObject The double.
	 */
	private function mapperReturning(array $result): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAcrossAllSources'])
			->getMock();
		$mapper->method('findAcrossAllSources')->willReturn($result);

		return $mapper;
	}

	/**
	 * Unresolvable is not terminal, and it is said out loud.
	 *
	 * Guessing yes would nominate a live dossier for destruction; guessing no
	 * in silence is the failure this class exists to end.
	 *
	 * @return void
	 */
	public function testAStateThatResolvesToNoRowIsReportedAndIsNotTerminal(): void {
		$this->objects->method('findAcrossAllSources')
			->willThrowException(new DoesNotExistException('no such object'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('resolves to no statusType row'));

		$this->assertFalse(
			$this->resolver->isFinalByReference(declaration: $this->declaration(), state: 'uuid-1')
		);
	}

	/**
	 * The lookup is by identifier across every magic table, so the schema has
	 * to be checked: without it a uuid that happens to name a row in another
	 * schema could answer for a status.
	 *
	 * @return void
	 */
	public function testARowFromAnotherSchemaCannotAnswerForAStatus(): void {
		$this->objects->method('findAcrossAllSources')
			->willReturn($this->row(['isFinal' => true], 'caseType'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('outside the declared schema'));

		$this->assertFalse(
			$this->resolver->isFinalByReference(declaration: $this->declaration(), state: 'uuid-1')
		);
	}

	public function testARowWithoutTheNamedPropertyIsReported(): void {
		$this->objects->method('findAcrossAllSources')
			->willReturn($this->row(['name' => 'Afgehandeld']));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('declares no "isFinal" property'));

		$this->assertFalse(
			$this->resolver->isFinalByReference(declaration: $this->declaration(), state: 'uuid-1')
		);
	}

	public function testHalfADeclarationResolvesNothingAndReadsNoRow(): void {
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->assertFalse($this->resolver->isFinalByReference(declaration: ['from' => 'statusType'], state: 'uuid-1'));
		$this->assertFalse($this->resolver->isFinalByReference(declaration: ['field' => 'isFinal'], state: 'uuid-1'));
		$this->assertFalse($this->resolver->isFinalByReference(declaration: $this->declaration(), state: '  '));
	}

	/**
	 * A sweep asks the same question for every object in the same state, and
	 * the answer is a row that cannot change inside one request.
	 *
	 * @return void
	 */
	public function testTheSameStateIsReadOncePerRequest(): void {
		$this->objects->expects($this->once())
			->method('findAcrossAllSources')
			->willReturn($this->row(['isFinal' => true]));

		for ($i = 0; $i < 5; $i++) {
			$this->assertTrue(
				$this->resolver->isFinalByReference(declaration: $this->declaration(), state: 'uuid-1')
			);
		}
	}

	public function testTheSchemaMayBeNamedByUuidTitleOrId(): void {
		foreach (['schema-uuid', 'Status Type', '7', 'STATUSTYPE'] as $spelling) {
			$resolver = new LifecycleFinalStateResolver(
				$this->mapperReturning($this->row(['isFinal' => true])),
				$this->logger
			);
			$this->assertTrue(
				$resolver->isFinalByReference(declaration: ['from' => $spelling, 'field' => 'isFinal'], state: 'uuid-1'),
				$spelling . ' must name the same schema'
			);
		}
	}
}
