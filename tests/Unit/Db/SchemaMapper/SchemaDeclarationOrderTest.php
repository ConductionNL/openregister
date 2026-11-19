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

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Listener\SchemaDeclarationChecksListener;
use OCA\OpenRegister\Notifications\Listener\NotificationDeclarationCheckListener;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException;
use OCA\OpenRegister\Service\Calculation\CalculationDeclarationException;
use OCA\OpenRegister\Service\Consent\ConsentDeclarationException;
use OCA\OpenRegister\Exception\UniqueHintException;
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
		$listener  = new SchemaDeclarationChecksListener(logger: new NullLogger(), container: $container);
		$listener->handle(event: $event);
		(new NotificationDeclarationCheckListener(logger: new NullLogger()))->handle(event: $event);
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
	 * A schema whose configuration has several faults, one of them in the notification slot.
	 *
	 * @return array<string, array{array<string, mixed>, class-string}>
	 */
	public static function severalConfigurationFaults(): array {
		return [
			'unique hint (Core) before notifications (Notifications)' => [
				[
					'x-openregister-unique-hint' => 'nonsense',
					'x-openregister-notifications' => self::unlinkableNotification(),
				],
				UniqueHintException::class,
			],
			'notifications (Notifications) before widgets (Core)' => [
				[
					'x-openregister-notifications' => self::unlinkableNotification(),
					'x-openregister-widgets' => [['nope' => 1]],
				],
				NotificationDeclarationException::class,
			],
		];
	}//end severalConfigurationFaults()

	/**
	 * Each fault of those cases refuses the save on its own.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function singleConfigurationFaults(): array {
		return [
			'unique hint' => [['x-openregister-unique-hint' => 'nonsense']],
			'notifications' => [['x-openregister-notifications' => self::unlinkableNotification()]],
			'widgets' => [['x-openregister-widgets' => [['nope' => 1]]]],
		];
	}//end singleConfigurationFaults()

	/**
	 * The refusal a schema whose configuration has several faults gets.
	 *
	 * @param array<string, mixed> $configuration The faulty configuration.
	 * @param class-string         $refusal       The refusal it gets.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('severalConfigurationFaults')]
	public function testTheFirstConfigurationFaultInSlotOrderRefusesTheSave(array $configuration, string $refusal): void {
		$this->assertInstanceOf(expected: $refusal, actual: $this->refusalOf(configuration: $configuration));
	}//end testTheFirstConfigurationFaultInSlotOrderRefusesTheSave()

	/**
	 * Each fault alone refuses the save, so the cases above test the order.
	 *
	 * @param array<string, mixed> $configuration The faulty configuration.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('singleConfigurationFaults')]
	public function testEachConfigurationFaultAloneRefusesTheSave(array $configuration): void {
		$this->assertInstanceOf(expected: Throwable::class, actual: $this->refusalOf(configuration: $configuration));
	}//end testEachConfigurationFaultAloneRefusesTheSave()

	/**
	 * A notification rule whose url action cannot be linked to.
	 *
	 * @return array<string, mixed>
	 */
	private static function unlinkableNotification(): array {
		return [
			'opened' => [
				'trigger' => ['type' => 'created'],
				'channels' => ['nc-notification'],
				'recipients' => [['kind' => 'users', 'users' => ['admin']]],
				'subject' => 'Opened',
				'actions' => [['label' => ['nl' => 'Open'], 'target' => ['kind' => 'url', 'href' => 'call']]],
			],
		];
	}//end unlinkableNotification()

	/**
	 * What the save of a schema with this configuration throws, if anything.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Throwable|null
	 */
	private function refusalOf(array $configuration): ?Throwable {
		$schema = new Schema();
		$schema->setSlug('faults');
		$schema->setConfiguration($configuration);

		try {
			(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($this->mapper(), $schema);
		} catch (Throwable $e) {
			return $e;
		}

		return null;
	}//end refusalOf()

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
