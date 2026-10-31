<?php

/**
 * Characterization tests for AnnotationNotificationDispatcher::dispatchWithSchema().
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

use DateTime;
use OCA\OpenRegister\Db\NotificationDispatchLog;
use OCA\OpenRegister\Db\NotificationDispatchLogMapper;
use OCA\OpenRegister\Db\NotificationHistory;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\NotificationSubscriptionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\QueuedNotification;
use OCA\OpenRegister\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\DuplicateDispatchException;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Service\Notification\NotificationCoalescer;
use OCA\OpenRegister\Service\Notification\NotificationDeliveryWindowService;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\Notification\OutboundTransportInterface;
use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifier;
use OCA\OpenRegister\Service\Notification\RuleReachRecorder;
use OCA\OpenRegister\Service\Notification\TalkSender;
use OCA\OpenRegister\Service\Party\PartyNotificationService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every call dispatchWithSchema() makes on its collaborators, in order,
 * for a corpus of rules that reaches each gate and channel. Expected calls live
 * in `fixtures/annotation-notification-dispatcher-golden.json`; set
 * `OR_GOLDEN_WRITE=1` to regenerate it.
 *
 * Recipient uids carry their behaviour in a prefix: `rl-` is rate limited,
 * `co-` coalesced, `off-` has the rule switched off, `em-` keeps only e-mail,
 * `q-` is inside quiet hours, `qx-` is inside quiet hours with a failing
 * queue, `nl-` reads Dutch.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher
 */
class AnnotationNotificationDispatcherCharacterizationTest extends TestCase {

	/**
	 * The calls recorded for the running case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Event ids seen so far, mapped to a stable placeholder.
	 *
	 * @var array<string, string>
	 */
	private array $eventIds = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/annotation-notification-dispatcher-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function corpus(): array {
		$users = static fn (string ...$uids): array => [['kind' => 'users', 'users' => $uids]];

		return [
			'no-annotation'     => ['rules' => null],
			'not-an-array-rule' => ['rules' => ['broken' => 'text']],
			'trigger-mismatch'  => ['rules' => ['r' => ['trigger' => ['type' => 'updated'], 'recipients' => $users('alice')]]],
			'basic'             => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'recipients' => $users('alice'), 'subject' => 'Hello {title}']], 'uids' => ['alice']],
			'default-subject'   => ['rules' => ['r' => ['trigger' => ['type' => 'created']]], 'uids' => ['alice'], 'trigger' => 'created'],
			'updated-trigger'   => ['rules' => ['r' => ['trigger' => ['type' => 'updated'], 'subject' => 's']], 'uids' => ['alice'], 'trigger' => 'updated', 'context' => ['old' => ['title' => 'x']]],
			'org-pinned-miss'   => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'organisation' => 'org-x']], 'uids' => ['alice']],
			'org-pinned-hit'    => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'organisation' => ['org-y', 'org-1']]], 'uids' => ['alice']],
			'org-pinned-odd'    => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'organisation' => 5]], 'uids' => ['alice']],
			'idempotent-new'    => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'idempotencyKey' => '${@self.uuid}-a']], 'uids' => ['alice']],
			'idempotent-dup'    => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'idempotencyKey' => 'dup']], 'uids' => ['alice']],
			'idempotent-broken' => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'idempotencyKey' => 'boom']], 'uids' => ['alice']],
			'idempotent-empty'  => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'idempotencyKey' => '']], 'uids' => ['alice']],
			'nobody'            => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'scope' => 'x']], 'uids' => [], 'unresolved' => [['kind' => 'groups', 'id' => 'gone', 'reason' => 'no such group'], ['kind' => 'role', 'id' => 'clerk', 'reason' => 'no groups']]],
			'parties-only'      => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['email'], 'recipients' => [['kind' => 'parties', 'role' => ' melder '], ['kind' => 'parties'], 'x'], 'message' => 'Body {title}', 'messageCategory' => ' Marketing ']], 'uids' => []],
			'parties-no-mail'   => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['nc-notification'], 'recipients' => [['kind' => 'parties']]]], 'uids' => []],
			'parties-no-uuid'   => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['email'], 'recipients' => [['kind' => 'parties']]]], 'uids' => [], 'noUuid' => true],
			'parties-nobody'    => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['email'], 'recipients' => [['kind' => 'parties']], 'messageCategory' => 7]], 'uids' => [], 'partiesNone' => true],
			'addresses'         => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['email', 'nc-notification'], 'recipients' => [['kind' => ReferencedAddressNotifier::KIND, 'field' => 'mail']], 'subject' => 'S', 'messageCategory' => 'unknown']], 'uids' => ['alice']],
			'all-channels'      => [
				'rules' => [
					'everything' => [
						'trigger'    => ['type' => 'created'],
						'channels'   => ['nc-notification', 'email', 'activity', 'web-push', 'webhook', 'talk'],
						'subject'    => ['nl' => 'Nieuw: {title}', 'en' => 'New: {title}'],
						'defaultLocale' => 'en',
						'message'    => ['nl' => 'Bericht', 'en' => 'Message'],
						'rateLimit'  => ['max' => 3, 'window' => 60],
						'coalesce'   => ['window' => 30],
						'domain'     => 'meldingen',
						'webhook'    => ['url' => 'https://hook.example.test/in', 'method' => 'put', 'headers' => ['X-A' => '1']],
						'talk'       => ['token' => 'tok'],
						'actions'    => [['label' => 'Open', 'target' => 'https://example.test/x']],
						'originApp'  => 'dossiq',
						'transports' => [
							['kind' => 'outbound', 'handler' => 'TransportOk', 'config' => ['a' => 1]],
							['kind' => 'outbound', 'handler' => 'TransportFails'],
							['kind' => 'outbound', 'handler' => 'TransportThrows'],
							['kind' => 'outbound', 'handler' => 'NotATransport'],
							['kind' => 'outbound', 'handler' => 'Missing'],
							['kind' => 'outbound', 'handler' => ''],
							['kind' => 'inbound', 'handler' => 'TransportOk'],
							'junk',
						],
					],
				],
				'uids' => ['alice', 'nl-bob', 'rl-carl', 'co-dave', 'off-eve', 'em-fay', 'q-gus', 'qx-hal'],
				'context' => ['action' => 'create'],
			],
			'broadcast-blocked' => [
				'rules' => [
					'r' => [
						'trigger'   => ['type' => 'created'],
						'channels'  => ['webhook', 'talk', 'web-push'],
						'rateLimit' => ['blockWebhook' => true],
						'coalesce'  => ['blockTalk' => true],
						'webhook'   => ['url' => 'https://hook.example.test/in'],
						'talk'      => ['token' => 'tok'],
					],
				],
				'uids' => ['alice'],
			],
			'webhook-variants'  => [
				'rules' => [
					'persistent' => ['trigger' => ['type' => 'created'], 'channels' => ['webhook'], 'webhook' => ['url' => 'https://hook.example.test/in', 'persistent' => true]],
					'malformed'  => ['trigger' => ['type' => 'created'], 'channels' => ['webhook', 'talk'], 'webhook' => ['url' => 'nope'], 'talk' => ['token' => '']],
					'failing'    => ['trigger' => ['type' => 'created'], 'channels' => ['webhook'], 'webhook' => ['url' => 'https://fail.example.test/in']],
					'none'       => ['trigger' => ['type' => 'created'], 'channels' => ['webhook', 'talk']],
				],
				'uids' => ['alice'],
			],
			'digest'            => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['nc-notification', 'webhook'], 'digest' => ['schedule' => 'daily']]], 'uids' => ['alice', 'q-gus']],
			'critical'          => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'critical' => true, 'digest' => ['schedule' => 'daily']]], 'uids' => ['q-gus']],
			'em-only-no-queue'  => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['nc-notification', 'webhook']]], 'uids' => ['em-fay', 'q-gus']],
			'no-preferences'    => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'channels' => ['nc-notification', 'email']]], 'uids' => ['off-eve', 'em-fay'], 'noPreferences' => true],
			'subscription'      => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'requiresSubscription' => true]], 'uids' => ['alice', 'bob']],
			'subscription-off'  => ['rules' => ['r' => ['trigger' => ['type' => 'created'], 'requiresSubscription' => 'yes']], 'uids' => ['alice']],
			'two-rules'         => ['rules' => ['first' => ['trigger' => ['type' => 'created'], 'subject' => 'one'], 'second' => ['trigger' => ['type' => 'created'], 'channels' => 'email', 'subject' => 'two']], 'uids' => ['alice']],
			'transition'        => ['rules' => ['r' => ['trigger' => ['type' => 'transition', 'to' => 'done']]], 'uids' => ['alice'], 'trigger' => 'transition', 'context' => ['to' => 'done', 'from' => 'open']],
		];
	}//end corpus()

	/**
	 * Data provider over the corpus names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::corpus()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * Each case makes exactly the recorded calls.
	 *
	 * @param string $name The corpus entry.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testDispatchMatchesGolden(string $name): void {
		$actual = $this->dispatchCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], json_decode(json_encode($actual), true));
	}//end testDispatchMatchesGolden()

	/**
	 * Dispatch one case and return the calls it made.
	 *
	 * @param array<string, mixed> $case The corpus entry.
	 *
	 * @return array<int, mixed>
	 */
	private function dispatchCase(array $case): array {
		$this->calls    = [];
		$this->eventIds = [];

		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('meldingen');
		if ($case['rules'] !== null) {
			$schema->setConfiguration(['x-openregister-notifications' => $case['rules']]);
		}

		$object = new ObjectEntity();
		if (($case['noUuid'] ?? false) !== true) {
			$object->setUuid('uuid-1');
		}

		$object->setSchema('9');
		$object->setRegister('4');
		$object->setOrganisation('org-1');
		$object->setName('Demo');
		$object->setObject(['title' => 'Demo', 'status' => 'done']);

		try {
			$this->dispatcher(case: $case)->dispatchWithSchema(
				$object,
				($case['trigger'] ?? 'created'),
				($case['context'] ?? []),
				$schema
			);
		} catch (\Throwable $e) {
			$this->calls[] = ['threw', get_class($e)];
		}

		return $this->calls;
	}//end dispatchCase()

	/**
	 * Record one call with normalised arguments.
	 *
	 * @param string            $what The collaborator and method.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return void
	 */
	private function record(string $what, array $args): void {
		$this->calls[] = [$what, $this->normalise(value: $args)];
	}//end record()

	/**
	 * Make a value JSON-stable: objects become a label, event ids a placeholder.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			unset($value['timestamp']);
			return array_map(fn ($v) => $this->normalise(value: $v), $value);
		}

		if ($value instanceof ObjectEntity) {
			return 'object:'.$value->getUuid();
		}

		if ($value instanceof Schema) {
			return 'schema:'.$value->getSlug();
		}

		if ($value instanceof QueuedNotification) {
			return [
				'schemaId'  => $value->getSchemaId(),
				'ruleKey'   => $value->getRuleKey(),
				'recipient' => $value->getRecipient(),
				'reason'    => $value->getReason(),
				'object'    => $value->getObjectUuid(),
				'payload'   => json_decode((string)$value->getPayload(), true),
				'due'       => $value->getDueAtHint()?->format('c'),
			];
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format('c');
		}

		if (is_object($value) === true) {
			return 'obj:'.get_class($value);
		}

		if (is_string($value) === true && preg_match('/^[0-9a-f]{32}$/', $value) === 1) {
			if (isset($this->eventIds[$value]) === false) {
				$this->eventIds[$value] = 'event#'.count($this->eventIds);
			}

			return $this->eventIds[$value];
		}

		return $value;
	}//end normalise()

	/**
	 * Build the dispatcher with recording collaborators.
	 *
	 * @param array<string, mixed> $case The corpus entry.
	 *
	 * @return AnnotationNotificationDispatcher
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	private function dispatcher(array $case): AnnotationNotificationDispatcher {
		$prefix = static fn (string $uid, string $p): bool => str_starts_with($uid, $p);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['exception'], $context['line'], $context['file']);
					$this->record(what: 'logger.'.$level, args: [(string)$message, $context]);
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default ?? 'http://localhost'
		);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid): string => str_starts_with($uid, 'nl-') ? 'nl_NL' : ''
		);

		$party = $this->createMock(PartyNotificationService::class);
		$party->method('notifyParties')->willReturnCallback(
			function (...$args) use ($case): array {
				$this->record(what: 'parties.notifyParties', args: $args);
				if (($case['partiesNone'] ?? false) === true) {
					return [];
				}

				return [['party' => 'p1', 'outcome' => 'dispatched'], ['party' => 'p2', 'outcome' => 'opted-out']];
			}
		);

		$addresses = $this->createMock(ReferencedAddressNotifier::class);
		$addresses->method('notify')->willReturnCallback(
			function (...$args): array {
				$this->record(what: 'addresses.notify', args: $args);
				return [
					['field' => 'mail', 'outcome' => EmailSender::OUTCOME_DISPATCHED],
					['field' => 'cc', 'outcome' => 'no-address'],
				];
			}
		);

		$transport = function (?string $failure, bool $throws): OutboundTransportInterface {
			$mock = $this->createMock(OutboundTransportInterface::class);
			$mock->method('send')->willReturnCallback(
				function (...$args) use ($failure, $throws): ?string {
					$this->record(what: 'transport.send', args: $args);
					if ($throws === true) {
						throw new RuntimeException('transport exploded');
					}

					return $failure;
				}
			);
			return $mock;
		};
		$services = [
			IConfig::class                   => $config,
			PartyNotificationService::class  => $party,
			ReferencedAddressNotifier::class => $addresses,
			'TransportOk'                    => $transport(null, false),
			'TransportFails'                 => $transport('remote said no', false),
			'TransportThrows'                => $transport(null, true),
			'NotATransport'                  => new \stdClass(),
		];

		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if ($id === 'Missing') {
					throw new RuntimeException('no such service');
				}

				return ($services[$id] ?? null);
			}
		);

		$activity = $this->createMock(IActivityManager::class);
		$activity->method('generateEvent')->willReturnCallback(
			function (): IEvent {
				$event = $this->createMock(IEvent::class);
				foreach (['setApp', 'setType', 'setAffectedUser', 'setSubject', 'setObject'] as $setter) {
					$event->method($setter)->willReturnCallback(
						function (...$args) use ($event, $setter): IEvent {
							$this->record(what: 'activity.'.$setter, args: $args);
							return $event;
						}
					);
				}

				$event->method('setTimestamp')->willReturn($event);
				return $event;
			}
		);
		$activity->method('publish')->willReturnCallback(
			function (): void {
				$this->record(what: 'activity.publish', args: []);
			}
		);

		$httpClient = $this->createMock(IClientService::class);
		$httpClient->method('newClient')->willReturnCallback(
			function (): IClient {
				$client = $this->createMock(IClient::class);
				$client->method('request')->willReturnCallback(
					function (string $method, string $url, array $options): IResponse {
						$this->record(what: 'http.request', args: [$method, $url, $options]);
						if (str_contains($url, 'fail.') === true) {
							throw new RuntimeException('connection refused');
						}

						return $this->createMock(IResponse::class);
					}
				);
				return $client;
			}
		);

		$rateLimiter = $this->createMock(RateLimiter::class);
		$rateLimiter->method('tryConsume')->willReturnCallback(
			function (string $ruleId, string $recipient, ?array $perRuleOverride = null) use ($prefix): bool {
				$this->record(what: 'rateLimiter.tryConsume', args: [$ruleId, $recipient, $perRuleOverride]);
				if ($recipient === '__webhook__') {
					return (($perRuleOverride['blockWebhook'] ?? false) !== true);
				}

				return $prefix($recipient, 'rl-') === false;
			}
		);

		$coalescer = $this->createMock(NotificationCoalescer::class);
		$coalescer->method('shouldDispatch')->willReturnCallback(
			function (string $ruleId, string $recipient, ?array $perRuleOverride) use ($prefix): bool {
				$this->record(what: 'coalescer.shouldDispatch', args: [$ruleId, $recipient, $perRuleOverride]);
				if ($recipient === '__talk__') {
					return (($perRuleOverride['blockTalk'] ?? false) !== true);
				}

				return $prefix($recipient, 'co-') === false;
			}
		);

		$history = $this->createMock(NotificationHistoryMapper::class);
		$history->method('record')->willReturnCallback(
			function (...$args): NotificationHistory {
				$this->record(what: 'history.record', args: $args);
				return new NotificationHistory();
			}
		);

		$subscriptions = $this->createMock(NotificationSubscriptionMapper::class);
		$subscriptions->method('findSubscribedUids')->willReturnCallback(
			function (...$args): array {
				$this->record(what: 'subscriptions.findSubscribedUids', args: $args);
				return ['alice'];
			}
		);

		$dispatchLog = $this->createMock(NotificationDispatchLogMapper::class);
		$dispatchLog->method('pruneExpired')->willReturnCallback(
			function (): int {
				$this->record(what: 'dispatchLog.pruneExpired', args: []);
				return 0;
			}
		);
		$dispatchLog->method('record')->willReturnCallback(
			function (string $notificationSlug, string $idempotencyKey): NotificationDispatchLog {
				$this->record(what: 'dispatchLog.record', args: [$notificationSlug, $idempotencyKey]);
				if ($idempotencyKey === 'dup') {
					throw new DuplicateDispatchException('duplicate');
				}

				if ($idempotencyKey === 'boom') {
					throw new RuntimeException('table missing');
				}

				return new NotificationDispatchLog();
			}
		);

		$preferences = null;
		if (($case['noPreferences'] ?? false) !== true) {
			$preferences = $this->createMock(NotificationPreferenceService::class);
			$preferences->method('resolveEffective')->willReturnCallback(
				function (array $schemaDefault, string $userId, string $schemaSlug, string $notificationKey, array $scopes = []) use ($prefix): array {
					$this->record(what: 'preferences.resolveEffective', args: [$userId, $schemaSlug, $notificationKey, $scopes]);
					if ($prefix($userId, 'off-') === true) {
						return ['enabled' => false, 'channels' => null];
					}

					if ($prefix($userId, 'em-') === true) {
						return ['enabled' => true, 'channels' => ['email', 'webhook']];
					}

					return ['enabled' => true, 'channels' => null];
				}
			);
		}

		$window = $this->createMock(NotificationDeliveryWindowService::class);
		$window->method('getForUser')->willReturnCallback(
			function (string $userId) use ($prefix): ?array {
				$this->record(what: 'window.getForUser', args: [$userId]);
				if ($prefix($userId, 'q-') === true || $prefix($userId, 'qx-') === true) {
					return ['enabled' => true, 'start' => '18:00', 'end' => '08:00'];
				}

				return null;
			}
		);
		$window->method('isInsideWindow')->willReturn(true);

		$queued = $this->createMock(QueuedNotificationMapper::class);
		$queued->method('insert')->willReturnCallback(
			function (QueuedNotification $entity): QueuedNotification {
				$this->record(what: 'queued.insert', args: [$entity]);
				if (str_starts_with((string)$entity->getRecipient(), 'qx-') === true) {
					throw new RuntimeException('queue full');
				}

				return $entity;
			}
		);

		$digest = $this->createMock(DigestScheduleEvaluator::class);
		$digest->method('isValidDigestSpec')->willReturnCallback(
			static fn (mixed $spec): bool => is_array($spec) === true && isset($spec['schedule']) === true
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-07-12T20:15:00+02:00'));

		$ncSender = $this->createMock(NcNotificationSender::class);
		$ncSender->method('send')->willReturnCallback(
			function (...$args): string {
				$this->record(what: 'nc.send', args: $args);
				return 'dispatched';
			}
		);
		$ncSender->method('enqueueWebPush')->willReturnCallback(
			function (...$args): void {
				$this->record(what: 'nc.enqueueWebPush', args: $args);
			}
		);

		$emailSender = $this->createMock(EmailSender::class);
		$emailSender->method('send')->willReturnCallback(
			function (...$args): string {
				$this->record(what: 'email.send', args: $args);
				return EmailSender::OUTCOME_DISPATCHED;
			}
		);

		$talkSender = $this->createMock(TalkSender::class);
		$talkSender->method('postAsBot')->willReturnCallback(
			function (...$args): string {
				$this->record(what: 'talk.postAsBot', args: $args);
				return 'dispatched';
			}
		);

		$resolver = $this->createMock(NotificationRecipientResolver::class);
		$resolver->method('resolveWithDiagnostics')->willReturnCallback(
			function (array $recipientsSpec, array $data, ?ObjectEntity $object = null, array $context = [], array $roleGroups = []) use ($case): array {
				$this->record(what: 'resolver.resolveWithDiagnostics', args: [$recipientsSpec, $data, $object, $context, $roleGroups]);
				return ['uids' => ($case['uids'] ?? []), 'unresolved' => ($case['unresolved'] ?? [])];
			}
		);
		$resolver->method('userExists')->willReturn(true);

		$reach = $this->createMock(RuleReachRecorder::class);
		$reach->method('reachedNobody')->willReturnCallback(
			function (...$args): void {
				$this->record(what: 'reach.reachedNobody', args: $args);
			}
		);

		return new AnnotationNotificationDispatcher(
			schemaMapper: $this->createMock(SchemaMapper::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $logger,
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			mailer: $this->createMock(IMailer::class),
			activityManager: $activity,
			httpClient: $httpClient,
			serverContainer: $container,
			rateLimiter: $rateLimiter,
			config: $config,
			historyMapper: $history,
			coalescer: $coalescer,
			subscriptionMapper: $subscriptions,
			dispatchLogMapper: $dispatchLog,
			preferenceService: $preferences,
			jobList: $this->createMock(\OCP\BackgroundJob\IJobList::class),
			deliveryWindowService: $window,
			queuedNotificationMapper: $queued,
			digestScheduleEvaluator: $digest,
			timeFactory: $time,
			ncSender: $ncSender,
			emailSender: $emailSender,
			talkSender: $talkSender,
			recipientResolver: $resolver,
			reachRecorder: $reach
		);
	}//end dispatcher()
}//end class
