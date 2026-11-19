<?php

/**
 * SchemaDeclarationOrderTest: the order a schema save checks its declarations in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Exception\UniqueHintException;
use OCA\OpenRegister\Service\Calculation\CalculationDeclarationException;
use OCA\OpenRegister\Service\Consent\ConsentDeclarationException;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Tests\Unit\Support\ProcessDeclarationChecksDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Throwable;

/**
 * Pins the sequence, that every slot is filled exactly once, and which fault wins.
 */
final class SchemaDeclarationOrderTest extends TestCase {

	/**
	 * The slots in the order the checks ran before Processes contributed its own.
	 *
	 * @return void
	 */
	public function testTheSlotsRunInThisOrder(): void {
		$this->assertSame(
			expected: [
				'LIFECYCLE', 'DECLARED_ACTIONS', 'MDTO_MAPPING', 'AGGREGATIONS', 'CALCULATIONS', 'CONSENT', 'RELATIONS',
				'DEPENDENT_VALUES', 'REFERENCE_LOOPS', 'NAMED_CONDITIONS',
				'QUALITY', 'DEDUP', 'UNIQUE_HINT', 'IDENTITY', 'HINGE', 'SURVIVORSHIP', 'MERGE', 'PARTY', 'NOTIFICATIONS',
				'WIDGETS', 'ARCHIVAL', 'REFERENCE_CARD', 'SEARCH_RESULT', 'RETENTION',
				'HANDOFF', 'HANDOFF_CONTRACT', 'MCP', 'REGISTRY', 'EXTERNAL_LINKS', 'EXTENDING_FORM',
				'AUTHORIZATION', 'HIERARCHY', 'DEPARTMENT_MATRIX', 'REVEAL_AUDIT', 'CLASSIFICATION', 'CONFIDENTIALITY',
				'REVERSIBILITY', 'REVIEW',
			],
			actual: array_keys(SchemaDeclarationOrder::slots())
		);

		$positions = array_values(SchemaDeclarationOrder::slots());
		$sorted    = $positions;
		sort($sorted);
		$this->assertSame(expected: $sorted, actual: $positions);
	}//end testTheSlotsRunInThisOrder()

	/**
	 * The mapper's own checks and the contributed checks fill every slot exactly once.
	 *
	 * @return void
	 */
	public function testEverySlotIsFilledOnce(): void {
		$mapper = $this->mapper();
		$schema = new Schema();
		$own    = [];
		foreach (['behaviourSteps', 'dataSteps', 'exchangeSteps', 'accessSteps'] as $section) {
			$own = array_merge($own, array_keys((new ReflectionMethod(SchemaMapper::class, $section))->invoke($mapper, $schema)));
		}

		$event = new SchemaDeclarationChecksEvent();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		foreach (ProcessDeclarationChecksDispatcher::listeners(logger: new NullLogger(), container: $container) as $listener) {
			$listener->handle(event: $event);
		}
		$contributed = array_map(static fn ($check): int => $check->position(), $event->checks());

		$this->assertSame(expected: [], actual: array_intersect($own, $contributed), message: 'No slot is claimed twice');
		$filled = array_merge($own, $contributed);
		sort($filled);
		$this->assertSame(expected: array_values(SchemaDeclarationOrder::slots()), actual: $filled);
	}//end testEverySlotIsFilledOnce()

	/**
	 * A schema with several faults is refused for the first in slot order, whichever area checks it.
	 *
	 * @return array<string, array{array<string, mixed>, class-string}>
	 */
	public static function severalFaults(): array {
		return [
			'consent (Core) before dependent values (Processes)' => [
				[
					'a' => ['type' => 'string', 'x-openregister-consent' => 'nonsense'],
					'b' => ['type' => 'string', 'x-openregister-dependent-values' => ['on' => 'nope']],
				],
				ConsentDeclarationException::class,
			],
			'calculation (Processes) before consent (Core)' => [
				[
					'a' => ['type' => 'string', 'x-openregister-consent' => 'nonsense'],
					'b' => ['type' => 'number', 'calculation' => ['nope' => 1]],
				],
				CalculationDeclarationException::class,
			],
		];
	}//end severalFaults()

	/**
	 * The refusal a schema with several faults gets.
	 *
	 * @param array<string, mixed> $properties The faulty properties.
	 * @param class-string         $refusal    The refusal it gets.
	 *
	 * @return void
	 *
	 * @dataProvider severalFaults
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('severalFaults')]
	public function testTheFirstFaultInSlotOrderRefusesTheSave(array $properties, string $refusal): void {
		$schema = new Schema();
		$schema->setSlug('faults');
		$schema->setProperties($properties);

		$thrown = null;
		try {
			(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($this->mapper(), $schema);
		} catch (Throwable $e) {
			$thrown = $e;
		}

		$this->assertInstanceOf(expected: $refusal, actual: $thrown);
	}//end testTheFirstFaultInSlotOrderRefusesTheSave()

	/**
	 * Schema-level faults across the areas that contribute data checks, and the refusal each gets.
	 *
	 * @return array<string, array{array<string, mixed>, class-string, string}>
	 */
	public static function severalDeclarationFaults(): array {
		$card   = ['x-openregister-card' => ['titleProperty' => 'absent']];
		$result = ['x-openregister-search-result' => ['titleProperty' => 'absent']];
		return [
			'unique hint (Search) before reference card (Configuration)' => [
				['x-openregister-unique-hint' => ['absent']] + $card,
				UniqueHintException::class,
				'x-openregister-unique-hint',
			],
			'reference card (Configuration) before search result (Core)' => [
				$card + $result,
				Exception::class,
				'x-openregister-card',
			],
			'search result (Core) before retention (Compliance)' => [
				$result + ['x-openregister-retention' => ['inheritAppraisalFrom' => ['title']]],
				Exception::class,
				'x-openregister-search-result',
			],
			'retention (Compliance) alone' => [
				['x-openregister-retention' => ['inheritAppraisalFrom' => ['title']]],
				Exception::class,
				'x-openregister-retention',
			],
		];
	}//end severalDeclarationFaults()

	/**
	 * The refusal a schema with several declaration faults gets.
	 *
	 * @param array<string, mixed> $configuration The faulty declarations.
	 * @param class-string         $refusal       The refusal it gets.
	 * @param string               $names         What the refusal message starts with.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('severalDeclarationFaults')]
	public function testTheFirstDeclarationFaultInSlotOrderRefusesTheSave(array $configuration, string $refusal, string $names): void {
		$schema = new Schema();
		$schema->setSlug('faults');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		$thrown = null;
		try {
			(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($this->mapper(), $schema);
		} catch (Throwable $e) {
			$thrown = $e;
		}

		$this->assertInstanceOf(expected: $refusal, actual: $thrown);
		$this->assertStringStartsWith(prefix: $names, string: $thrown->getMessage());
	}//end testTheFirstDeclarationFaultInSlotOrderRefusesTheSave()

	/**
	 * A mapper whose dispatcher contributes the process checks as the app does.
	 *
	 * @return SchemaMapper
	 */
	private function mapper(): SchemaMapper {
		return new SchemaMapper(
			db: $this->createMock(originalClassName: IDBConnection::class),
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(originalClassName: OrganisationMapper::class),
				userSession: $this->createMock(originalClassName: IUserSession::class),
				groupManager: $this->createMock(originalClassName: IGroupManager::class)
			),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: new ProcessDeclarationChecksDispatcher(),
				validator: $this->createMock(originalClassName: PropertyValidatorHandler::class),
				logger: new NullLogger()
			)
		);
	}//end mapper()
}//end class
