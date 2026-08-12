<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the override-only preference model: zero-migration fall-through to
 * the schema default, per-user isolation, channel narrowing, and clear-on-reset.
 */
class NotificationPreferenceServiceTest extends TestCase {
	private IConfig&MockObject $config;
	private SchemaMapper&MockObject $schemaMapper;
	private LoggerInterface&MockObject $logger;
	private NotificationPreferenceService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new NotificationPreferenceService(
			$this->config,
			$this->schemaMapper,
			$this->logger
		);
	}

	public function testConfigKeyShape(): void {
		$this->assertSame(
			'notification_pref/meldingen/object_created',
			$this->service->configKey('meldingen', 'object_created')
		);
	}

	/**
	 * Phase-0 regression: a natural (slug, key) pair that fits in the 64-char
	 * oc_preferences.configkey column must be returned UNCHANGED so every
	 * already-stored preference keeps resolving (full backward compatibility).
	 */
	public function testConfigKeyUnchangedWhenItFitsTheColumn(): void {
		$key = $this->service->configKey('meldingen', 'object_created');
		$this->assertLessThanOrEqual(64, strlen($key));
		$this->assertSame('notification_pref/meldingen/object_created', $key);
	}

	/**
	 * Phase-0 regression: a (slug, key) pair that overflows the 64-char column
	 * (e.g. the system schema `openregister_configuration` +
	 * `configuration-changed` = 66 chars) must be deterministically compressed
	 * to a stable <=64-char key instead of letting NC throw
	 * "for key is too long (64)".
	 */
	public function testConfigKeyCompressesWhenOverSixtyFourChars(): void {
		$slug = 'openregister_configuration';
		$note = 'configuration-changed';

		// Sanity: the natural key really does overflow.
		$natural = 'notification_pref/' . $slug . '/' . $note;
		$this->assertGreaterThan(64, strlen($natural));

		$key = $this->service->configKey($slug, $note);

		// Compressed key fits the column.
		$this->assertLessThanOrEqual(64, strlen($key));
		// Still namespaced under the readable prefix.
		$this->assertStringStartsWith('notification_pref/', $key);
		// Carries the stable 16-char hash separator so distinct pairs never collide.
		$this->assertStringContainsString('~', $key);
	}

	/**
	 * Phase-0 regression: the compression is deterministic (same pair -> same
	 * key) and collision-resistant (distinct pairs -> distinct keys), so a
	 * stored override is always found again.
	 */
	public function testConfigKeyCompressionIsDeterministicAndDistinct(): void {
		$a1 = $this->service->configKey('openregister_configuration', 'configuration-changed');
		$a2 = $this->service->configKey('openregister_configuration', 'configuration-changed');
		$b = $this->service->configKey('openregister_configuration', 'configuration-removed');

		$this->assertSame($a1, $a2, 'Same pair must map to the same compressed key.');
		$this->assertNotSame($a1, $b, 'Distinct pairs must map to distinct compressed keys.');
		$this->assertLessThanOrEqual(64, strlen($a1));
		$this->assertLessThanOrEqual(64, strlen($b));
	}

	public function testFallThroughToSchemaDefaultWhenNoOverride(): void {
		// No stored value → resolve to the schema default, tagged schema-default.
		$this->config->method('getUserValue')->willReturn('');

		$effective = $this->service->resolveEffective(
			['enabled' => true, 'channels' => ['nc-notification']],
			'piet',
			'meldingen',
			'object_created'
		);

		$this->assertTrue($effective['enabled']);
		$this->assertSame('schema-default', $effective['source']);
	}

	public function testOverrideFlipsSchemaDefaultOff(): void {
		$this->config->method('getUserValue')->willReturn('{"enabled":false}');

		$effective = $this->service->resolveEffective(
			['enabled' => true],
			'jan',
			'meldingen',
			'object_created'
		);

		$this->assertFalse($effective['enabled']);
		$this->assertSame('user-override', $effective['source']);
	}

	public function testPerUserIsolation(): void {
		// jan has an override; piet has none — the same pair resolves
		// independently per user.
		$this->config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, mixed $default = '') {
				return $uid === 'jan' ? '{"enabled":false}' : $default;
			}
		);

		$jan = $this->service->resolveEffective(['enabled' => true], 'jan', 'meldingen', 'object_created');
		$piet = $this->service->resolveEffective(['enabled' => true], 'piet', 'meldingen', 'object_created');

		$this->assertFalse($jan['enabled']);
		$this->assertTrue($piet['enabled']);
	}

	public function testChannelNarrowingRestrictsToSubset(): void {
		// Override narrows the two declared channels down to in-app only.
		$this->config->method('getUserValue')->willReturn('{"enabled":true,"channels":["nc-notification"]}');

		$effective = $this->service->resolveEffective(
			['enabled' => true, 'channels' => ['nc-notification', 'push']],
			'jan',
			'meldingen',
			'object_created'
		);

		$this->assertTrue($effective['enabled']);
		$this->assertSame(['nc-notification'], $effective['channels']);
	}

	public function testSetOverrideClearsWhenNull(): void {
		$this->config->expects($this->once())
			->method('deleteUserValue')
			->with('jan', 'openregister', 'notification_pref/meldingen/object_created');
		$this->config->expects($this->never())->method('setUserValue');

		$this->service->setOverride('jan', 'meldingen', 'object_created', null);
	}

	public function testSetOverrideWritesJson(): void {
		$this->config->expects($this->once())
			->method('setUserValue')
			->with(
				'jan',
				'openregister',
				'notification_pref/meldingen/object_created',
				$this->callback(static function (string $json): bool {
					$decoded = json_decode($json, true);
					return is_array($decoded) && ($decoded['enabled'] ?? null) === false;
				})
			);

		$this->service->setOverride('jan', 'meldingen', 'object_created', ['enabled' => false]);
	}

	public function testGetEffectiveForUserMergesDeclaredNotifications(): void {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('meldingen');
		$schema->setTitle('Meldingen');
		$schema->setApplication('pipelinq');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				'object_created' => ['enabled' => true, 'channels' => ['nc-notification']],
				'object_updated' => ['enabled' => false],
			],
		]);

		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		// jan overrode object_created off; object_updated falls through.
		$this->config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, mixed $default = '') {
				return $key === 'notification_pref/meldingen/object_created' ? '{"enabled":false}' : $default;
			}
		);

		$entries = $this->service->getEffectiveForUser('jan');

		$this->assertCount(2, $entries);
		$byKey = [];
		foreach ($entries as $e) {
			$byKey[$e['notification']] = $e;
		}

		$this->assertFalse($byKey['object_created']['enabled']);
		$this->assertSame('user-override', $byKey['object_created']['source']);
		$this->assertFalse($byKey['object_updated']['enabled']);
		$this->assertSame('schema-default', $byKey['object_updated']['source']);
		$this->assertSame('pipelinq', $byKey['object_created']['application']);
		$this->assertSame('pipelinq', $byKey['object_updated']['application']);
	}
}
