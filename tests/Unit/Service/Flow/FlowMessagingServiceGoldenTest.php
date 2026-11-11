<?php

/**
 * Golden characterization test for the send pipeline of FlowMessagingService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\InAppNotice;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\PushRideAlong;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientSource;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Notifications\Service\Notification\PlaceholderValues;
use OCA\OpenRegister\Notifications\Service\Notification\RateLimiter;
use OCA\OpenRegister\Notifications\Service\Notification\TalkMessage;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSendException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Flow\FlowMessagingChannels;
use OCA\OpenRegister\Service\Flow\FlowMessagingOverrides;
use OCA\OpenRegister\Service\Flow\FlowMessagingPlatform;
use OCA\OpenRegister\Service\Flow\FlowMessagingRecipients;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutQuestion;

/**
 * Runs email(), notify() and talk() over a fixed
 * corpus and compares every collaborator call, the report and the outcome
 * with `fixtures/flow-messaging-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate it.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowMessagingService
 * @uses \OCA\OpenRegister\Service\Flow\FlowSend
 * @uses \OCA\OpenRegister\Service\Flow\FlowSendItem
 * @uses \OCA\OpenRegister\Service\Flow\FlowRecipientAddresses
 * @uses \OCA\OpenRegister\Service\Flow\FlowEmailAnnouncer
 * @uses \OCA\OpenRegister\Service\Flow\FlowStepReport
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Event\FlowEmailSentEvent
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class FlowMessagingServiceGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/flow-messaging-golden.json';
	}//end goldenPath()

	/**
	 * Every kind of recipient: users with each outcome, a group, unknowns and addresses.
	 *
	 * @return array<int, string>
	 */
	private static function recipients(): array {
		return [
			'alice', 'bob', 'carol', 'dave', 'eve', 'frank', 'team', 'ghost', '{{ owner }}',
			'ok@x.example', 'ks@x.example', 'bad@x.example', 'optout@x.example', 'auth@x.example', 'limited@x.example', 'nope@',
		];
	}//end recipients()

	/**
	 * The corpus: name => [method, config, items, channel enabled, bound, runAs].
	 *
	 * @return array<string, array{0: string, 1: array, 2: array, 3: bool, 4: int, 5: ?string}>
	 */
	private static function corpus(): array {
		$items = [
			[FlowItems::JSON => ['uuid' => 'o-1', 'name' => 'One', 'owner' => 'alice']],
			[FlowItems::JSON => ['id' => 'o-2', 'title' => 'Two', 'owner' => 'zed']],
		];
		$email = ['recipients' => self::recipients(), 'subject' => 'S {{ name }}', 'body' => 'B {{ name }}', 'externalRecipients' => 'any', 'messageCategory' => 'news'];
		$note = ['recipients' => self::recipients(), 'title' => 'T {{ name }}', 'message' => 'M {{ name }}'];
		$ghosts = ['recipients' => array_map(fn (int $n): string => "ghost$n", range(1, 7)), 'title' => 'T', 'message' => 'M'];
		$talkItems = array_map(fn (string $room): array => [FlowItems::JSON => ['room' => $room, 'name' => $room]], ['room-ok', 'room-ks', 'room-err', 'room-limit', '']);
		return [
			'email' => ['email', $email, $items, true, 50, 'actor'],
			'email-object-mode' => ['email', ['externalRecipients' => 'object'] + $email, $items, true, 50, 'actor'],
			'email-closed' => ['email', ['externalRecipients' => 'none'] + $email, $items, true, 50, 'actor'],
			'email-kill-switch' => ['email', $email, $items, false, 50, 'actor'],
			'email-over-bound' => ['email', $email, $items, true, 3, 'actor'],
			'email-no-actor' => ['email', $email, $items, true, 50, null],
			'email-disabled-actor' => ['email', $email, $items, true, 50, 'disabled'],
			'notification' => ['notify', $note, $items, true, 50, 'actor'],
			'notification-kill-switch' => ['notify', $note, $items, false, 50, 'actor'],
			'notification-unknowns' => ['notify', $ghosts, $items, true, 50, 'actor'],
			'notification-delivered-only' => ['notify', ['recipients' => ['alice']] + $note, $items, true, 50, 'actor'],
			'talk' => ['talk', ['conversation' => '{{ room }}', 'message' => 'Hi {{ name }}'], $talkItems, true, 50, 'actor'],
			'talk-literal' => ['talk', ['conversation' => 'room-ok', 'message' => 'Hi'], $talkItems, true, 50, 'actor'],
		];
	}//end corpus()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each case resolves, guards, sends and reports as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testSendMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testSendMatchesGolden()

	/**
	 * Run one case and return what it recorded.
	 *
	 * @param array $case [method, config, items, channel enabled, bound, runAs].
	 *
	 * @return array
	 */
	private function runCase(array $case): array {
		[$method, $config, $items, $enabled, $bound, $runAs] = $case;
		$this->calls = [];
		$service = $this->service(enabled: $enabled, bound: $bound);
		$handle = new FlowStepReport();
		$context = ['runId' => 'run-9', FlowStepReport::CONTEXT_KEY => $handle];
		if ($runAs !== null) {
			$context['runAs'] = $runAs;
		}

		try {
			$this->calls[] = ['returned' => $service->$method(new \OCA\OpenRegister\Service\Flow\MessageSend(config: $config, items: $items, context: $context, stepName: 'step-x'))];
		} catch (\Throwable $e) {
			$this->calls[] = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$this->calls[] = ['handle' => $handle->take()];
		return $this->calls;
	}//end runCase()

	/**
	 * Record one collaborator call.
	 *
	 * @param string $call The collaborator and method.
	 * @param array  $args The arguments that matter.
	 *
	 * @return void
	 */
	private function record(string $call, array $args): void {
		$this->calls[] = [$call => $args];
	}//end record()

	/**
	 * The service under test, its collaborators recording into $this->calls.
	 *
	 * @param bool $enabled Whether the channel is enabled.
	 * @param int  $bound   The recipient bound.
	 *
	 * @return FlowMessagingService
	 */
	private function service(bool $enabled, int $bound): FlowMessagingService {
		$policy = $this->createMock(NotificationChannelPolicy::class);
		$policy->method('isChannelEnabled')->willReturn($enabled);
		$templating = $this->createMock(NotificationTemplating::class);
		$templating->method('render')->willReturnCallback(
			fn (string $template, PlaceholderValues $values): string => (string)preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string)($values->data[$m[1]] ?? ''), $template)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn($bound);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(fn ($message) => $this->record(call: "log.$level", args: [$message]));
		}

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(fn ($event) => $this->record(call: 'event', args: [get_class($event), $event->getRecipient(), $event->getChannelKind(), $event->getSubject(), $event->getBody(), $event->getStepName()]));

		return new FlowMessagingService(
			channels: new FlowMessagingChannels(
				channelPolicy: $policy,
				ncSender: $this->ncSender(),
				emailSender: $this->emailSender(),
				talkSender: $this->talkSender()
			),
			recipients: new FlowMessagingRecipients(
				recipientResolver: $this->resolver(),
				preferences: $this->preferences(),
				rateLimiter: $this->rateLimiter(),
				userManager: $this->userManager()
			),
			platform: new FlowMessagingPlatform(
				templating: $templating,
				appConfig: $appConfig,
				logger: $logger,
				eventDispatcher: $dispatcher
			),
			overrides: new FlowMessagingOverrides(
				runContext: null,
				optOut: $this->optOut()
			)
		);
	}//end service()

	/**
	 * Users alice to frank, plus `actor` and `disabled`; group `team` is alice and bob.
	 *
	 * @return NotificationRecipientResolver
	 */
	private function resolver(): NotificationRecipientResolver {
		$users = ['alice', 'bob', 'carol', 'dave', 'eve', 'frank'];
		$resolver = $this->createMock(NotificationRecipientResolver::class);
		$resolver->method('userExists')->willReturnCallback(fn (string $uid): bool => in_array($uid, $users, true));
		$resolver->method('groupExists')->willReturnCallback(fn (string $gid): bool => $gid === 'team');
		$resolver->method('resolveFromSource')->willReturnCallback(function (array $recipientsSpec, RecipientSource $source) use ($users): array {
			$data = $source->data;
			if (($recipientsSpec[0]['kind'] ?? '') === 'groups') {
				return ['uids' => ['alice', 'bob'], 'unresolved' => []];
			}

			return ['uids' => array_values(array_intersect((array)array_values($data)[0], $users)), 'unresolved' => []];
		});
		$resolver->method('extractUidsFromRelation')->willReturnCallback(fn ($value): array => array_values(array_filter((array)$value, 'is_string')));
		return $resolver;
	}//end resolver()

	/**
	 * Notification sender: alice and frank dispatched, bob kill-switched, the rest failed.
	 *
	 * @return NcNotificationSender
	 */
	private function ncSender(): NcNotificationSender {
		$sender = $this->createMock(NcNotificationSender::class);
		$sender->method('notify')->willReturnCallback(function (InAppNotice $notice) {
			$this->record(call: 'nc.send', args: [$notice->uid, $notice->object->getUuid(), $notice->object->getName(), $notice->rule->name, $notice->content->subject, $notice->content->message]);
			return self::outcome(recipient: $notice->uid);
		});
		$sender->method('enqueuePush')->willReturnCallback(function (PushRideAlong $push): void {
			$this->record(call: 'nc.webPush', args: [$push->recipients, $push->rule->name, $push->content->subject, $push->content->message, $push->object->getUuid()]);
		});
		return $sender;
	}//end ncSender()

	/**
	 * Email sender: the same outcomes for users, and per address.
	 *
	 * @return EmailSender
	 */
	private function emailSender(): EmailSender {
		$sender = $this->createMock(EmailSender::class);
		$sender->method('send')->willReturnCallback(function (string $uid, string $subject, string $body): string {
			$this->record(call: 'email.send', args: [$uid, $subject, $body]);
			return self::outcome(recipient: $uid);
		});
		$sender->method('sendMailTo')->willReturnCallback(function (\OCA\OpenRegister\Notifications\Service\Notification\MailAddressee $to, \OCA\OpenRegister\Notifications\Service\Notification\MailContent $mail): string {
			$this->record(call: 'email.sendToAddress', args: [$to->address, $to->displayName, $mail->subject, $mail->body]);
			return self::outcome(recipient: $to->address);
		});
		return $sender;
	}//end emailSender()

	/**
	 * The outcome a sender gives a recipient.
	 *
	 * @param string $recipient The uid or address.
	 *
	 * @return string
	 */
	private static function outcome(string $recipient): string {
		if (in_array($recipient, ['alice', 'frank', 'ok@x.example'], true) === true) {
			return 'dispatched';
		}

		if (in_array($recipient, ['bob', 'ks@x.example'], true) === true) {
			return 'kill-switch';
		}

		return 'failed';
	}//end outcome()

	/**
	 * Talk sender: room-ok posts, room-ks is kill-switched, room-err throws.
	 *
	 * @return TalkSender
	 */
	private function talkSender(): TalkSender {
		$sender = $this->createMock(TalkSender::class);
		$sender->method('postAsUser')->willReturnCallback(function (TalkMessage $talkMessage, string $actorUid): string {
			$token = $talkMessage->token;
			$this->record(call: 'talk.post', args: [$token, $talkMessage->message, $actorUid]);
			if ($token === 'room-err') {
				throw new TalkSendException('not a participant');
			}

			return $token === 'room-ks' ? TalkSender::OUTCOME_KILL_SWITCH : 'posted';
		});
		return $sender;
	}//end talkSender()

	/**
	 * Rate limiter: eve, limited@x.example and room-limit are over budget.
	 *
	 * @return RateLimiter
	 */
	private function rateLimiter(): RateLimiter {
		$limiter = $this->createMock(RateLimiter::class);
		$limiter->method('tryConsume')->willReturnCallback(function (string $ruleId, string $recipient): bool {
			$this->record(call: 'rate', args: [$ruleId, $recipient]);
			return in_array($recipient, ['eve', 'limited@x.example', '__talk__:room-limit'], true) === false;
		});
		return $limiter;
	}//end rateLimiter()

	/**
	 * Preferences: dave turned flows off, carol only takes another channel.
	 *
	 * @return NotificationPreferenceService
	 */
	private function preferences(): NotificationPreferenceService {
		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->method('resolveEffective')->willReturnCallback(fn (array $schemaDefault, string $userId): array => match ($userId) {
			'dave' => ['enabled' => false, 'channels' => null],
			'carol' => ['enabled' => true, 'channels' => ['talk']],
			default => ['enabled' => true, 'channels' => null],
		});
		return $preferences;
	}//end preferences()

	/**
	 * Users: `actor` enabled, `disabled` disabled.
	 *
	 * @return IUserManager
	 */
	private function userManager(): IUserManager {
		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if (in_array($uid, ['actor', 'disabled'], true) === false) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('isEnabled')->willReturn($uid === 'actor');
			return $user;
		});
		return $manager;
	}//end userManager()

	/**
	 * Opt-out: optout@ refused, auth@ authority unavailable, the rest allowed with a link.
	 *
	 * @return OptOutAuthority
	 */
	private function optOut(): OptOutAuthority {
		$optOut = $this->createMock(OptOutAuthority::class);
		$optOut->method('askQuestion')->willReturnCallback(function (OptOutQuestion $question): array {
			$this->record(call: 'optOut.ask', args: [$question->channel, $question->category, $question->addresses, $question->correlationId]);
			$decisions = [];
			foreach ($question->addresses as $address) {
				$decisions[$address] = match ($address) {
					'optout@x.example' => ['send' => false, 'code' => 'opted-out', 'unsubscribe' => null],
					'auth@x.example' => ['send' => false, 'code' => OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE, 'unsubscribe' => null],
					default => ['send' => true, 'code' => 'allowed', 'unsubscribe' => ['url' => 'https://u/'.$address]],
				};
			}

			return $decisions;
		});
		$optOut->method('decisionFor')->willReturnCallback(fn (array $decisions, string $address): array => ($decisions[$address] ?? ['send' => false, 'code' => 'missing', 'unsubscribe' => null]));
		$optOut->method('withLink')->willReturnCallback(fn (string $body, ?array $unsubscribe): string => $body.' ['.($unsubscribe['url'] ?? '-').']');
		return $optOut;
	}//end optOut()
}//end class
