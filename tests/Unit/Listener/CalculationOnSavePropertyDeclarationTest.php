<?php

/**
 * OpenRegister CalculationOnSavePropertyDeclarationTest
 *
 * A calculation a property form forwarded materialises through the same
 * save-time listener a hand-written annotation does: the value is written
 * from its inputs on create, it recomputes when an input changes, and a value
 * the client sent for a computed property does not survive the save.
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
 * An authored calculation is evaluated on write.
 */
class CalculationOnSavePropertyDeclarationTest extends TestCase {

	/** @var SchemaMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $schemaMapper;

	/**
	 * @var CalculationOnSaveListener The save-time listener under test.
	 */
	private CalculationOnSaveListener $listener;

	/**
	 * Wire the listener with a real evaluator and mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$payloadBuilder = $this->createMock(originalClassName: CalculationPayloadBuilder::class);

		$payloadBuilder->method('build')
			->willReturnCallback(static fn (ObjectEntity $o): array => $o->getObject());
		$payloadBuilder->method('stripSyntheticKeys')
			->willReturnCallback(static fn (array $d): array => $d);

		$register = new Register();
		$register->setId(16);
		$registerMapper->method('find')->willReturn($register);

		$this->listener = new CalculationOnSaveListener(
			$this->schemaMapper,
			$registerMapper,
			new CalculationEvaluator(new PlaceholderResolver($userSession)),
			$payloadBuilder,
			$this->createMock(originalClassName: SequenceService::class),
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A schema whose `uiterlijkeDatum` property carries a forwarded calculation.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithForwardedCalculation(): Schema {
		$schema = new Schema();
		$schema->setId(26);
		$schema->setProperties(
			[
				'ontvangstdatum' => ['type' => 'string', 'format' => 'date'],
				'uiterlijkeDatum' => [
					'type' => 'string',
					'format' => 'date',
					'calculation' => [
						'type' => 'date',
						'expression' => [
							'dateAdd' => [
								'date' => ['prop' => 'ontvangstdatum'],
								'amount' => 6,
								'unit' => 'weeks',
							],
						],
					],
				],
			]
		);
		$schema->setConfiguration([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end schemaWithForwardedCalculation()

	/**
	 * Build an object carrying the given business data.
	 *
	 * @param array<string, mixed> $data The stored object data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function objectWith(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('2a5c1a1e-5d1a-4d2f-9c6a-1f0f3c4b7e21');
		$object->setRegister('16');
		$object->setSchema('26');
		$object->setObject($data);

		return $object;
	}//end objectWith()

	/**
	 * A computed property is written from its inputs.
	 *
	 * @return void
	 */
	public function testAComputedPropertyIsWrittenFromItsInputs(): void {
		$this->schemaWithForwardedCalculation();
		$object = $this->objectWith(data: ['ontvangstdatum' => '2026-01-01']);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame(expected: '2026-02-12', actual: $object->getObject()['uiterlijkeDatum']);

	}//end testAComputedPropertyIsWrittenFromItsInputs()

	/**
	 * A change to an input recomputes the derived value.
	 *
	 * @return void
	 */
	public function testAChangeToAnInputRecomputes(): void {
		$this->schemaWithForwardedCalculation();
		$object = $this->objectWith(data:
			['ontvangstdatum' => '2026-03-02', 'uiterlijkeDatum' => '2026-02-12']
		);

		$this->listener->handle(new ObjectUpdatingEvent($object));

		$this->assertSame(expected: '2026-04-13', actual: $object->getObject()['uiterlijkeDatum']);

	}//end testAChangeToAnInputRecomputes()

	/**
	 * A value the client wrote into a computed property does not survive.
	 *
	 * The computed-fields contract is that a save-time computed value is
	 * overwritten by the computed result rather than refused, so the evidence
	 * that a direct write does not stick is the stored value afterwards.
	 *
	 * @return void
	 */
	public function testAValueWrittenDirectlyIntoAComputedPropertyDoesNotSurvive(): void {
		$this->schemaWithForwardedCalculation();
		$object = $this->objectWith(data:
			['ontvangstdatum' => '2026-01-01', 'uiterlijkeDatum' => '2099-12-31']
		);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertNotSame(expected: '2099-12-31', actual: $object->getObject()['uiterlijkeDatum']);
		$this->assertSame(expected: '2026-02-12', actual: $object->getObject()['uiterlijkeDatum']);

	}//end testAValueWrittenDirectlyIntoAComputedPropertyDoesNotSurvive()

	/**
	 * A schema declaring nothing is left entirely alone.
	 *
	 * @return void
	 */
	public function testASchemaWithNoDeclarationsIsUntouched(): void {
		$schema = new Schema();
		$schema->setId(26);
		$schema->setProperties(['naam' => ['type' => 'string']]);
		$schema->setConfiguration([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$object = $this->objectWith(data: ['naam' => 'Anna']);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame(expected: 'Anna', actual: $object->getObject()['naam']);
		$this->assertArrayNotHasKey(key: 'uiterlijkeDatum', array: $object->getObject());

	}//end testASchemaWithNoDeclarationsIsUntouched()
}//end class
