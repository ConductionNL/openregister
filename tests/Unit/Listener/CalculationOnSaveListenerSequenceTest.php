<?php

/**
 * OpenRegister CalculationOnSaveListenerSequenceTest
 *
 * Regression cover for openregister#3075: a materialised calculation that
 * contains a `sequence` node must NOT be recomputed on the update path, where
 * no SequenceContext exists. Re-evaluating there resolves the node to null,
 * `concat` renders it as an empty string, and the number assigned at create is
 * silently overwritten with a truncated stub ("2026-0013" -> "2026-").
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\CalculationOnSaveListener;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\SequenceService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sequence-bearing calculations survive an update.
 */
class CalculationOnSaveListenerSequenceTest extends TestCase {

	/**
	 * The dossiq `case.identifier` calculation, verbatim: the year of
	 * `startDate`, a dash, and a yearly running number.
	 *
	 * @var array<string, mixed>
	 */
	private const IDENTIFIER_CALC = [
		'type' => 'string',
		'materialise' => true,
		'expression' => [
			'concat' => [
				['year' => ['prop' => 'startDate']],
				'-',
				['sequence' => ['scope' => 'yearly', 'pad' => 4]],
			],
		],
	];

	/**
	 * A materialised calculation with no `sequence` node — it must keep
	 * recomputing on update, so the guard is not over-broad.
	 *
	 * @var array<string, mixed>
	 */
	private const SLUG_CALC = [
		'type' => 'string',
		'materialise' => true,
		'expression' => ['concat' => [['prop' => 'title'], '!']],
	];

	/** @var SchemaMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $schemaMapper;

	/** @var RegisterMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $registerMapper;

	/** @var CalculationPayloadBuilder&\PHPUnit\Framework\MockObject\MockObject */
	private $payloadBuilder;

	/** @var SequenceService&\PHPUnit\Framework\MockObject\MockObject */
	private $sequences;

	private CalculationOnSaveListener $listener;

	/**
	 * Wire the listener with a REAL evaluator and mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->payloadBuilder = $this->createMock(CalculationPayloadBuilder::class);
		$this->sequences = $this->createMock(SequenceService::class);

		// The payload builder only injects/strips the synthetic @self/@ref/
		// @aggregate keys; none of the calculations under test use them.
		$this->payloadBuilder->method('build')
			->willReturnCallback(static fn (ObjectEntity $o): array => $o->getObject());
		$this->payloadBuilder->method('stripSyntheticKeys')
			->willReturnCallback(static fn (array $d): array => $d);

		$register = new Register();
		$register->setId(16);
		$this->registerMapper->method('find')->willReturn($register);

		$this->listener = new CalculationOnSaveListener(
			$this->schemaMapper,
			$this->registerMapper,
			new CalculationEvaluator(new PlaceholderResolver($userSession)),
			$this->payloadBuilder,
			$this->sequences,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Build a schema declaring the given calculations.
	 *
	 * @param array<string, mixed> $calcs The calculations block.
	 *
	 * @return Schema
	 */
	private function schemaWith(array $calcs): Schema {
		$schema = new Schema();
		$schema->setId(26);
		$schema->setConfiguration(['x-openregister-calculations' => $calcs]);
		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end schemaWith()

	/**
	 * Build an object carrying the given business data.
	 *
	 * @param array<string, mixed> $data The stored object data.
	 *
	 * @return ObjectEntity
	 */
	private function objectWith(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('ce4b0570-3bf4-4133-a08b-3a9b03b2cc20');
		$object->setRegister('16');
		$object->setSchema('26');
		$object->setObject($data);

		return $object;
	}//end objectWith()

	/**
	 * openregister#3075 — on UPDATE the assigned running number survives.
	 *
	 * Before the fix the evaluator resolved the context-less `sequence` node to
	 * null, `concat` rendered "2026-", and the listener wrote that over the
	 * stored "2026-0013". Any app whose identifier is readOnly then had every
	 * subsequent edit rejected with "Cannot modify readOnly property".
	 *
	 * @return void
	 */
	public function testUpdateDoesNotOverwriteAnAssignedSequenceIdentifier(): void {
		$this->schemaWith(['identifier' => self::IDENTIFIER_CALC]);
		$this->sequences->expects($this->never())->method('reserveNext');

		$object = $this->objectWith(
			['title' => 'Editable case', 'startDate' => '2026-08-29', 'identifier' => '2026-0013']
		);

		$this->listener->handle(new ObjectUpdatingEvent($object));

		$this->assertSame('2026-0013', $object->getObject()['identifier']);

	}//end testUpdateDoesNotOverwriteAnAssignedSequenceIdentifier()

	/**
	 * The guard is scoped to sequence-bearing expressions: every other
	 * materialised calculation still recomputes on update.
	 *
	 * @return void
	 */
	public function testUpdateStillRecomputesCalculationsWithoutASequence(): void {
		$this->schemaWith(
			['identifier' => self::IDENTIFIER_CALC, 'slug' => self::SLUG_CALC]
		);

		$object = $this->objectWith(
			[
				'title' => 'Edited case',
				'startDate' => '2026-08-29',
				'identifier' => '2026-0013',
				'slug' => 'stale',
			]
		);

		$this->listener->handle(new ObjectUpdatingEvent($object));

		$data = $object->getObject();
		$this->assertSame('Edited case!', $data['slug'], 'non-sequence calculation still recomputes');
		$this->assertSame('2026-0013', $data['identifier'], 'sequence-bearing calculation is left alone');

	}//end testUpdateStillRecomputesCalculationsWithoutASequence()

	/**
	 * CREATE is unchanged: the sequence reserves exactly one number and the
	 * identifier is materialised from it.
	 *
	 * @return void
	 */
	public function testCreateStillReservesAndMaterialisesTheIdentifier(): void {
		$this->schemaWith(['identifier' => self::IDENTIFIER_CALC]);
		$this->sequences->expects($this->once())
			->method('reserveNext')
			->with(16, 26, date('Y'))
			->willReturn(13);

		$object = $this->objectWith(['title' => 'New case', 'startDate' => '2026-08-29']);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame('2026-0013', $object->getObject()['identifier']);

	}//end testCreateStillReservesAndMaterialisesTheIdentifier()

}//end class
