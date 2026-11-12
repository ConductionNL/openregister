<?php

/**
 * Golden test for NotificationAnnotationValidator::validate().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact error list, in order, for every branch the validator itself
 * owns: rule shape, channels, opt-in channels, message category, origin app,
 * subject, channel targets, organisation gate, domain, transports, critical
 * and digest. The expected lists were recorded from the code before its
 * refactor and live in `fixtures/notification-annotation-validator-c7-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator
 */
class NotificationAnnotationValidatorGoldenTest extends TestCase {

	/**
	 * Override value that removes a key from the base rule.
	 */
	private const DROP = '__drop__';

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-annotation-validator-c7-golden.json';
	}//end goldenPath()

	/**
	 * A schema holding one rule built from a valid base plus overrides.
	 *
	 * @param array<string, mixed> $overrides Keys to replace; the value self::DROP removes the key.
	 *
	 * @return array<string, mixed>
	 */
	private static function one(array $overrides): array {
		$rule = [
			'trigger'    => ['type' => 'created'],
			'channels'   => ['nc-notification'],
			'subject'    => 'Hello',
			'recipients' => [['kind' => 'users', 'users' => ['admin']]],
		];
		foreach ($overrides as $key => $value) {
			unset($rule[$key]);
			if ($value !== self::DROP) {
				$rule[$key] = $value;
			}
		}

		return ['properties' => ['status' => ['type' => 'string']], 'x-openregister-notifications' => ['r' => $rule]];
	}//end one()

	/**
	 * The corpus: case name => schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function corpus(): array {
		return [
			'no-annotation'          => ['properties' => []],
			'annotation-empty'       => ['x-openregister-notifications' => []],
			'annotation-string'      => ['x-openregister-notifications' => 'x'],
			'valid'                  => self::one([]),
			'bad-names'              => ['x-openregister-notifications' => [0 => [], '' => [], 'm' => 'x', 'n' => 5]],
			'two-rules'              => ['x-openregister-notifications' => ['a' => ['channels' => 'x'], 'b' => ['subject' => '']]],
			'forced-no-reason'       => self::one(['forcedChannels' => ['channels' => ['email']]]),
			'channels-missing'       => self::one(['channels' => self::DROP]),
			'channels-string'        => self::one(['channels' => 'email']),
			'channels-bad'           => self::one(['channels' => ['fax', 'email', 7, null]]),
			'opt-in-string'          => self::one(['optInChannels' => 'email']),
			'opt-in-null'            => self::one(['optInChannels' => null]),
			'opt-in-mixed'           => self::one(['optInChannels' => ['email', 'webhook', 3, null, 1.5, ['x']]]),
			'opt-in-empty'           => self::one(['optInChannels' => []]),
			'category-ok'            => self::one(['messageCategory' => ' Service ']),
			'category-bad'           => self::one(['messageCategory' => ' Spam ']),
			'category-array'         => self::one(['messageCategory' => ['x']]),
			'category-empty'         => self::one(['messageCategory' => '']),
			'category-blank'         => self::one(['messageCategory' => '   ']),
			'category-int'           => self::one(['messageCategory' => 7]),
			'category-null'          => self::one(['messageCategory' => null]),
			'origin-empty'           => self::one(['originApp' => '']),
			'origin-int'             => self::one(['originApp' => 3]),
			'origin-ok'              => self::one(['originApp' => 'dossiq']),
			'subject-missing'        => self::one(['subject' => self::DROP]),
			'subject-int'            => self::one(['subject' => 5]),
			'subject-empty'          => self::one(['subject' => '']),
			'subject-map-empty'      => self::one(['subject' => []]),
			'subject-map-dflt-only'  => self::one(['subject' => ['defaultLocale' => 'nl']]),
			'subject-map-bad'        => self::one(['subject' => ['nl' => '', 'en' => 3, 'de' => 'ok', 0 => 'x', 'fr' => null]]),
			'subject-map-bad-dflt'   => self::one(['subject' => ['nl' => 'Hoi', 'defaultLocale' => 'en']]),
			'subject-map-int-dflt'   => self::one(['subject' => ['nl' => 'Hoi', 'defaultLocale' => 7]]),
			'subject-map-ok'         => self::one(['subject' => ['nl' => 'Hoi', 'en' => 'Hi', 'defaultLocale' => 'nl']]),
			'webhook-missing'        => self::one(['channels' => ['webhook']]),
			'webhook-string'         => self::one(['channels' => ['webhook'], 'webhook' => 'https://example.com']),
			'webhook-bad-url'        => self::one(['channels' => ['webhook'], 'webhook' => ['url' => 'not a url']]),
			'webhook-empty-url'      => self::one(['channels' => ['webhook'], 'webhook' => ['url' => '']]),
			'webhook-ok'             => self::one(['channels' => ['webhook'], 'webhook' => ['url' => 'https://example.com/h']]),
			'webhook-undeclared'     => self::one(['webhook' => ['url' => 'nope']]),
			'talk-missing'           => self::one(['channels' => ['talk']]),
			'talk-int-token'         => self::one(['channels' => ['talk'], 'talk' => ['token' => 5]]),
			'talk-empty-token'       => self::one(['channels' => ['talk'], 'talk' => ['token' => '']]),
			'talk-ok'                => self::one(['channels' => ['talk'], 'talk' => ['token' => 'abc']]),
			'webhook-and-talk'       => self::one(['channels' => ['webhook', 'talk', 'fax']]),
			'org-empty'              => self::one(['organisation' => '']),
			'org-int'                => self::one(['organisation' => 5]),
			'org-null'               => self::one(['organisation' => null]),
			'org-list-empty'         => self::one(['organisation' => []]),
			'org-list-bad'           => self::one(['organisation' => ['a', 1]]),
			'org-list-blank'         => self::one(['organisation' => ['a', '']]),
			'org-list-ok'            => self::one(['organisation' => ['a', 'b']]),
			'org-ok'                 => self::one(['organisation' => 'org-1']),
			'domain-empty'           => self::one(['domain' => '']),
			'domain-int'             => self::one(['domain' => 3]),
			'domain-ok'              => self::one(['domain' => 'finance']),
			'transports-string'      => self::one(['transports' => 'x']),
			'transports-empty'       => self::one(['transports' => []]),
			'transports-mixed'       => self::one(
				[
					'transports' => [
						'x',
						['kind' => 'inbound'],
						[],
						['kind' => 'outbound'],
						['kind' => 'outbound', 'handler' => ''],
						'k' => ['kind' => 'outbound', 'handler' => 'H'],
					],
				]
			),
			'critical-bad'           => self::one(['critical' => 'yes']),
			'critical-ok'            => self::one(['critical' => false]),
			'digest-not-object'      => self::one(['critical' => 1, 'digest' => 'x', 'coalesce' => ['window' => 60]]),
			'digest-empty'           => self::one(['digest' => []]),
			'digest-bad'             => self::one(['digest' => ['schedule' => 'hourly', 'at' => '25:00', 'timezone' => 'Mars/Base']]),
			'digest-weekly-ok'       => self::one(['digest' => ['schedule' => 'weekly', 'at' => '08:00', 'weekday' => 0, 'timezone' => 'Europe/Amsterdam']]),
			'digest-weekly-day-7'    => self::one(['digest' => ['schedule' => 'weekly', 'at' => '8:00', 'weekday' => 7]]),
			'digest-weekly-day-neg'  => self::one(['digest' => ['schedule' => 'weekly', 'at' => '23:59', 'weekday' => -1]]),
			'digest-weekly-no-day'   => self::one(['digest' => ['schedule' => 'weekly', 'at' => '23:59']]),
			'digest-weekly-str-day'  => self::one(['digest' => ['schedule' => 'weekly', 'at' => '12:00', 'weekday' => '1']]),
			'digest-weekly-null-day' => self::one(['digest' => ['schedule' => 'weekly', 'at' => '12:00', 'weekday' => null]]),
			'digest-daily-weekday'   => self::one(
				['digest' => ['schedule' => 'daily', 'at' => '07:30', 'weekday' => 2, 'timezone' => ''], 'coalesce' => ['window' => 60]]
			),
			'digest-daily-null-day'  => self::one(['digest' => ['schedule' => 'daily', 'at' => '07:30', 'weekday' => null, 'timezone' => null]]),
			'digest-timezone-int'    => self::one(['digest' => ['schedule' => 'daily', 'at' => 730, 'timezone' => 5]]),
			'digest-coalesce-scalar' => self::one(['digest' => ['schedule' => 'daily', 'at' => '00:00', 'timezone' => 'UTC'], 'coalesce' => 60]),
			'everything-wrong'       => self::one(
				[
					'trigger'         => self::DROP,
					'channels'        => ['webhook', 'talk'],
					'optInChannels'   => ['talk'],
					'messageCategory' => 'x',
					'originApp'       => '',
					'subject'         => 3,
					'organisation'    => 4,
					'domain'          => '',
					'transports'      => [5],
					'critical'        => 'no',
					'digest'          => [],
					'recipients'      => self::DROP,
				]
			),
		];
	}//end corpus()

	/**
	 * Data provider over the corpus.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::corpus() as $name => $schema) {
			$cases[$name] = [$name, $schema];
		}

		return $cases;
	}//end cases()

	/**
	 * The recorded error list for each case.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function golden(): array {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		self::assertIsArray($golden);

		return $golden;
	}//end golden()

	/**
	 * The validator returns the recorded error list for each case.
	 *
	 * @param string               $name   Case name.
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(string $name, array $schema): void {
		$golden = self::golden();
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], (new NotificationAnnotationValidator())->validate($schema));
	}//end testMatchesGolden()

	/**
	 * The golden covers exactly the corpus and is mostly non-empty, so it pins faults, not silence.
	 *
	 * @return void
	 */
	public function testGoldenIsComplete(): void {
		$golden = self::golden();
		$this->assertSame(array_keys(self::corpus()), array_keys($golden));
		$this->assertGreaterThanOrEqual(50, count(array_filter($golden)));
	}//end testGoldenIsComplete()
}//end class
