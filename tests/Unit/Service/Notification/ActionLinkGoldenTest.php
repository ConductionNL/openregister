<?php

/**
 * Golden of how a rule's declared actions become links.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ActionTargetContext;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\AppIntegrations;
use OCA\OpenRegister\Notifications\Service\Notification\ChannelSet;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryLedger;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchGates;
use OCA\OpenRegister\Notifications\Service\Notification\DispatchThrottle;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\MessageRendering;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\RuleAudience;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ActionLinkResolver
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ActionTargetContext
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\AppRouteBase
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ObjectDetailLinks
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\TaskActionLinks
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\LinkableUrl
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\AppIntegrations
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\ChannelSet
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\DeliveryHold
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\DeliveryLedger
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\DispatchGates
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\DispatchThrottle
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\EmailSender
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\MessageRendering
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\RuleAudience
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\RuleReachRecorder
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\TalkSender
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Service\Task\TaskStateRules
 * @uses \OCA\OpenRegister\Service\WriteCause
 */
final class ActionLinkGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/action-link-golden.json';

	private const BASE = 'https://cloud.example.org';

	private array $lookups = [];

	private array $debug = [];

	/**
	 * Name => [actions, object ids, object data].
	 *
	 * @return array<string, array{0: mixed, 1: array{0: ?string, 1: ?string, 2: ?string}, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$own = ['1', '2', 'uuid-o'];
		$detail = [['label' => ['nl' => 'Open'], 'target' => ['kind' => 'object-detail']]];
		$relation = static fn (string $field): array => [['target' => ['kind' => 'object-detail', 'object' => ['kind' => 'relation', 'field' => $field]]]];
		$verb = static fn (array $extra): array => [['target' => ['kind' => 'task-verb', 'verb' => 'complete'] + $extra]];
		$route = static fn (array $target): array => [['target' => ['kind' => 'route'] + $target]];

		return [
			'none declared' => [null, $own, []],
			'not a list' => ['open', $own, []],
			'empty list' => [[], $own, []],
			'entry not an array' => [['x', 3], $own, []],
			'no target' => [[['label' => ['nl' => 'x']]], $own, []],
			'target not an array' => [[['target' => 'x']], $own, []],
			'unknown kind' => [[['target' => ['kind' => 'other']]], $own, []],
			'label not a map' => [[['label' => 'Open', 'primary' => 1, 'target' => ['kind' => 'object-detail']]], $own, []],
			'two actions, one dropped' => [[['primary' => true, 'target' => ['kind' => 'object-detail']], ['target' => ['kind' => 'url', 'href' => 'nope']]], $own, []],
			'detail fallback' => [$detail, $own, []],
			'detail encoded ids' => [$detail, ['a b', 'c/d', 'e f'], []],
			'detail no register' => [$detail, [null, '2', 'uuid-o'], []],
			'detail no schema' => [$detail, ['1', '', 'uuid-o'], []],
			'detail no uuid' => [$detail, ['1', '2', null], []],
			'detail registered absolute' => [$detail, ['20', '2', 'uuid-o'], []],
			'detail registered no path' => [$detail, ['24', '2', 'uuid-o'], []],
			'detail registered relative' => [$detail, ['21', '2', 'uuid-o'], []],
			'detail registered empty' => [$detail, ['22', '2', 'uuid-o'], []],
			'detail registry skips slugs' => [$detail, ['20', 'slug', 'uuid-o'], []],
			'relation string' => [$relation('client'), $own, ['client' => 'readable']],
			'relation by id' => [$relation('client'), $own, ['client' => ['id' => 'readable', 'uuid' => 'other']]],
			'relation by uuid' => [$relation('client'), $own, ['client' => ['uuid' => 'readable']]],
			'relation first of list' => [$relation('client'), $own, ['client' => ['readable', 'other']]],
			'relation empty string' => [$relation('client'), $own, ['client' => '']],
			'relation number' => [$relation('client'), $own, ['client' => 5]],
			'relation list of numbers' => [$relation('client'), $own, ['client' => [5]]],
			'relation unset' => [$relation('client'), $own, []],
			'relation no field' => [$relation(''), $own, ['' => 'readable']],
			'relation denied' => [$relation('client'), $own, ['client' => 'denied']],
			'relation gone' => [$relation('client'), $own, ['client' => 'gone']],
			'relation other object kind' => [[['target' => ['kind' => 'object-detail', 'object' => ['kind' => 'self']]]], $own, []],
			'route origin app' => [$route(['route' => '/clients/{{id}}']), $own, ['id' => 'a b']],
			'route named app' => [$route(['app' => 'pipelinq', 'route' => 'x/{{ id }}/{{list}}/{{none}}']), $own, ['id' => 7, 'list' => [1]]],
			'route app without dashboard' => [$route(['app' => 'missing', 'route' => '/x']), $own, []],
			'route empty' => [$route(['route' => '']), $own, []],
			'url web' => [[['target' => ['kind' => 'url', 'href' => 'https://example.test/a?b=c']]], $own, []],
			'url no href' => [[['target' => ['kind' => 'url']]], $own, []],
			'verb from object' => [$verb([]), $own, []],
			'verb from payload with outcome' => [$verb(['outcome' => 'approved x']), $own, ['taskUuid' => 'task-1']],
			'verb empty outcome' => [$verb(['outcome' => '']), $own, []],
			'verb rejecting outcome' => [$verb(['outcome' => 'rejected']), $own, []],
			'verb numeric outcome' => [$verb(['outcome' => 3]), $own, []],
			'verb no uuid' => [$verb([]), ['1', '2', ''], []],
			'verb bad name' => [[['target' => ['kind' => 'task-verb', 'verb' => 'Bad1']]], $own, []],
			'verb no name' => [[['target' => ['kind' => 'task-verb']]], $own, []],
			'verb route missing' => [[['target' => ['kind' => 'task-verb', 'verb' => 'broken']]], $own, []],
		];
	}//end cases()

	public function testActionsMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$actions, $ids, $data]) {
			$this->lookups = [];
			$this->debug = [];
			$spec = ['actions' => $actions];
			if ($actions === null) {
				$spec = [];
			}

			$links = $this->resolved(spec: $spec, object: self::objectWith($ids), data: $data);
			$actual[$name] = ['links' => $links, 'lookups' => $this->lookups, 'debug' => $this->debug];
		}

		$actual['no collaborators'] = $this->withoutCollaborators();

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertNotEmpty($actual['relation string']['links']);
		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));
	}//end testActionsMatchGolden()

	/**
	 * What each target gives when no URL generator, object service or registry was wired.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutCollaborators(): array {
		$object = self::objectWith(['1', '2', 'uuid-o']);
		$spec = ['actions' => [
			['target' => ['kind' => 'object-detail']],
			['target' => ['kind' => 'route', 'app' => 'pipelinq', 'route' => '/x']],
			['target' => ['kind' => 'task-verb', 'verb' => 'complete']],
			['target' => ['kind' => 'object-detail', 'object' => ['kind' => 'relation', 'field' => 'client']]],
		]];

		return $this->resolved(spec: $spec, object: $object, data: ['client' => 'readable'], wired: false);
	}//end withoutCollaborators()

	/**
	 * The links of a spec.
	 */
	private function resolved(array $spec, ObjectEntity $object, array $data, bool $wired = true): array {
		$method = new ReflectionMethod(AnnotationNotificationDispatcher::class, 'actionLinks');

		return $method->invoke($this->makeDispatcher(wired: $wired))->resolve(
			spec: $spec,
			context: new ActionTargetContext(object: $object, data: $data, originApp: 'origin')
		);
	}//end resolved()

	/**
	 * An object with a register, schema and uuid.
	 *
	 * @param array{0: ?string, 1: ?string, 2: ?string} $ids The ids.
	 */
	private static function objectWith(array $ids): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister($ids[0]);
		$object->setSchema($ids[1]);
		$object->setUuid($ids[2]);

		return $object;
	}//end objectWith()

	private function urlGenerator(): IURLGenerator {
		$generator = $this->createMock(IURLGenerator::class);
		$generator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route, array $args = []): string {
				if (in_array($route, ['missing.dashboard.page', 'openregister.task.broken'], true) === true) {
					throw new RuntimeException('no route');
				}

				return self::BASE . '/route/' . $route . '/' . implode('/', $args) . '/';
			}
		);
		$generator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => self::BASE . $url);

		return $generator;
	}//end urlGenerator()

	private function objectService(): ObjectOperationsInterface {
		$service = $this->createMock(ObjectOperationsInterface::class);
		$service->method('find')->willReturnCallback(
			function (mixed $id, mixed ...$rest): ?ObjectEntity {
				$this->lookups[] = [$id, $rest];
				return match ($id) {
					'readable' => self::objectWith(['5', '6', 'uuid-r']),
					'gone' => null,
					default => throw new RuntimeException('denied ' . $id),
				};
			}
		);

		return $service;
	}//end objectService()

	private function registry(): DeepLinkRegistryService {
		$registry = $this->createMock(DeepLinkRegistryService::class);
		$registry->method('resolveUrl')->willReturnCallback(
			static fn (int $registerId, int $schemaId, array $objectData): ?string => match ($registerId) {
				20 => 'https://other.example.org:8080/apps/pipelinq/' . $schemaId . '/' . $objectData['uuid'] . '?a=1#top',
				21 => '/apps/relative/' . $objectData['id'],
				22 => '',
				24 => 'https://other.example.org',
				default => null,
			}
		);

		return $registry;
	}//end registry()

	private function makeDispatcher(bool $wired): AnnotationNotificationDispatcher {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->debug[] = (string) $message;
			}
		);

		return new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: new NcNotificationSender(
					notificationManager: $this->createMock(INotificationManager::class),
					logger: $logger,
					userManager: $this->createMock(IUserManager::class)
				),
				emailSender: new EmailSender(
					userManager: $this->createMock(IUserManager::class),
					mailer: $this->createMock(IMailer::class),
					logger: $logger
				),
				talkSender: new TalkSender(
					httpClient: $this->createMock(IClientService::class),
					logger: $logger
				),
				activityManager: $this->createMock(IActivityManager::class)
			),
			gates: new DispatchGates(
				throttle: new DispatchThrottle(),
				deliveryHold: new DeliveryHold()
			),
			integrations: new AppIntegrations(
				httpClient: $this->createMock(IClientService::class),
				serverContainer: $this->createMock(IServerContainer::class)
			),
			ledger: new DeliveryLedger(logger: $logger),
			audience: new RuleAudience(
				schemaMapper: $this->createMock(SchemaMapper::class),
				recipientResolver: new NotificationRecipientResolver(
					userManager: $this->createMock(IUserManager::class),
					groupManager: $this->createMock(IGroupManager::class),
					logger: $logger,
					serverContainer: $this->createMock(IServerContainer::class)
				)
			),
			rendering: new MessageRendering(
				templating: new NotificationTemplating(logger: $logger),
				urlGenerator: $wired === true ? $this->urlGenerator() : null,
				objectService: $wired === true ? $this->objectService() : null,
				deepLinkRegistry: $wired === true ? $this->registry() : null
			)
		);
	}//end makeDispatcher()
}//end class
