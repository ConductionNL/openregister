<?php

/**
 * Characterisation of the dispatcher's private decision helpers.
 *
 * The golden corpus drives whole dispatches; these cases pin the branches of
 * the small helpers it does not reach on its own: the preference scopes, the
 * organisation pin, the subscription filter, the role map, the recipient
 * locale, the origin app and the merged summary of a queued group.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
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
use OCA\OpenRegister\Notifications\Service\Notification\RuleFiring;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * AnnotationNotificationDispatcherHelperBranchesTest.
 */
class AnnotationNotificationDispatcherHelperBranchesTest extends TestCase {

	/** @var array<int, string> */
	private array $debug = [];

	/**
	 * A dispatcher over the given user config and register mapper.
	 */
	private function dispatcher(?IConfig $config = null, ?RegisterMapper $registers = null): AnnotationNotificationDispatcher {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function (string $m): void {
			$this->debug[] = $m;
		});

		return new AnnotationNotificationDispatcher(
			channels: new ChannelSet(
				ncSender: $this->createMock(NcNotificationSender::class),
				emailSender: $this->createMock(EmailSender::class),
				talkSender: new TalkSender(
					httpClient: $this->createMock(IClientService::class),
					logger: $this->createMock(LoggerInterface::class)
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
			ledger: new DeliveryLedger(
				logger: $logger,
				historyMapper: $this->createMock(NotificationHistoryMapper::class)
			),
			audience: new RuleAudience(
				schemaMapper: $this->createMock(SchemaMapper::class),
				recipientResolver: new NotificationRecipientResolver(
					userManager: $this->createMock(IUserManager::class),
					groupManager: $this->createMock(IGroupManager::class),
					logger: $this->createMock(LoggerInterface::class),
					serverContainer: $this->createMock(IServerContainer::class)
				),
				registerMapper: $registers,
				config: $config
			),
			rendering: new MessageRendering(
				templating: new NotificationTemplating(logger: $this->createMock(LoggerInterface::class))
			)
		);
	}

	/**
	 * Call one private helper.
	 *
	 * @param array<string, mixed> $args Named arguments.
	 */
	private function call(AnnotationNotificationDispatcher $dispatcher, string $method, array $args): mixed {
		$reflection = new ReflectionMethod(AnnotationNotificationDispatcher::class, $method);

		return $reflection->invokeArgs($dispatcher, $args);
	}

	/**
	 * @param array<string, mixed> $spec The rule.
	 */
	private function firing(array $spec, ObjectEntity $object, Schema $schema): RuleFiring {
		return new RuleFiring('rule-1', $spec, $object, $schema, [], 'object_created');
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: int|string|null, 3: array<int, string>}>
	 */
	public static function scopeProvider(): array {
		return [
			'everything' => [['domain' => 'meldingen'], 'zaken', 'reg-1', ['schema:zaken', 'domain:meldingen', 'register:reg-1']],
			'an integer register' => [[], 'zaken', 7, ['schema:zaken', 'register:7']],
			'an empty domain and register' => [['domain' => ''], 'zaken', '', ['schema:zaken']],
			'a non-string domain' => [['domain' => 5], null, null, ['schema:12']],
		];
	}

	/**
	 * @dataProvider scopeProvider
	 *
	 * @param array<string, mixed> $spec     The rule.
	 * @param array<int, string>   $expected The scopes, narrowest first.
	 */
	public function testDispatchScopesAreNarrowestFirst(array $spec, ?string $slug, int|string|null $register, array $expected): void {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug($slug);
		$object = new ObjectEntity();
		if ($register !== null) {
			$object->setRegister((string)$register);
		}

		$scopes = $this->call($this->dispatcher(), 'dispatchScopes', ['firing' => $this->firing($spec, $object, $schema)]);

		$this->assertSame($expected, $scopes);
	}

	public function testAnEmptySchemaSlugAddsNoSchemaScope(): void {
		$schema = new Schema();
		$schema->setSlug('');
		$object = new ObjectEntity();

		$scopes = $this->call($this->dispatcher(), 'dispatchScopes', ['firing' => $this->firing([], $object, $schema)]);

		$this->assertSame([], $scopes);
	}

	/**
	 * @return array<string, array{0: mixed, 1: string|null, 2: bool}>
	 */
	public static function organisationProvider(): array {
		return [
			'no pin' => [null, 'org-1', true],
			'a matching string' => ['org-1', 'org-1', true],
			'a different string' => ['org-2', 'org-1', false],
			'an empty string pin on an untenanted object' => ['', null, true],
			'a string pin on an untenanted object' => ['org-1', null, false],
			'an any-of list that matches' => [['org-9', 'org-1'], 'org-1', true],
			'an any-of list that misses' => [['org-9'], 'org-1', false],
			'non-strings in the list never match' => [[1, null, ['org-1']], 'org-1', false],
			'an empty list' => [[], 'org-1', false],
			'a malformed pin' => [5, 'org-1', false],
			'a boolean pin' => [true, 'org-1', false],
		];
	}

	/**
	 * @dataProvider organisationProvider
	 */
	public function testTheOrganisationPinAllowsOnlyAnExactMatch(mixed $pinned, ?string $objectOrg, bool $allowed): void {
		$object = new ObjectEntity();
		$object->setOrganisation($objectOrg);
		$spec = [];
		if ($pinned !== null) {
			$spec['organisation'] = $pinned;
		}

		$this->assertSame($allowed, $this->call($this->dispatcher(), 'organisationGateAllows', ['spec' => $spec, 'object' => $object]));
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function subscriptionProvider(): array {
		return [
			'a subscribed uid' => ['alice', true],
			'an unsubscribed uid' => ['bob', false],
			'a subscribed user entry' => [['kind' => 'user', 'uid' => 'alice'], true],
			'an unsubscribed user entry' => [['kind' => 'user', 'uid' => 'bob'], false],
			'a user entry without a uid' => [['kind' => 'user'], true],
			'a user entry with an empty uid' => [['kind' => 'user', 'uid' => ''], true],
			'a user entry with a non-string uid' => [['kind' => 'user', 'uid' => 5], true],
			'an e-mail entry' => [['kind' => 'email', 'uid' => 'bob'], true],
			'a non-array, non-string' => [42, true],
		];
	}

	/**
	 * @dataProvider subscriptionProvider
	 */
	public function testOnlyUsersAreFilteredBySubscription(mixed $recipient, bool $kept): void {
		$this->assertSame(
			$kept,
			$this->call($this->dispatcher(), 'keepsSubscribedRecipient', ['recipient' => $recipient, 'subscribedSet' => ['alice' => 1]])
		);
	}

	public function testTheRoleMapKeepsNamedRolesAndStringGroups(): void {
		$schema = new Schema();
		$schema->setAuthorization(['roles' => ['clerk' => ['g1', 2, 'g2'], '' => ['g3'], 'boss' => 'g4', 7 => ['g5']]]);

		$this->assertSame(
			['clerk' => ['g1', 'g2'], 'boss' => ['g4']],
			$this->call($this->dispatcher(), 'roleGroupsFor', ['schema' => $schema])
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function noRolesProvider(): array {
		return [
			'no authorization' => [null],
			'no roles' => [['read' => ['g1']]],
			'roles that are not a map' => [['roles' => 'clerk']],
		];
	}

	/**
	 * @dataProvider noRolesProvider
	 */
	public function testWithoutARoleMapThereAreNoRoleGroups(mixed $authorization): void {
		$schema = new Schema();
		$schema->setAuthorization($authorization);

		$this->assertSame([], $this->call($this->dispatcher(), 'roleGroupsFor', ['schema' => $schema]));
	}

	public function testAnUnreadableAuthorizationGivesNoRoleGroups(): void {
		$schema = new class extends Schema {
			public function getAuthorization(): ?array {
				throw new RuntimeException('bad json');
			}
		};

		$this->assertSame([], $this->call($this->dispatcher(), 'roleGroupsFor', ['schema' => $schema]));
	}

	/**
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function localeProvider(): array {
		return [
			'a bare language' => ['nl', 'nl'],
			'a language with a region' => ['en_GB', 'en'],
			'an upper-case language' => ['DE_de', 'de'],
			'no preference' => ['', null],
		];
	}

	/**
	 * @dataProvider localeProvider
	 */
	public function testTheUserLocaleIsTheBareLanguage(mixed $stored, ?string $expected): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('alice', 'core', 'lang', '')->willReturn($stored);

		$this->assertSame($expected, $this->call($this->dispatcher(config: $config), 'resolveUserLocale', ['uid' => 'alice']));
	}

	public function testWithoutConfigOrWhenItFailsTheLocaleIsUnknown(): void {
		$this->assertNull($this->call($this->dispatcher(), 'resolveUserLocale', ['uid' => 'alice']));

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willThrowException(new RuntimeException('db'));
		$this->assertNull($this->call($this->dispatcher(config: $config), 'resolveUserLocale', ['uid' => 'alice']));
	}

	public function testADeclaredOriginAppWins(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->expects($this->never())->method('find');
		$object = new ObjectEntity();
		$object->setRegister('5');

		$this->assertSame(
			'dossiq',
			$this->call($this->dispatcher(registers: $registers), 'resolveOriginApp', ['spec' => ['originApp' => 'dossiq'], 'object' => $object])
		);
	}

	/**
	 * @return array<string, array{0: mixed, 1: string|null, 2: string}>
	 */
	public static function originProvider(): array {
		return [
			'the owning app of the register' => [null, 'procest', 'procest'],
			'an empty declaration falls through' => ['', 'procest', 'procest'],
			'a register without an app' => [null, null, 'openregister'],
			'a register with an empty app' => [5, '', 'openregister'],
		];
	}

	/**
	 * @dataProvider originProvider
	 */
	public function testTheOriginAppFallsBackToTheRegistersApp(mixed $declared, ?string $application, string $expected): void {
		$register = new Register();
		$register->setApplication($application);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$object = new ObjectEntity();
		$object->setRegister('5');

		$this->assertSame(
			$expected,
			$this->call($this->dispatcher(registers: $registers), 'resolveOriginApp', ['spec' => ['originApp' => $declared], 'object' => $object])
		);
	}

	public function testAFailedRegisterLookupIsLoggedAndFallsBack(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willThrowException(new RuntimeException('gone'));
		$object = new ObjectEntity();
		$object->setRegister('5');

		$this->assertSame('openregister', $this->call($this->dispatcher(registers: $registers), 'resolveOriginApp', ['spec' => [], 'object' => $object]));
		$this->assertSame(['[AnnotationNotificationDispatcher] originApp register lookup failed: gone'], $this->debug);
	}

	public function testWithoutARegisterTheOriginIsOpenRegister(): void {
		$this->assertSame('openregister', $this->call($this->dispatcher(), 'resolveOriginApp', ['spec' => [], 'object' => new ObjectEntity()]));
	}

	/**
	 * @param array<string, mixed> $payload The stored payload.
	 */
	private function row(array $payload): QueuedNotification {
		$row = new QueuedNotification();
		$row->setPayload((string)json_encode($payload));

		return $row;
	}

	public function testOneQueuedRowFlushesVerbatim(): void {
		$summary = $this->call($this->dispatcher(), 'buildQueuedSummary', ['rows' => [$this->row(['subject' => 'S', 'message' => 'M'])]]);

		$this->assertSame(['S', 'M'], $summary);
	}

	public function testABareQueuedRowFlushesAsEmptyText(): void {
		$summary = $this->call($this->dispatcher(), 'buildQueuedSummary', ['rows' => [$this->row([])]]);

		$this->assertSame(['', ''], $summary);
	}

	public function testSeveralQueuedRowsMergeIntoACountedBreakdown(): void {
		$rows = [
			$this->row(['action' => 'created', 'subject' => 'a']),
			$this->row(['action' => 'updated', 'subject' => '']),
			$this->row(['action' => 'created', 'subject' => 'c']),
			$this->row(['action' => 'archived', 'subject' => 'd']),
			$this->row(['subject' => 'e']),
		];

		$summary = $this->call($this->dispatcher(), 'buildQueuedSummary', ['rows' => $rows]);

		$this->assertSame(['5 meldingen (2 nieuw, 1 gewijzigd, 1 archived, 1 other)', 'a; c; d; e'], $summary);
	}

	public function testMoreThanTenQueuedRowsListTenTitlesAndCountTheRest(): void {
		$rows = [];
		for ($i = 1; $i <= 12; $i++) {
			$rows[] = $this->row(['action' => 'updated', 'subject' => 't' . $i]);
		}

		$summary = $this->call($this->dispatcher(), 'buildQueuedSummary', ['rows' => $rows]);

		$this->assertSame(
			['12 meldingen (12 gewijzigd)', 't1; t2; t3; t4; t5; t6; t7; t8; t9; t10 ... en 2 meer'],
			$summary
		);
	}
}
