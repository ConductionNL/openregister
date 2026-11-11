<?php

/**
 * Characterisation of the small decision units of the notification engine.
 *
 * Pins, one case per branch, the outcomes of the coalesce settings parser,
 * the delivery-hold reason, the timezone fallback, the coalescer's state
 * inspection and the relation display-name lookup, so their restructuring
 * keeps every answer, every lookup and every log line.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Service\Notification\CoalesceBucket;
use OCA\OpenRegister\Notifications\Service\Notification\CoalesceRule;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold;
use OCA\OpenRegister\Notifications\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationCoalescer;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\RuleFiring;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * NotificationSmallUnitsCharacterizationTest.
 */
class NotificationSmallUnitsCharacterizationTest extends TestCase {

	private const UUID = '0b6f6f0e-3c1a-4d8e-9a51-2f4b7c9d1e23';

	/**
	 * @return array<string, array{0: mixed, 1: int, 2: int|null}>
	 */
	public static function coalesceValueProvider(): array {
		return [
			'positive int' => [30, 30, 30],
			'digit string' => ['45', 45, 45],
			'zero' => [0, 0, null],
			'zero string' => ['0', 0, null],
			'negative' => [-5, 0, null],
			'negative string' => ['-5', 0, null],
			'mixed string' => ['12a', 0, null],
			'empty string' => ['', 0, null],
			'float' => [3.5, 0, null],
			'bool' => [true, 0, null],
			'null' => [null, 0, null],
		];
	}

	/**
	 * @dataProvider coalesceValueProvider
	 */
	public function testCoalesceSettingsReadOnlyPositiveIntegers(mixed $value, int $window, ?int $max): void {
		$rule = new CoalesceRule(['windowSeconds' => $value, 'maxEvents' => $value]);

		$this->assertSame($window, $rule->windowSeconds);
		$this->assertSame($max, $rule->maxEvents);
	}

	public function testAbsentCoalesceSettingsReadAsNoWindowAndNoCap(): void {
		$rule = new CoalesceRule([]);

		$this->assertSame(0, $rule->windowSeconds);
		$this->assertNull($rule->maxEvents);
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: string|null}>
	 */
	public static function holdReasonProvider(): array {
		return [
			'quiet hours and a digest' => [true, true, QueuedNotification::REASON_BOTH],
			'quiet hours only' => [true, false, QueuedNotification::REASON_QUIET_HOURS],
			'a digest only' => [false, true, QueuedNotification::REASON_DIGEST_SCHEDULE],
			'neither' => [false, false, null],
		];
	}

	/**
	 * @dataProvider holdReasonProvider
	 */
	public function testTheHoldReasonFollowsQuietHoursAndTheDigest(bool $inside, bool $digest, ?string $reason): void {
		$window = $this->createMock(NotificationDeliveryWindowService::class);
		$window->method('getForUser')->willReturn(['start' => '22:00', 'end' => '07:00']);
		$window->method('isInsideWindow')->willReturn($inside);
		$evaluator = $this->createMock(DigestScheduleEvaluator::class);
		$evaluator->method('isValidDigestSpec')->willReturn(true);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-03-04 05:06:07', new DateTimeZone('UTC')));

		$spec     = ($digest === true) ? ['digest' => ['at' => '08:00']] : [];
		$decision = (new DeliveryHold($window, $evaluator, null, $clock))->decide(
			new RuleFiring('rule-1', $spec, new ObjectEntity(), new Schema(), [], 'object_created'),
			'alice'
		);

		$this->assertSame($reason, $decision?->reason);
	}

	/**
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function timezoneProvider(): array {
		return [
			'a valid declared zone' => ['Europe/Amsterdam', 'Europe/Amsterdam'],
			'no declared zone' => [null, 'America/Chicago'],
			'an empty declared zone' => ['', 'America/Chicago'],
			'a name DateTimeZone refuses' => ['Not/AZone', 'America/Chicago'],
		];
	}

	/**
	 * @dataProvider timezoneProvider
	 */
	public function testTheDeclaredZoneWinsOnlyWhenItIsAValidName(?string $declared, string $expected): void {
		$server = $this->createMock(IDateTimeZone::class);
		$server->method('getTimeZone')->willReturn(new DateTimeZone('America/Chicago'));
		$service = new NotificationDeliveryWindowService($this->createMock(IConfig::class), $server);

		$this->assertSame($expected, $service->resolveTimezone($declared)->getName());
	}

	public function testWithoutAnyZoneTheFallbackIsUtc(): void {
		$service = new NotificationDeliveryWindowService($this->createMock(IConfig::class));

		$this->assertSame('UTC', $service->resolveTimezone('Not/AZone')->getName());
	}

	/**
	 * @param mixed $stored What the cache answers for the bucket's key, or a Throwable to throw.
	 */
	private function coalescerOver(mixed $stored): NotificationCoalescer {
		$cache = $this->createMock(ICache::class);
		if ($stored instanceof \Throwable) {
			$cache->method('get')->willThrowException($stored);
		} else {
			$cache->method('get')->with((new CoalesceBucket('rule-a', 'alice'))->key())->willReturn($stored);
		}

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new NotificationCoalescer($factory, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));
	}

	public function testInspectDecodesTheStoredWindow(): void {
		$coalescer = $this->coalescerOver('{"count":"4","opened":1700000000}');

		$this->assertSame(['count' => 4, 'opened' => 1700000000], $coalescer->inspect('rule-a', 'alice'));
	}

	public function testInspectAnswersNullForAnUndecodableValue(): void {
		$this->assertNull($this->coalescerOver('{"count":1}')->inspect('rule-a', 'alice'));
		$this->assertNull($this->coalescerOver(null)->inspect('rule-a', 'alice'));
	}

	public function testInspectAnswersNullWhenTheCacheReadFails(): void {
		$this->assertNull($this->coalescerOver(new RuntimeException('redis down'))->inspect('rule-a', 'alice'));
	}

	public function testInspectAnswersNullForAnEmptyRuleOrRecipientWithoutReading(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->never())->method('get');
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$coalescer = new NotificationCoalescer($factory, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));

		$this->assertNull($coalescer->inspect('', 'alice'));
		$this->assertNull($coalescer->inspect('rule-a', ''));
	}

	public function testInspectAnswersNullWithoutACache(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new RuntimeException('no backend'));
		$coalescer = new NotificationCoalescer($factory, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));

		$this->assertNull($coalescer->inspect('rule-a', 'alice'));
	}

	public function testANonUuidValueIsNeverLookedUp(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->never())->method('find');

		$templating = new NotificationTemplating($this->createMock(LoggerInterface::class), $objects);

		$this->assertNull($templating->resolveRelationDisplayName('not-a-uuid'));
	}

	public function testWithoutAnObjectServiceAUuidStaysRaw(): void {
		$templating = new NotificationTemplating($this->createMock(LoggerInterface::class));

		$this->assertNull($templating->resolveRelationDisplayName(self::UUID));
	}

	public function testANamedObjectIsResolvedOnceAndThenServedFromTheCache(): void {
		$related = new ObjectEntity();
		$related->setName('Acme Gemeente BV');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')->with(self::UUID)->willReturn($related);

		$templating = new NotificationTemplating($this->createMock(LoggerInterface::class), $objects);

		$this->assertSame('Acme Gemeente BV', $templating->resolveRelationDisplayName(self::UUID));
		$this->assertSame('Acme Gemeente BV', $templating->resolveRelationDisplayName(self::UUID));
	}

	/**
	 * @return array<string, array{0: ObjectEntity|null}>
	 */
	public static function namelessProvider(): array {
		$blank = new ObjectEntity();
		$blank->setName('');

		return [
			'nothing found' => [null],
			'an object without a name' => [new ObjectEntity()],
			'an object with an empty name' => [$blank],
		];
	}

	/**
	 * @dataProvider namelessProvider
	 */
	public function testANamelessResultStaysRawAndIsCached(?ObjectEntity $related): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')->willReturn($related);

		$templating = new NotificationTemplating($this->createMock(LoggerInterface::class), $objects);

		$this->assertNull($templating->resolveRelationDisplayName(self::UUID));
		$this->assertNull($templating->resolveRelationDisplayName(self::UUID));
	}

	public function testAFailedLookupIsLoggedAtDebugAndCachedAsRaw(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('find')->willThrowException(new RuntimeException('forbidden'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with('[NotificationTemplating] relation display-name resolve failed: forbidden');

		$templating = new NotificationTemplating($logger, $objects);

		$this->assertNull($templating->resolveRelationDisplayName(self::UUID));
		$this->assertNull($templating->resolveRelationDisplayName(self::UUID));
	}
}
