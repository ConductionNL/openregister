<?php

/**
 * The four automatic-transition errors refuse the schema save.
 *
 * SchemaMapper treats most lifecycle validation errors as advisory: it logs
 * them and stores the schema, so a register import carrying a partial or
 * different-dialect lifecycle block does not break. A transition `condition`
 * was the exception; `autoWhen` and `executionMode` join it. A stored scalar
 * `autoWhen` is worse than a stored broken condition, because it evaluates as
 * a truthy literal and fires the transition on every write from its `from`
 * state, and the other three describe a move that can never happen at all.
 *
 * These tests drive the private `validateLifecycleAnnotation()` directly,
 * because the public insert/update paths are DB-bound. What they pin down is
 * the split: which errors refuse, which merely warn.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Save-time policy for automatic transitions.
 */
class SchemaMapperAutoTransitionValidationTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	private SchemaMapper $mapper;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->mapper = new SchemaMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->logger
		);
	}//end setUp()

	/**
	 * Run the private validator against a schema carrying the given lifecycle.
	 *
	 * @param array<string, mixed> $lifecycle The x-openregister-lifecycle block.
	 *
	 * @return void
	 */
	private function validate(array $lifecycle): void {
		$schema = new Schema();
		$schema->setSlug('bezwaar');
		$schema->setProperties(
			[
				'status' => ['type' => 'string', 'enum' => ['open', 'besloten']],
				'motivering' => ['type' => 'string'],
			]
		);
		$schema->setConfiguration(['x-openregister-lifecycle' => $lifecycle]);

		$method = new ReflectionMethod(SchemaMapper::class, 'validateLifecycleAnnotation');
		$method->invoke($this->mapper, $schema);
	}//end validate()

	/**
	 * A static lifecycle whose `beslissen` transition carries the given extras.
	 *
	 * @param array<string, mixed> $extra Extra keys on the transition.
	 *
	 * @return array<string, mixed>
	 */
	private function lifecycle(array $extra): array {
		return [
			'field' => 'status',
			'initial' => 'open',
			'transitions' => [
				'beslissen' => (['from' => ['open'], 'to' => 'besloten'] + $extra),
			],
		];
	}//end lifecycle()

	/**
	 * @return void
	 */
	public function testAScalarAutoWhenRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^Invalid .*lifecycle-autowhen-malformed/');

		$this->validate($this->lifecycle(['autoWhen' => true]));
	}//end testAScalarAutoWhenRefusesTheSave()

	/**
	 * @return void
	 */
	public function testAnUnknownExecutionModeRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^Invalid .*lifecycle-execution-mode-malformed/');

		$this->validate(
			$this->lifecycle(
				['autoWhen' => ['!!' => ['var' => 'object.motivering']], 'executionMode' => 'background']
			)
		);
	}//end testAnUnknownExecutionModeRefusesTheSave()

	/**
	 * @return void
	 */
	public function testARequiredInputBesideAutoWhenRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^Invalid .*lifecycle-autowhen-requires-input/');

		$this->validate(
			$this->lifecycle(
				[
					'autoWhen' => ['!!' => ['var' => 'object.motivering']],
					'inputs' => [['field' => 'motivering', 'required' => true]],
				]
			)
		);
	}//end testARequiredInputBesideAutoWhenRefusesTheSave()

	/**
	 * @return void
	 */
	public function testAGraphAutoWhenRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^Invalid .*lifecycle-autowhen-graph-unsupported/');

		$this->validate(
			[
				'field' => 'status',
				'graph' => [
					'schema' => 'fase',
					'parentField' => 'zaaktype',
					'parentFrom' => 'zaaktype',
					'orderField' => 'volgorde',
					'finalField' => 'eindfase',
					'allowedMoves' => 'forward',
					'autoWhen' => ['!!' => ['var' => 'object.motivering']],
				],
			]
		);
	}//end testAGraphAutoWhenRefusesTheSave()

	/**
	 * @return void
	 */
	public function testAMalformedMessageBesideAValidAutoWhenOnlyWarns(): void {
		// The advisory bucket is unchanged: a broken `message` is still stored
		// and logged, even when the same transition carries an `autoWhen`.
		$this->logger->expects($this->once())->method('warning');

		$this->validate(
			$this->lifecycle(
				[
					'autoWhen' => ['!!' => ['var' => 'object.motivering']],
					'message' => 42,
				]
			)
		);
	}//end testAMalformedMessageBesideAValidAutoWhenOnlyWarns()

	/**
	 * @return void
	 */
	public function testTheRefusalLeadsWithInvalidSoTheControllerAnswers400(): void {
		// SchemasController maps a save exception to 400 by matching the word
		// "Invalid" in its message. The text is generalised from "condition" to
		// "declaration" so it reads correctly for both rules.
		try {
			$this->validate($this->lifecycle(['autoWhen' => 'nope']));
			$this->fail('A malformed autoWhen must refuse the save.');
		} catch (Exception $e) {
			$this->assertStringStartsWith('Invalid', $e->getMessage());
		}
	}//end testTheRefusalLeadsWithInvalidSoTheControllerAnswers400()
}//end class
