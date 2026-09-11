<?php

/**
 * A broken lifecycle `condition` refuses the schema save; other lifecycle
 * errors stay advisory.
 *
 * SchemaMapper treats lifecycle validation errors as advisory: it logs them
 * and stores the schema, so a register import carrying a partial or
 * different-dialect lifecycle block does not break. A transition `condition`
 * is the exception. It is a gate, and a gate stored broken either refuses
 * every transition it covers or, on a graph block, gates nothing while its
 * author believes it holds.
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
 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
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
 * Save-time policy for lifecycle conditions.
 */
class SchemaMapperLifecycleConditionTest extends TestCase {

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
				'beslissen' => ['from' => ['open'], 'to' => 'besloten'] + $extra,
			],
		];
	}//end lifecycle()

	/**
	 * @return void
	 */
	public function testAScalarConditionRefusesTheSave(): void {
		// 🔴 The fail-open case. Stored, this string would evaluate as a truthy
		// literal; the listener now refuses it too, but the author should hear
		// about it at save time rather than from a refused transition later.
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^Invalid .*lifecycle-condition-malformed/');

		$this->validate($this->lifecycle(['condition' => "@self.motivering == 'ja'"]));
	}//end testAScalarConditionRefusesTheSave()

	/**
	 * @return void
	 */
	public function testAnUnevaluableConditionRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/lifecycle-condition-malformed/');

		$this->validate($this->lifecycle(['condition' => ['nosuchoperator' => [1]]]));
	}//end testAnUnevaluableConditionRefusesTheSave()

	/**
	 * @return void
	 */
	public function testAGraphConditionRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/lifecycle-condition-graph-unsupported/');

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
					'condition' => ['!!' => ['var' => 'object.motivering']],
				],
			]
		);
	}//end testAGraphConditionRefusesTheSave()

	/**
	 * @return void
	 */
	public function testTheRefusalLeadsWithInvalidSoTheControllerAnswers400(): void {
		// SchemasController maps a save exception to 400 by matching the word
		// "Invalid" in its message. Without it an unevaluable-operator error
		// (whose own text says "not a valid") would surface as a 500.
		try {
			$this->validate($this->lifecycle(['condition' => ['nosuchoperator' => [1]]]));
			$this->fail('A malformed condition must refuse the save.');
		} catch (Exception $e) {
			$this->assertStringStartsWith(prefix: 'Invalid', string: $e->getMessage());
		}
	}//end testTheRefusalLeadsWithInvalidSoTheControllerAnswers400()

	/**
	 * @return void
	 */
	public function testOtherLifecycleErrorsStayAdvisory(): void {
		// The 08-28 advisory policy is preserved for everything that is not a
		// condition: a `to` outside the enum warns and the schema is saved.
		$this->logger->expects($this->once())->method('warning');

		$this->validate(
			[
				'field' => 'status',
				'initial' => 'open',
				'transitions' => ['beslissen' => ['from' => ['open'], 'to' => 'nergens']],
			]
		);
	}//end testOtherLifecycleErrorsStayAdvisory()

	/**
	 * @return void
	 */
	public function testAMalformedMessageStaysAdvisory(): void {
		// A bad `message` only degrades refusal text to the engine fallback; it
		// gates nothing, so it does not earn the refusal a broken condition does.
		$this->logger->expects($this->once())->method('warning');

		$this->validate(
			$this->lifecycle(
				[
					'condition' => ['!!' => ['var' => 'object.motivering']],
					'message' => 42,
				]
			)
		);
	}//end testAMalformedMessageStaysAdvisory()

	/**
	 * @return void
	 */
	public function testTheAdvisoryWarningNoLongerClaimsTheLifecycleIsOff(): void {
		// The previous text said the annotation "was ignored (no status workflow
		// applied)". It was not: it is stored and the listener acts on it. An
		// operator reading that log would have looked in the wrong place.
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->logicalNot($this->stringContains('ignored')));

		$this->validate(
			[
				'field' => 'status',
				'initial' => 'open',
				'transitions' => ['beslissen' => ['from' => ['open'], 'to' => 'nergens']],
			]
		);
	}//end testTheAdvisoryWarningNoLongerClaimsTheLifecycleIsOff()

	/**
	 * @return void
	 */
	public function testAValidConditionSavesSilently(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->validate(
			$this->lifecycle(
				[
					'condition' => ['!!' => ['var' => 'object.motivering']],
					'message' => ['nl' => 'Motivering vereist.', 'en' => 'Motivation required.'],
				]
			)
		);
	}//end testAValidConditionSavesSilently()
}//end class
