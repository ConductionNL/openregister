<?php

/**
 * Characterization tests for NotificationAnnotationValidator::validate().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact error list, in order, for a corpus that reaches every branch
 * of the annotation validator. The expected lists live in
 * `fixtures/notification-annotation-validator-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator
 */
class NotificationAnnotationValidatorCharacterizationTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-annotation-validator-golden.json';
	}//end goldenPath()

	/**
	 * Wrap one or more rules in a schema.
	 *
	 * @param array<string|int, mixed> $rules The `x-openregister-notifications` map.
	 * @param array<string, mixed>     $extra Extra schema keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(array $rules, array $extra = []): array {
		return array_merge(
			[
				'properties' => [
					'assignee' => ['type' => 'string'],
					'supplier' => ['type' => 'object', '$ref' => 'supplier'],
					'status'   => ['type' => 'string'],
				],
				'x-openregister-notifications' => $rules,
			],
			$extra
		);
	}//end schema()

	/**
	 * A valid rule, optionally overridden.
	 *
	 * @param array<string, mixed> $overrides Keys to replace; a null value removes the key.
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(array $overrides = []): array {
		$rule = [
			'trigger'    => ['type' => 'created'],
			'channels'   => ['nc-notification'],
			'subject'    => 'Hello {{status}}',
			'recipients' => [['kind' => 'users', 'users' => ['admin']]],
		];
		foreach ($overrides as $key => $value) {
			if ($value === null) {
				unset($rule[$key]);
				continue;
			}

			$rule[$key] = $value;
		}

		return $rule;
	}//end rule()

	/**
	 * The corpus: name => schema.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public static function corpus(): array {
		$cases = [
			'no-annotation'          => ['properties' => []],
			'empty-annotation'       => ['x-openregister-notifications' => []],
			'annotation-not-array'   => ['x-openregister-notifications' => 'x'],
			'valid-minimal'          => self::schema(['r' => self::rule()]),
			'properties-not-array'   => ['properties' => 'x', 'x-openregister-notifications' => ['r' => self::rule(['recipients' => [['kind' => 'field', 'field' => 'status']]])]],
			'bad-name-and-malformed' => self::schema([0 => self::rule(), 'm' => 'not-an-object', '' => self::rule()]),
			'app-event-string-ok'    => self::schema(['r' => self::rule(['trigger' => 'booking.confirmed'])]),
			'app-event-string-bad'   => self::schema(['r' => self::rule(['trigger' => 'Booking'])]),
			'app-event-reserved'     => self::schema(['r' => self::rule(['trigger' => 'created'])]),
			'app-event-empty'        => self::schema(['r' => self::rule(['trigger' => ['event' => '']])]),
			'app-event-in-object'    => self::schema(['r' => self::rule(['trigger' => ['type' => 'nope', 'event' => 'a.b']])]),
			'bad-trigger-type'       => self::schema(['r' => self::rule(['trigger' => ['type' => 'deleted']])]),
			'no-trigger'             => self::schema(['r' => self::rule(['trigger' => null])]),
			'trigger-int'            => self::schema(['r' => self::rule(['trigger' => 5])]),
			'scheduled-no-interval'  => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled']])]),
			'scheduled-low-interval' => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 30]])]),
			'scheduled-filter-str'   => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => 'x']])]),
			'scheduled-filter-ok'    => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['status' => 'open']]])]),
			'scheduled-filter-bad'   => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'filter' => ['status' => ['bogus' => 1]]]])]),
			'scheduled-dedupe-str'   => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'dedupeFields' => 'x']])]),
			'scheduled-dedupe-empty' => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'dedupeFields' => []]])]),
			'scheduled-dedupe-bad'   => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 60, 'dedupeFields' => ['a', '', 3]]])]),
			'scheduled-dedupe-ok'    => self::schema(['r' => self::rule(['trigger' => ['type' => 'scheduled', 'intervalSec' => 3600, 'dedupeFields' => ['a', 'b']]])]),
			'threshold-empty'        => self::schema(['r' => self::rule(['trigger' => ['type' => 'threshold']])]),
			'threshold-bad-op'       => self::schema(['r' => self::rule(['trigger' => ['type' => 'threshold', 'aggregation' => 'n', 'op' => 'between', 'value' => 1]])]),
			'threshold-ok'           => self::schema(['r' => self::rule(['trigger' => ['type' => 'threshold', 'aggregation' => 'n', 'op' => 'gte', 'value' => null]])]),
			'calc-empty'             => self::schema(['r' => self::rule(['trigger' => ['type' => 'calculatedChange']])]),
			'calc-bad-clauses'       => self::schema(['r' => self::rule(['trigger' => ['type' => 'calculatedChange', 'field' => 'f', 'condition' => [], 'previously' => 'x']])]),
			'calc-bad-ops'           => self::schema(['r' => self::rule(['trigger' => ['type' => 'calculatedChange', 'field' => 'f', 'condition' => ['lt' => 1, 'foo' => 2, 0 => 3], 'previously' => ['bar' => 1]]])]),
			'calc-ok'                => self::schema(['r' => self::rule(['trigger' => ['type' => 'calculatedChange', 'field' => 'f', 'condition' => ['lt' => 1], 'previously' => ['gte' => 1]]])]),
			'forced-no-reason'       => self::schema(['r' => self::rule(['forcedChannels' => ['channels' => ['email']]])]),
			'forced-internal-only'   => self::schema(['r' => self::rule(['internalOnly' => true, 'channels' => ['email', 'webhook'], 'webhook' => ['url' => 'https://example.com/h']])]),
			'channels-missing'       => self::schema(['r' => self::rule(['channels' => null])]),
			'channels-string'        => self::schema(['r' => self::rule(['channels' => 'email'])]),
			'channels-bad'           => self::schema(['r' => self::rule(['channels' => ['fax', 'email', 7]])]),
			'webhook-missing'        => self::schema(['r' => self::rule(['channels' => ['webhook']])]),
			'webhook-bad-url'        => self::schema(['r' => self::rule(['channels' => ['webhook'], 'webhook' => ['url' => 'not a url']])]),
			'webhook-empty-url'      => self::schema(['r' => self::rule(['channels' => ['webhook'], 'webhook' => []])]),
			'webhook-ok'             => self::schema(['r' => self::rule(['channels' => ['webhook'], 'webhook' => ['url' => 'https://example.com/h']])]),
			'talk-missing'           => self::schema(['r' => self::rule(['channels' => ['talk']])]),
			'talk-int-token'         => self::schema(['r' => self::rule(['channels' => ['talk'], 'talk' => ['token' => 5]])]),
			'talk-ok'                => self::schema(['r' => self::rule(['channels' => ['talk'], 'talk' => ['token' => 'abc']])]),
			'opt-in-string'          => self::schema(['r' => self::rule(['optInChannels' => 'email'])]),
			'opt-in-mixed'           => self::schema(['r' => self::rule(['optInChannels' => ['email', 'webhook', 3, null]])]),
			'opt-in-ok'              => self::schema(['r' => self::rule(['optInChannels' => ['email']])]),
			'category-ok'            => self::schema(['r' => self::rule(['messageCategory' => ' Service '])]),
			'category-bad'           => self::schema(['r' => self::rule(['messageCategory' => 'spam'])]),
			'category-array'         => self::schema(['r' => self::rule(['messageCategory' => ['x']])]),
			'category-empty'         => self::schema(['r' => self::rule(['messageCategory' => ''])]),
			'origin-app-empty'       => self::schema(['r' => self::rule(['originApp' => ''])]),
			'origin-app-int'         => self::schema(['r' => self::rule(['originApp' => 3])]),
			'origin-app-ok'          => self::schema(['r' => self::rule(['originApp' => 'dossiq'])]),
			'actions-not-array'      => self::schema(['r' => self::rule(['actions' => 'x'])]),
			'actions-too-many'       => self::schema(['r' => self::rule(['actions' => [['label' => ['en' => 'a'], 'target' => ['kind' => 'url', 'url' => 'https://a.b']], ['label' => 'b'], ['target' => ['kind' => 'nope']]]])]),
			'actions-task-verb'      => self::schema(['r' => self::rule(['actions' => [['label' => ['en' => 'Claim'], 'target' => ['kind' => 'task-verb', 'verb' => 'steal']]]])]),
			'subject-missing'        => self::schema(['r' => self::rule(['subject' => null])]),
			'subject-int'            => self::schema(['r' => self::rule(['subject' => 5])]),
			'subject-empty'          => self::schema(['r' => self::rule(['subject' => ''])]),
			'subject-map-empty'      => self::schema(['r' => self::rule(['subject' => ['defaultLocale' => 'nl']])]),
			'subject-map-bad-locale' => self::schema(['r' => self::rule(['subject' => ['nl' => '', 'en' => 3, 'de' => 'ok', 0 => 'x']])]),
			'subject-map-bad-dflt'   => self::schema(['r' => self::rule(['subject' => ['nl' => 'Hoi', 'defaultLocale' => 'en']])]),
			'subject-map-int-dflt'   => self::schema(['r' => self::rule(['subject' => ['nl' => 'Hoi', 'defaultLocale' => 7]])]),
			'subject-map-ok'         => self::schema(['r' => self::rule(['subject' => ['nl' => 'Hoi', 'en' => 'Hi', 'defaultLocale' => 'nl']])]),
			'message-bad'            => self::schema(['r' => self::rule(['message' => 5])]),
			'message-map-bad'        => self::schema(['r' => self::rule(['message' => ['nl' => '']])]),
			'message-ok'             => self::schema(['r' => self::rule(['message' => 'Body'])]),
			'organisation-int'       => self::schema(['r' => self::rule(['organisation' => 5])]),
			'organisation-list-bad'  => self::schema(['r' => self::rule(['organisation' => ['a', 1]])]),
			'organisation-ok'        => self::schema(['r' => self::rule(['organisation' => 'org-1'])]),
			'domain-empty'           => self::schema(['r' => self::rule(['domain' => ''])]),
			'domain-int'             => self::schema(['r' => self::rule(['domain' => 3])]),
			'domain-ok'              => self::schema(['r' => self::rule(['domain' => 'finance'])]),
			'transports-bad'         => self::schema(['r' => self::rule(['transports' => 'x'])]),
			'transports-bad-kind'    => self::schema(['r' => self::rule(['transports' => [['kind' => 'inbound']]])]),
			'critical-bad'           => self::schema(['r' => self::rule(['critical' => 'yes'])]),
			'digest-bad'             => self::schema(['r' => self::rule(['digest' => ['at' => '25:00', 'timezone' => 'Mars/Base']])]),
			'digest-not-object'      => self::schema(['r' => self::rule(['digest' => 'x'])]),
			'digest-weekly-ok'       => self::schema(['r' => self::rule(['critical' => true, 'digest' => ['schedule' => 'weekly', 'at' => '08:00', 'weekday' => 1, 'timezone' => 'Europe/Amsterdam']])]),
			'digest-weekly-bad-day'  => self::schema(['r' => self::rule(['digest' => ['schedule' => 'weekly', 'at' => '8:00', 'weekday' => 9]])]),
			'digest-weekly-no-day'   => self::schema(['r' => self::rule(['digest' => ['schedule' => 'weekly', 'at' => '23:59', 'weekday' => '1']])]),
			'digest-daily-weekday'   => self::schema(['r' => self::rule(['digest' => ['schedule' => 'daily', 'at' => '07:30', 'weekday' => 2, 'timezone' => ''], 'coalesce' => ['window' => 60]])]),
			'digest-timezone-int'    => self::schema(['r' => self::rule(['digest' => ['schedule' => 'daily', 'at' => 730, 'timezone' => 5]])]),
			'actions-shapes'         => self::schema(['r' => self::rule(['actions' => ['x', ['label' => ['nl' => '', 'en' => 'Go'], 'primary' => 'yes', 'target' => 'x']]])]),
			'actions-label-empty'    => self::schema(['r' => self::rule(['actions' => [['label' => [], 'primary' => true, 'target' => ['kind' => 'task-verb', 'verb' => 'claim']], ['label' => ['en' => 5], 'target' => ['kind' => 'route']]]])]),
			'message-empty'          => self::schema(['r' => self::rule(['message' => ''])]),
			'message-map-no-locale'  => self::schema(['r' => self::rule(['message' => ['defaultLocale' => 'nl']])]),
			'message-map-default'    => self::schema(['r' => self::rule(['message' => ['nl' => 'a', 'en' => 5, 'defaultLocale' => 'de']])]),
			'message-map-default-int' => self::schema(['r' => self::rule(['message' => ['nl' => 'a', 0 => 'b', 'defaultLocale' => 5]])]),
			'message-map-ok'         => self::schema(['r' => self::rule(['message' => ['nl' => 'a', 'en' => 'b', 'defaultLocale' => 'en']])]),
			'recipients-missing'     => self::schema(['r' => self::rule(['recipients' => null]), 's' => self::rule(['subject' => ''])]),
			'recipients-string'      => self::schema(['r' => self::rule(['recipients' => 'admin'])]),
			'recipient-malformed'    => self::schema(['r' => self::rule(['recipients' => ['admin', ['kind' => 'users', 'users' => ['a']]]])]),
			'recipient-watchers'     => self::schema(['r' => self::rule(['recipients' => [['watchers' => 'yes'], ['watchers' => true], ['watchers' => true, 'kind' => 'field']]])]),
			'recipient-bad-kind'     => self::schema(['r' => self::rule(['recipients' => [['kind' => 'robots'], []]])]),
			'recipient-names-nobody' => self::schema(['r' => self::rule(['recipients' => [['kind' => 'groups', 'groups' => []], ['kind' => 'users'], ['kind' => 'groups', 'groups' => ['g']]]])]),
			'recipient-field'        => self::schema(['r' => self::rule(['recipients' => [['kind' => 'field', 'field' => 'nope'], ['kind' => 'field'], ['kind' => 'field', 'field' => 'assignee']]])]),
			'recipient-email'        => self::schema(['r' => self::rule(['recipients' => [['kind' => 'email'], ['kind' => 'email', 'field' => 'nope.x'], ['kind' => 'email', 'field' => 'status.x'], ['kind' => 'email', 'field' => 'supplier.contactEmail']]])]),
			'recipient-email-ok'     => self::schema(['r' => self::rule(['channels' => ['email'], 'recipients' => [['kind' => 'email', 'field' => 'supplier.contactEmail'], ['kind' => 'email', 'field' => 'status']]])]),
			'recipient-acl'          => self::schema(['r' => self::rule(['recipients' => [['kind' => 'object-acl'], ['kind' => 'object-acl', 'permission' => 'write'], ['kind' => 'object-acl', 'permission' => 'read']]])]),
			'recipient-role-free'    => self::schema(['r' => self::rule(['recipients' => [['kind' => 'role'], ['kind' => 'role', 'role' => 'anyone']]])]),
			'recipient-role-declared' => self::schema(
				['r' => self::rule(['recipients' => [['kind' => 'role', 'role' => 'clerk'], ['kind' => 'role', 'role' => 'boss']]])],
				['authorization' => ['roles' => ['clerk' => [], 'manager' => [], 0 => []]]]
			),
			'recipient-role-bad-auth' => self::schema(
				['r' => self::rule(['recipients' => [['kind' => 'role', 'role' => 'boss']]])],
				['authorization' => ['roles' => 'clerk']]
			),
			'recipient-expression'   => self::schema(['r' => self::rule(['recipients' => [['kind' => 'expression'], ['kind' => 'expression', 'resolver' => 'X'], ['kind' => 'relation'], ['kind' => 'parties']]])]),
			'everything-wrong'       => self::schema(
				[
					'r' => [
						'trigger'         => ['type' => 'scheduled', 'intervalSec' => 1, 'filter' => 'x', 'dedupeFields' => []],
						'channels'        => ['fax', 'webhook', 'talk'],
						'optInChannels'   => ['fax'],
						'messageCategory' => 'spam',
						'originApp'       => '',
						'actions'         => 'x',
						'subject'         => '',
						'message'         => 5,
						'organisation'    => 5,
						'domain'          => '',
						'transports'      => 'x',
						'critical'        => 'yes',
						'recipients'      => [['kind' => 'robots'], ['kind' => 'role']],
					],
					'q' => self::rule(['trigger' => 'created']),
				]
			),
		];

		return $cases;
	}//end corpus()

	/**
	 * Data provider over the corpus.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function corpusProvider(): array {
		$rows = [];
		foreach (self::corpus() as $name => $schema) {
			$rows[$name] = [$name, $schema];
		}

		return $rows;
	}//end corpusProvider()

	/**
	 * The validator returns exactly the recorded errors for every corpus entry.
	 *
	 * @param string               $name   The corpus entry.
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return void
	 */
	#[DataProvider('corpusProvider')]
	public function testValidateMatchesGolden(string $name, array $schema): void {
		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], (new NotificationAnnotationValidator())->validate($schema));
	}//end testValidateMatchesGolden()

	/**
	 * Every golden entry belongs to a corpus entry, so a renamed case cannot hide.
	 *
	 * @return void
	 */
	public function testGoldenHasNoOrphans(): void {
		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);

		$this->assertSame(array_keys(self::corpus()), array_keys($golden));
	}//end testGoldenHasNoOrphans()
}//end class
