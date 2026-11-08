<?php

/**
 * Characterisation of FlowTimerDefinitionStore's descriptor fallback.
 *
 * Pins which descriptor objects become definitions, and what is logged
 * and returned when the descriptor is absent, unreadable or not JSON.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore
 */
final class FlowTimerDescriptorCharacterisationTest extends TestCase {

	/**
	 * A scratch app root holding the descriptor.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Warnings the store logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Make a scratch app root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/or-timer-descriptor-' . bin2hex(random_bytes(6));
		mkdir($this->root . '/lib/Settings', 0777, true);
	}//end setUp()

	/**
	 * Remove the scratch app root.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->root . '/lib/Settings/flow_timer_register.json');
		@rmdir($this->root . '/lib/Settings');
		@rmdir($this->root . '/lib');
		@rmdir($this->root);

		parent::tearDown();
	}//end tearDown()

	/**
	 * A store whose register is empty, so the descriptor answers.
	 *
	 * @param IAppManager|null $appManager The app manager, or one pointing at the scratch root.
	 *
	 * @return FlowTimerDefinitionStore The store.
	 */
	private function store(?IAppManager $appManager = null): FlowTimerDefinitionStore {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsBySlug')->willReturn([]);

		if ($appManager === null) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('getAppPath')->willReturn($this->root);
		}

		$warnings = &$this->warnings;
		$logger = new class($warnings) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $warnings Where warnings are collected.
			 */
			public function __construct(private array &$warnings) {
			}

			/**
			 * Collect a warning.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				if ($level === 'warning') {
					$this->warnings[] = (string)$message;
				}
			}
		};

		return new FlowTimerDefinitionStore(objects: $objects, appManager: $appManager, logger: $logger);
	}//end store()

	/**
	 * Write the descriptor file.
	 *
	 * @param string $contents The raw contents.
	 *
	 * @return void
	 */
	private function descriptor(string $contents): void {
		file_put_contents($this->root . '/lib/Settings/flow_timer_register.json', $contents);
	}//end descriptor()

	/**
	 * Only objects of the asked schema with a slug are kept, keyed by the
	 * trimmed slug, `@self` stripped, a repeated slug keeping the last.
	 *
	 * @return void
	 */
	public function testWhichObjectsBecomeDefinitions(): void {
		$this->descriptor(
			(string)json_encode(
				[
					'components' => [
						'objects' => [
							'not-an-object',
							['@self' => ['schema' => 'working-calendar'], 'slug' => ' first ', 'n' => 1],
							['@self' => ['schema' => 'escalation-ladder'], 'slug' => 'ladder', 'n' => 2],
							['@self' => ['schema' => 'working-calendar'], 'slug' => '   ', 'n' => 3],
							['slug' => 'no-self', 'n' => 4],
							['@self' => ['schema' => 'working-calendar'], 'slug' => 'first', 'n' => 5],
							['@self' => ['schema' => 'working-calendar'], 'slug' => 'second', 'n' => 6],
						],
					],
				]
			)
		);

		$store = $this->store();

		$this->assertSame(
			[
				'first' => ['slug' => 'first', 'n' => 5],
				'second' => ['slug' => 'second', 'n' => 6],
			],
			$store->calendars()
		);
		$this->assertSame(['ladder' => ['slug' => 'ladder', 'n' => 2]], $store->ladders());
		$this->assertSame([], $this->warnings);
	}//end testWhichObjectsBecomeDefinitions()

	/**
	 * An absent descriptor yields nothing and says where it looked.
	 *
	 * @return void
	 */
	public function testAnAbsentDescriptorYieldsNothingAndIsLogged(): void {
		$this->assertSame([], $this->store()->calendars());
		$this->assertSame(
			['[FlowTimerDefinitionStore] Descriptor absent: ' . $this->root . '/lib/Settings/flow_timer_register.json'],
			$this->warnings
		);
	}//end testAnAbsentDescriptorYieldsNothingAndIsLogged()

	/**
	 * A descriptor that cannot be located yields nothing and logs why.
	 *
	 * @return void
	 */
	public function testAnUnreadableDescriptorYieldsNothingAndIsLogged(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new RuntimeException('app gone'));

		$this->assertSame([], $this->store($appManager)->calendars());
		$this->assertSame(['[FlowTimerDefinitionStore] Descriptor unreadable: app gone'], $this->warnings);
	}//end testAnUnreadableDescriptorYieldsNothingAndIsLogged()

	/**
	 * A descriptor that is not JSON, or has no objects, yields nothing quietly.
	 *
	 * @return void
	 */
	public function testANonJsonOrObjectlessDescriptorYieldsNothingQuietly(): void {
		$this->descriptor('{ not json');
		$this->assertSame([], $this->store()->calendars());

		$this->descriptor('{"components": {}}');
		$this->assertSame([], $this->store()->calendars());

		$this->assertSame([], $this->warnings);
	}//end testANonJsonOrObjectlessDescriptorYieldsNothingQuietly()
}//end class
