<?php

/**
 * Shared set-up for the example-set tests: apps on disk in a temporary
 * folder, an app manager that reports them as enabled, an app config in
 * memory, and the real catalogue, installer and remover on top of an
 * in-memory store and in-memory accounts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetViews;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetParties;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckStore;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTimelineEntries;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Db\TimelineEntryMapper;
use OCA\OpenRegister\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesStore;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCP\IUserManager;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentNotFoundException;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Db\WatcherMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\ViewService;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskState;
use OCP\Config\IUserConfig;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetProof;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeExampleSetAccounts;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeExampleSetRegisters;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeExampleSetStore;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeLockingProvider;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetLock;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Base for the example-set tests.
 */
abstract class ExampleSetTestCase extends TestCase {
	/** @var string Root of the temporary apps. */
	protected string $appsRoot = '';

	/** @var array<string, string> App paths by app id; every app here is enabled. */
	protected array $apps = [];

	/** @var array<string, string> The app config, by key. */
	protected array $config = [];

	/** @var array<string, bool> Which keys of the app config were stored as lazy values. */
	protected array $lazyKeys = [];

	protected FakeExampleSetStore $store;

	protected FakeExampleSetAccounts $accounts;

	/** Locks held by the runs under test and by the "other process" a test plays. */
	protected FakeLockingProvider $locks;

	protected FakeExampleSetRegisters $registers;

	/** @var array<string, array<string, string>> App config of the apps a fragment configures: app, key, value. */
	protected array $appValues = [];

	/** @var array<string, array<string, string>> The type a key of $appValues was written with, when not text. */
	protected array $appTypes = [];

	/** @var array<string, array<string, bool>> Which of those keys are kept as sensitive. */
	protected array $sensitive = [];

	/** @var array<string, array<string, array<string, string>>> User values: uid, app, key, value. */
	protected array $userValues = [];

	/** @var array<string, array<string, array<string, bool>>> Which user values are kept as sensitive. */
	protected array $sensitiveUserValues = [];

	/** @var array<string, Organisation> OpenRegister organisations by uuid. */
	protected array $organisations = [];

	/** @var array<string, array<string, array{name: string, shared: bool, content: string}>> Files by object id, then file id. */
	protected array $files = [];

	/** @var array<int, int> File ids whose delete throws. */
	protected array $undeletableFiles = [];

	/** @var array<string, Task> The task engine's tasks, by uuid. */
	protected array $engineTasks = [];

	/** @var array<int, array{data: array<string, mixed>, actor: string|null}> Every task import, in order. */
	protected array $taskImports = [];

	/** @var array<string, array<string, mixed>> The party links, by `<object> <party> <role>`, with what was imported. */
	protected array $partyLinks = [];

	/** @var array<string, Watcher> The follows, by `<user> <object uuid>`. */
	protected array $follows = [];

	/** @var array<string, array{user: string, object: string, message: string, createdAt: \DateTime|null}> The notes, by comment id. */
	protected array $notes = [];

	/** @var array<string, \DateTime> The read states, by `<user> <object uuid>`: when the object was seen. */
	protected array $readStates = [];

	/** @var array<string, Flow> The stored flows, by uuid. */
	protected array $flows = [];

	/** @var array<int, string> The uuids of the flows published, in order. */
	protected array $publishedFlows = [];

	/** @var array<string, array<int, array<string, mixed>>> The Deck rows written, by kind (boards, stacks, labels, cards), keyed by id. */
	protected array $deck = ['boards' => [], 'stacks' => [], 'labels' => [], 'cards' => []];

	/** @var array{collectives: array<int, array<string, mixed>>, pages: array<int, array<string, mixed>>} What the fake Collectives holds. */
	protected array $collectives = ['collectives' => [], 'pages' => []];

	/** @var bool Whether the fake Collectives is installed. */
	protected bool $collectivesInstalled = true;

	/** @var array{rooms: array<string, array<string, mixed>>, messages: array<int, array<string, mixed>>} What the fake Talk holds. */
	protected array $talk = ['rooms' => [], 'messages' => []];

	/** @var array<int, TalkLink> The Talk links, by id. */
	protected array $talkLinks = [];

	/** @var bool Whether the fake Talk is installed. */
	protected bool $talkInstalled = true;

	/** @var array<int, AuditTrail> The audit trail, by id. */
	protected array $auditTrail = [];

	/** @var array<string, TimelineEntry> The timeline entries, by uuid. */
	protected array $timelineEntries = [];

	/** @var array<int, EmailLink> The email links, by id. */
	protected array $emailLinks = [];

	/** @var array<string, object> Services a test adds to the container, by id. */
	protected array $services = [];

	/** @var array<int, string> The timeline kinds the fake knows. */
	protected array $timelineKinds = ['statuswijziging', 'contactmoment'];

	/** @var array<int, array{principal: string, uri: string}> The calendars, by id. */
	protected array $calendars = [];

	/** @var array<string, string> The calendar objects, by `<calendar id> <uri>`: their iCalendar text. */
	protected array $calendarObjects = [];

	/** @var array<string, CaseItem> The case plan items, by `<object uuid> <item key>`. */
	protected array $caseItems = [];

	/** @var array<int, string> The apps that brought plan items in, one per call. */
	protected array $casePlanActors = [];

	/** @var array<int, CalendarLink> The calendar links, by id. */
	protected array $calendarLinks = [];

	/** @var array<int, DeckLink> The Deck links, by id. */
	protected array $deckLinks = [];

	/** @var bool Whether Deck is installed. */
	protected bool $deckInstalled = true;

	/** @var array<string, FlowRun> The stored flow runs, by uuid. */
	protected array $flowRuns = [];

	/** @var array<string, View> The saved views, by uuid. */
	protected array $savedViews = [];

	/** @var int The views created so far, so a view written again gets a uuid of its own. */
	private int $viewsCreated = 0;

	/** @var string The instance's overwrite.cli.url. */
	protected string $baseUrl = 'https://cloud.example.test/';

	protected ExampleSetRecord $record;

	protected ExampleSetCatalogue $catalogue;

	protected ExampleSetInstaller $installer;

	protected ExampleSetRemover $remover;

	protected function setUp(): void {
		parent::setUp();
		$this->appsRoot = sys_get_temp_dir().'/or-example-sets-'.bin2hex(random_bytes(6));
		mkdir($this->appsRoot);

		$this->store    = new FakeExampleSetStore();
		$this->accounts  = new FakeExampleSetAccounts();
		$this->registers = new FakeExampleSetRegisters();
		$this->store->userExists = fn (string $uid): bool => $this->accounts->userExists(uid: $uid);
		$changes         = new ExampleSetChanges(store: $this->store, values: new ExampleSetValues());
		$container       = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
			FileService::class        => $this->fileService(),
			OrganisationMapper::class => $this->organisationMapper(),
			TaskService::class        => $this->taskService(),
			TaskMapper::class         => $this->taskMapper(),
			ViewService::class        => $this->viewService(),
			ViewMapper::class         => $this->viewMapper(),
			WatcherMapper::class      => $this->watcherMapper(),
			PartyRoleService::class   => $this->partyRoles(),
			PartyService::class       => $this->partyService(),
			ContactLinkMapper::class  => $this->contactLinks(),
			ICommentsManager::class   => $this->commentsManager(),
			ObjectReadStateMapper::class => $this->readStateMapper(),
			FlowMapper::class         => $this->flowMapper(),
			FlowVersionService::class => $this->flowVersions(),
			FlowService::class        => $this->flowService(),
			FlowRunMapper::class      => $this->flowRunMapper(),
			ExampleSetDeckStore::class => $this->deckStore(),
			ExampleSetCollectivesStore::class => $this->collectivesStore(),
			ExampleSetTalkStore::class => $this->talkStore(),
			TalkLinkMapper::class     => $this->talkLinkMapper(),
			AuditTrailMapper::class   => $this->auditTrailMapper(),
			TimelineEntryMapper::class => $this->timelineEntryMapper(),
			TimelineKindService::class => $this->timelineKindService(),
			DeckLinkMapper::class     => $this->deckLinkMapper(),
			EmailLinkMapper::class    => $this->emailLinkMapper(),
			CalDavBackend::class      => $this->caldav(),
			CalendarLinkMapper::class => $this->calendarLinkMapper(),
			CasePlanService::class    => $this->casePlans(),
			CaseItemMapper::class     => $this->caseItemMapper(),
			IUserManager::class       => $this->createMock(IUserManager::class),
			OrganisationService::class => $this->organisationService(),
			default                   => $this->services[$id],
		});
		$files     = new ExampleSetFiles(container: $container);
		$setConfig = new ExampleSetAppConfig(appConfig: $this->appConfigOfApps(), random: $this->random());
		$system    = $this->createMock(IConfig::class);
		$system->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = ''): string => ($key === 'overwrite.cli.url' ? $this->baseUrl : $default));
		$system->method('getUserKeys')->willReturnCallback(fn (string $uid, string $app): array => array_keys($this->userValues[$uid][$app] ?? []));
		$system->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, mixed $default = ''): mixed => ($this->userValues[$uid][$app][$key] ?? $default)
		);
		$system->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, mixed $value): void {
			$this->userValues[$uid][$app][$key] = (string) $value;
		});
		$system->method('deleteUserValue')->willReturnCallback(function (string $uid, string $app, string $key): void {
			unset($this->userValues[$uid][$app][$key]);
		});
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('isSensitive')->willReturnCallback(
			fn (string $uid, string $app, string $key): bool => ($this->sensitiveUserValues[$uid][$app][$key] ?? false)
		);
		$preferences = new ExampleSetUserPreferences(config: $system, userConfig: $userConfig, accounts: $this->accounts);
		$instance    = new ExampleSetInstance(container: $container, config: $system);

		$appConfig = $this->createMock(IAppConfig::class);
		// A value is found only by the lazy flag it was stored with, as a lazy and a non-lazy lookup are separate stores.
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => (
				isset($this->config[$key]) === true && ($this->lazyKeys[$key] ?? false) === $lazy ? $this->config[$key] : $default
			)
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy = false): bool {
			$this->config[$key]   = $value;
			$this->lazyKeys[$key] = $lazy;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key], $this->lazyKeys[$key]);
		});
		$this->record = new ExampleSetRecord(appConfig: $appConfig);
		$this->locks  = new FakeLockingProvider();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturnCallback(fn (): array => array_keys($this->apps));
		$appManager->method('getAppPath')->willReturnCallback(fn (string $app): string => $this->apps[$app]);

		$this->catalogue = new ExampleSetCatalogue(appManager: $appManager, order: new ExampleSetOrder());
		$people          = new ExampleSetPeople(accounts: $this->accounts, record: $this->record);
		$this->installer = new ExampleSetInstaller(
			store: $this->store,
			people: $people,
			values: new ExampleSetValues(),
			record: $this->record,
			proof: new ExampleSetProof(),
			prerequisites: new ExampleSetPrerequisites(registers: $this->registers, instance: $instance, record: $this->record, people: $people),
			changes: $changes,
			files: $files,
			appConfig: $setConfig,
			userPreferences: $preferences,
			engineRows: new ExampleSetEngineKinds(
				tasks: new ExampleSetTasks(container: $container),
				views: new ExampleSetViews(container: $container),
				watchers: new ExampleSetWatchers(container: $container),
				parties: new ExampleSetParties(container: $container),
				notes: new ExampleSetNotes(container: $container),
				readStates: new ExampleSetReadStates(container: $container),
				flows: new ExampleSetFlows(container: $container),
				archival: new ExampleSetArchival(appConfig: $this->appConfigOfApps()),
				exportRuns: new ExampleSetExportRuns(container: $container),
				deckCards: new ExampleSetDeckCards(container: $container),
				appointments: new ExampleSetAppointments(container: $container),
				casePlans: new ExampleSetCasePlans(container: $container),
				collectivesPages: new ExampleSetCollectivesPages(container: $container),
				talkConversations: new ExampleSetTalkConversations(container: $container),
				auditEntries: new ExampleSetAuditEntries(container: $container),
				timelineEntries: new ExampleSetTimelineEntries(container: $container),
				emailLinks: new ExampleSetEmailLinks(container: $container),
				accessLinks: new ExampleSetAccessLinks(container: $container),
				grants: new ExampleSetGrants(container: $container)
			),
			lock: new ExampleSetLock(locks: $this->locks)
		);
		$this->remover   = new ExampleSetRemover(
			store: $this->store,
			accounts: $this->accounts,
			record: $this->record,
			changes: $changes,
			registers: $this->registers,
			files: $files,
			appConfig: $setConfig,
			instance: $instance,
			userPreferences: $preferences,
			engineRows: new ExampleSetEngineKinds(
				tasks: new ExampleSetTasks(container: $container),
				views: new ExampleSetViews(container: $container),
				watchers: new ExampleSetWatchers(container: $container),
				parties: new ExampleSetParties(container: $container),
				notes: new ExampleSetNotes(container: $container),
				readStates: new ExampleSetReadStates(container: $container),
				flows: new ExampleSetFlows(container: $container),
				archival: new ExampleSetArchival(appConfig: $this->appConfigOfApps()),
				exportRuns: new ExampleSetExportRuns(container: $container),
				deckCards: new ExampleSetDeckCards(container: $container),
				appointments: new ExampleSetAppointments(container: $container),
				casePlans: new ExampleSetCasePlans(container: $container),
				collectivesPages: new ExampleSetCollectivesPages(container: $container),
				talkConversations: new ExampleSetTalkConversations(container: $container),
				auditEntries: new ExampleSetAuditEntries(container: $container),
				timelineEntries: new ExampleSetTimelineEntries(container: $container),
				emailLinks: new ExampleSetEmailLinks(container: $container),
				accessLinks: new ExampleSetAccessLinks(container: $container),
				grants: new ExampleSetGrants(container: $container)
			),
			lock: new ExampleSetLock(locks: $this->locks)
		);
	}

	protected function tearDown(): void {
		$this->removeTree(path: $this->appsRoot);
		parent::tearDown();
	}

	/**
	 * Enable an app, optionally shipping a fragment of a set.
	 *
	 * @param string                    $app      The app id.
	 * @param string                    $set      The set id.
	 * @param array<string, mixed>|null $fragment The fragment, or null for an app without one.
	 *
	 * @return void
	 */
	protected function app(string $app, string $set = 'demo', ?array $fragment = null): void {
		$path = $this->appsRoot.'/'.$app;
		@mkdir($path.'/'.ExampleSetCatalogue::FOLDER, 0777, true);
		$this->apps[$app] = $path;
		if ($fragment !== null) {
			file_put_contents($path.'/'.ExampleSetCatalogue::FOLDER.'/'.$set.'.json', (string) json_encode($fragment));
		}
	}

	/**
	 * A small cast file: one resident with an account and a BSN, one employee in one group.
	 *
	 * @param array<int, array<string, mixed>> $objects OpenRegister's own objects.
	 *
	 * @return array<string, mixed>
	 */
	protected function castFile(array $objects = []): array {
		return [
			'set'      => 'demo',
			'title'    => 'Demo',
			'timezone' => 'Europe/Amsterdam',
			'cast'     => [
				'groups'        => [['key' => 'staff', 'gid' => 'demo-staff', 'displayName' => 'Staff']],
				'people'        => [
					['key' => 'sanne', 'uid' => 'sanne.devries', 'displayName' => 'Sanne de Vries', 'email' => 'sanne@demo.example',
						'bsn' => '999994001', 'address' => ['number' => 14, 'street' => 'Lindelaan']],
					['key' => 'esra', 'uid' => 'esra.demir', 'displayName' => 'Esra Demir', 'groups' => ['staff']],
				],
				'organisations' => [['key' => 'shop', 'name' => 'Slagerij', 'kvk' => '00001001']],
			],
			'objects'  => $objects,
		];
	}

	/**
	 * A task service whose trusted import puts a task in $engineTasks.
	 *
	 * @return TaskService
	 */
	private function taskService(): TaskService {
		$service = $this->createMock(TaskService::class);
		$service->method('import')->willReturnCallback(function (array $data, ?string $actor): Task {
			foreach ($this->engineTasks as $held) {
				if ($held->getTaskKey() === (string) $data['key']) {
					// The task key is unique in the engine (or_tasks_key_uniq).
					throw new \RuntimeException('duplicate key value violates unique constraint "or_tasks_key_uniq"');
				}
			}

			$this->taskImports[] = ['data' => $data, 'actor' => $actor];
			$state = TaskState::normalise(value: (string) ($data['state'] ?? Task::STATE_AVAILABLE))['state'];
			$task  = new Task();
			$task->setUuid('task-'.count($this->taskImports));
			$task->setTaskKey((string) $data['key']);
			$task->setTitle((string) $data['title']);
			$task->setObjectUuid((string) $data['objectUuid']);
			$task->setAssignee(isset($data['assignee']) === true ? (string) $data['assignee'] : null);
			$task->setState($state);
			$task->setIsTerminal(TaskState::isTerminal(state: $state));
			$this->engineTasks[(string) $task->getUuid()] = $task;
			return $task;
		});

		return $service;
	}

	/**
	 * A task mapper over $engineTasks.
	 *
	 * @return TaskMapper
	 */
	private function taskMapper(): TaskMapper {
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			fn (string $uuid): Task => ($this->engineTasks[$uuid] ?? throw new DoesNotExistException('no task '.$uuid))
		);
		$mapper->method('findByKey')->willReturnCallback(
			fn (string $key): array => array_values(array_filter($this->engineTasks, static fn (Task $task): bool => $task->getTaskKey() === $key))
		);
		$mapper->method('update')->willReturnCallback(function (Task $task): Task {
			$this->engineTasks[(string) $task->getUuid()] = $task;
			return $task;
		});
		$mapper->method('delete')->willReturnCallback(function (Task $task): Task {
			unset($this->engineTasks[(string) $task->getUuid()]);
			return $task;
		});

		return $mapper;
	}

	/**
	 * A view service whose create puts a view in $savedViews.
	 *
	 * @return ViewService
	 */
	private function viewService(): ViewService {
		$service = $this->createMock(ViewService::class);
		$service->method('create')->willReturnCallback(
			function (string $name, string $description, string $owner, bool $isPublic, bool $isDefault, array $query, ?array $presentation = null, ?array $sharedWith = null, ?array $alert = null): View {
				$view = new View();
				$view->setUuid('view-'.(++$this->viewsCreated));
				$view->setName($name);
				$view->setOwner($owner);
				$view->setIsPublic($isPublic);
				$view->setQuery($query);
				$view->setAlert($alert);
				$view->setSharedWith($sharedWith ?? []);
				$this->savedViews[(string) $view->getUuid()] = $view;
				return $view;
			}
		);
		$service->method('update')->willReturnCallback(
			function (int|string $id, string $name, string $description, string $owner, bool $isPublic, bool $isDefault, array $query, ?array $favoredBy = null, ?array $presentation = null, ?array $sharedWith = null, ?array $alert = null): View {
				$view = ($this->savedViews[(string) $id] ?? throw new DoesNotExistException('no view '.$id));
				$view->setName($name);
				$view->setIsPublic($isPublic);
				$view->setQuery($query);
				$view->setAlert($alert);
				$view->setSharedWith($sharedWith ?? []);
				return $view;
			}
		);

		return $service;
	}

	/**
	 * A view mapper over $savedViews.
	 *
	 * @return ViewMapper
	 */
	private function viewMapper(): ViewMapper {
		$mapper = $this->createMock(ViewMapper::class);
		$mapper->method('find')->willReturnCallback(
			fn ($id): View => ($this->savedViews[(string) $id] ?? throw new DoesNotExistException('no view '.$id))
		);
		$mapper->method('delete')->willReturnCallback(function (View $view): View {
			unset($this->savedViews[(string) $view->getUuid()]);
			return $view;
		});
		$mapper->method('update')->willReturnCallback(function (View $view): View {
			$this->savedViews = array_filter($this->savedViews, static fn (View $held): bool => $held !== $view);
			$this->savedViews[(string) $view->getUuid()] = $view;
			return $view;
		});

		return $mapper;
	}

	/**
	 * A flow mapper over $flows, keyed by uuid.
	 *
	 * @return FlowMapper
	 */
	private function flowMapper(): FlowMapper {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('insert')->willReturnCallback(function (Flow $flow): Flow {
			$this->flows[(string) $flow->getUuid()] = $flow;
			return $flow;
		});
		$mapper->method('findByUuid')->willReturnCallback(function (string $uuid): Flow {
			if (isset($this->flows[$uuid]) === false) {
				throw new DoesNotExistException('No such flow');
			}

			return $this->flows[$uuid];
		});

		return $mapper;
	}

	/**
	 * A version service that records which flows it published.
	 *
	 * @return FlowVersionService
	 */
	private function flowVersions(): FlowVersionService {
		$versions = $this->createMock(FlowVersionService::class);
		$versions->method('publish')->willReturnCallback(function (Flow $flow) {
			// As the real service: only a draft head can be published.
			if ($flow->getLifecycleStatus() === 'published') {
				throw new \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused(
					reason: \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused::REASON_NOT_A_DRAFT,
					flowId: (string) $flow->getUuid(),
					state: 'published'
				);
			}

			$this->publishedFlows[] = (string) $flow->getUuid();
			$flow->setLifecycleStatus('published');
			return $this->createMock(\OCA\OpenRegister\Db\FlowVersion::class);
		});
		$versions->method('publishShippedUpdate')->willReturnCallback(function (Flow $flow) {
			$this->publishedFlows[] = (string) $flow->getUuid();
			return $this->createMock(\OCA\OpenRegister\Db\FlowVersion::class);
		});

		return $versions;
	}

	/**
	 * A flow service whose deleteStored() drops the flow from $flows.
	 *
	 * @return FlowService
	 */
	private function flowService(): FlowService {
		$service = $this->createMock(FlowService::class);
		$service->method('deleteStored')->willReturnCallback(function (Flow $flow): void {
			unset($this->flows[(string) $flow->getUuid()]);
			$this->flowRuns = array_filter($this->flowRuns, static fn (FlowRun $run): bool => $run->getFlowId() !== $flow->getUuid());
		});

		return $service;
	}

	/**
	 * A Collectives store over $collectives: a collective by name, a page by title in it.
	 *
	 * @return ExampleSetCollectivesStore
	 */
	private function collectivesStore(): ExampleSetCollectivesStore {
		$store = $this->createMock(ExampleSetCollectivesStore::class);
		$store->method('available')->willReturnCallback(fn (): bool => $this->collectivesInstalled);
		$store->method('collective')->willReturnCallback(function (string $owner, string $name, array $groups): array {
			foreach ($this->collectives['collectives'] as $id => $row) {
				if ($row['name'] === $name) {
					return ['id' => $id, 'created' => false];
				}
			}

			$id = (count($this->collectives['collectives']) + 1);
			$this->collectives['collectives'][$id] = ['name' => $name, 'owner' => $owner, 'groups' => $groups];
			return ['id' => $id, 'created' => true];
		});
		$store->method('page')->willReturnCallback(function (int $collective, string $owner, string $title, string $content): array {
			foreach ($this->collectives['pages'] as $id => $row) {
				if ($row['collective'] === $collective && $row['title'] === $title) {
					return ['id' => $id, 'created' => false];
				}
			}

			$id = (100 + count($this->collectives['pages']) + 1);
			$this->collectives['pages'][$id] = ['collective' => $collective, 'title' => $title, 'content' => $content, 'owner' => $owner];
			return ['id' => $id, 'created' => true];
		});
		$store->method('pageExists')->willReturnCallback(fn (int $collective, int $page, string $owner): bool => isset($this->collectives['pages'][$page]));
		$store->method('deletePage')->willReturnCallback(function (int $collective, int $page, string $owner): void {
			unset($this->collectives['pages'][$page]);
		});
		$store->method('deleteCollective')->willReturnCallback(function (int $collective, string $owner): void {
			unset($this->collectives['collectives'][$collective]);
			$this->collectives['pages'] = array_filter($this->collectives['pages'], static fn (array $page): bool => $page['collective'] !== $collective);
		});

		return $store;
	}//end collectivesStore()

	/**
	 * A timeline entry mapper over $timelineEntries.
	 *
	 * @return TimelineEntryMapper
	 */
	private function timelineEntryMapper(): TimelineEntryMapper {
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->method('insert')->willReturnCallback(function (TimelineEntry $entry): TimelineEntry {
			$this->timelineEntries[(string) $entry->getUuid()] = $entry;
			return $entry;
		});
		$mapper->method('findForObject')->willReturnCallback(
			fn (string $objectUuid, ?string $visibility = null, int $limit = 50, int $offset = 0): array => array_values(
				array_filter($this->timelineEntries, static fn (TimelineEntry $row): bool => $row->getObjectUuid() === $objectUuid)
			)
		);
		$mapper->method('findByUuid')->willReturnCallback(fn (string $uuid): ?TimelineEntry => ($this->timelineEntries[$uuid] ?? null));
		$mapper->method('delete')->willReturnCallback(function (TimelineEntry $entry): TimelineEntry {
			unset($this->timelineEntries[(string) $entry->getUuid()]);
			return $entry;
		});

		return $mapper;
	}//end timelineEntryMapper()

	/**
	 * A timeline kind service that knows $timelineKinds and accepts their fields as given.
	 *
	 * @return TimelineKindService
	 */
	private function timelineKindService(): TimelineKindService {
		$kinds = $this->createMock(TimelineKindService::class);
		$kinds->method('validateFields')->willReturnCallback(function (?string $kindSlug, array $fields): array {
			if (in_array($kindSlug, $this->timelineKinds, true) === false) {
				throw new TimelineValidationException(['kind' => 'No entry kind named '.$kindSlug.' is declared']);
			}

			return $fields;
		});

		return $kinds;
	}//end timelineKindService()

	/**
	 * An audit trail mapper over $auditTrail.
	 *
	 * @return AuditTrailMapper
	 */
	private function auditTrailMapper(): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(function (array $entries): array {
			foreach ($entries as $entry) {
				$entry->setId(count($this->auditTrail) + 1);
				$this->auditTrail[$entry->getId()] = $entry;
			}

			return $entries;
		});
		$mapper->method('findForObjectByAction')->willReturnCallback(
			fn (string $objectUuid, array $actions = [], int $limit = 50): array => array_values(
				array_filter($this->auditTrail, static fn (AuditTrail $row): bool => $row->getObjectUuid() === $objectUuid && in_array($row->getAction(), $actions, true))
			)
		);
		$mapper->method('find')->willReturnCallback(fn (int $id): AuditTrail => ($this->auditTrail[$id] ?? throw new DoesNotExistException('no entry '.$id)));

		return $mapper;
	}//end auditTrailMapper()

	/**
	 * A Talk store over $talk: a room by name, its held call as two system messages.
	 *
	 * @return ExampleSetTalkStore
	 */
	private function talkStore(): ExampleSetTalkStore {
		$store = $this->createMock(ExampleSetTalkStore::class);
		$store->method('available')->willReturnCallback(fn (): bool => $this->talkInstalled);
		$store->method('room')->willReturnCallback(function (string $owner, string $name, array $participants): array {
			foreach ($this->talk['rooms'] as $token => $room) {
				if ($room['name'] === $name && $room['owner'] === $owner) {
					return ['token' => $token, 'id' => $room['id'], 'created' => false];
				}
			}

			$token = 'room'.(count($this->talk['rooms']) + 1);
			$this->talk['rooms'][$token] = ['id' => (count($this->talk['rooms']) + 1), 'name' => $name, 'owner' => $owner, 'participants' => $participants];
			return ['token' => $token, 'id' => $this->talk['rooms'][$token]['id'], 'created' => true];
		});
		$store->method('heldCall')->willReturnCallback(function (string $token, string $owner, \DateTime $start, \DateTime $end, array $joined): void {
			$this->talk['messages'][] = ['room' => $token, 'message' => 'call_started', 'at' => $start->format('c')];
			$this->talk['messages'][] = ['room' => $token, 'message' => 'call_ended', 'at' => $end->format('c'), 'users' => $joined, 'duration' => ($end->getTimestamp() - $start->getTimestamp())];
		});
		$store->method('roomExists')->willReturnCallback(fn (string $token): bool => isset($this->talk['rooms'][$token]));
		$store->method('deleteRoom')->willReturnCallback(function (string $token): void {
			unset($this->talk['rooms'][$token]);
			$this->talk['messages'] = array_values(array_filter($this->talk['messages'], static fn (array $message): bool => $message['room'] !== $token));
		});

		return $store;
	}//end talkStore()

	/**
	 * A Talk link mapper over $talkLinks.
	 *
	 * @return TalkLinkMapper
	 */
	private function talkLinkMapper(): TalkLinkMapper {
		$mapper = $this->createMock(TalkLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (TalkLink $link): TalkLink {
			$link->setId(count($this->talkLinks) + 1);
			$this->talkLinks[$link->getId()] = $link;
			return $link;
		});
		$mapper->method('findByObjectAndRoom')->willReturnCallback(function (string $objectUuid, string $roomToken): ?TalkLink {
			foreach ($this->talkLinks as $link) {
				if ($link->getObjectUuid() === $objectUuid && $link->getRoomToken() === $roomToken) {
					return $link;
				}
			}

			return null;
		});
		$mapper->method('deleteByObjectAndRoom')->willReturnCallback(function (string $objectUuid, string $roomToken): int {
			$before          = count($this->talkLinks);
			$this->talkLinks = array_filter($this->talkLinks, static fn (TalkLink $link): bool => !($link->getObjectUuid() === $objectUuid && $link->getRoomToken() === $roomToken));
			return $before - count($this->talkLinks);
		});

		return $mapper;
	}//end talkLinkMapper()

	/**
	 * A Deck store over $deck: rows found by title, written with the next id, deleted by id.
	 *
	 * @return ExampleSetDeckStore
	 */
	private function deckStore(): ExampleSetDeckStore {
		$store = $this->createMock(ExampleSetDeckStore::class);
		$add   = function (string $kind, array $row): int {
			$id = (count($this->deck['boards']) + count($this->deck['stacks']) + count($this->deck['labels']) + count($this->deck['cards']) + 1);
			while (isset($this->deck[$kind][$id]) === true) {
				$id++;
			}

			$this->deck[$kind][$id] = $row;
			return $id;
		};
		$find  = function (string $kind, string $key, int|string $value, string $title): ?int {
			foreach ($this->deck[$kind] as $id => $row) {
				if ($row[$key] === $value && $row['title'] === $title) {
					return $id;
				}
			}

			return null;
		};
		$store->method('available')->willReturnCallback(fn (): bool => $this->deckInstalled);
		$store->method('board')->willReturnCallback(function (string $owner, string $title, string $color, array $groups) use ($find, $add): array {
			$found = $find('boards', 'owner', $owner, $title);
			return $found !== null ? ['id' => $found, 'created' => false] : ['id' => $add('boards', ['owner' => $owner, 'title' => $title, 'groups' => $groups]), 'created' => true];
		});
		$store->method('stack')->willReturnCallback(function (int $board, string $title) use ($find, $add): array {
			$found = $find('stacks', 'board', $board, $title);
			return $found !== null ? ['id' => $found, 'created' => false] : ['id' => $add('stacks', ['board' => $board, 'title' => $title]), 'created' => true];
		});
		$store->method('label')->willReturnCallback(function (int $board, string $title, string $color) use ($find, $add): array {
			$found = $find('labels', 'board', $board, $title);
			return $found !== null ? ['id' => $found, 'created' => false] : ['id' => $add('labels', ['board' => $board, 'title' => $title, 'color' => $color]), 'created' => true];
		});
		$store->method('insertCard')->willReturnCallback(
			fn (int $stack, string $owner, string $title, string $description, ?\DateTime $due, ?\DateTime $done, array $labels, array $assignees): int => $add(
				'cards',
				['stack' => $stack, 'owner' => $owner, 'title' => $title, 'due' => $due?->format('Y-m-d'), 'labels' => $labels, 'assignees' => $assignees]
			)
		);
		$store->method('cardExists')->willReturnCallback(fn (int $card): bool => isset($this->deck['cards'][$card]));
		$store->method('deleteCard')->willReturnCallback(function (int $card): void {
			unset($this->deck['cards'][$card]);
		});
		$store->method('deleteLabel')->willReturnCallback(function (int $label): void {
			unset($this->deck['labels'][$label]);
		});
		$store->method('deleteUnused')->willReturnCallback(function (?int $stack, ?int $board): void {
			if ($stack !== null && array_filter($this->deck['cards'], static fn (array $card): bool => $card['stack'] === $stack) === []) {
				unset($this->deck['stacks'][$stack]);
			}

			if ($board !== null && array_filter($this->deck['stacks'], static fn (array $row): bool => $row['board'] === $board) === []) {
				unset($this->deck['boards'][$board]);
				$this->deck['labels'] = array_filter($this->deck['labels'], static fn (array $row): bool => $row['board'] !== $board);
			}
		});

		return $store;
	}

	/**
	 * A CalDAV backend over $calendars and $calendarObjects.
	 *
	 * @return CalDavBackend
	 */
	private function caldav(): CalDavBackend {
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarsForUser')->willReturnCallback(function (string $principal): array {
			$rows = [];
			foreach ($this->calendars as $id => $calendar) {
				if ($calendar['principal'] === $principal) {
					$rows[] = ['id' => $id, 'uri' => $calendar['uri']];
				}
			}

			return $rows;
		});
		$backend->method('createCalendar')->willReturnCallback(function (string $principal, string $uri, array $properties): int {
			$id                   = (count($this->calendars) + 1);
			$this->calendars[$id] = ['principal' => $principal, 'uri' => $uri];
			return $id;
		});
		$backend->method('createCalendarObject')->willReturnCallback(function (int $calendarId, string $uri, string $data): string {
			$this->calendarObjects[$calendarId.' '.$uri] = $data;
			return 'etag';
		});
		$backend->method('getCalendarObject')->willReturnCallback(
			fn (int $calendarId, string $uri): ?array => (isset($this->calendarObjects[$calendarId.' '.$uri]) === true ? ['uri' => $uri] : null)
		);
		$backend->method('deleteCalendarObject')->willReturnCallback(function (int $calendarId, string $uri): void {
			unset($this->calendarObjects[$calendarId.' '.$uri]);
		});

		return $backend;
	}

	/**
	 * A case plan service whose convergent import puts the definition's items
	 * (nested under `children`) in $caseItems, creating only keys not held yet.
	 *
	 * @return CasePlanService
	 */
	private function casePlans(): CasePlanService {
		$service = $this->createMock(CasePlanService::class);
		$service->method('ensureItems')->willReturnCallback(
			function (string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, array $history, string $app): array {
				$this->casePlanActors[] = $app;
				$created = [];
				$walk    = function (array $nodes) use (&$walk, &$created, $objectUuid): void {
					foreach ($nodes as $node) {
						$key = (string) $node['key'];
						if (isset($this->caseItems[$objectUuid.' '.$key]) === false) {
							$item = new CaseItem();
							$item->setObjectUuid($objectUuid);
							$item->setItemKey($key);
							$item->setState((string) ($node['state'] ?? 'available'));
							$this->caseItems[$objectUuid.' '.$key] = $item;
							$created[] = $key;
						}

						$walk((array) ($node['children'] ?? []));
					}
				};
				$walk((array) $definition['items']);
				return ['created' => $created, 'existing' => [], 'items' => []];
			}
		);

		return $service;
	}

	/**
	 * A case item mapper over $caseItems.
	 *
	 * @return CaseItemMapper
	 */
	private function caseItemMapper(): CaseItemMapper {
		$mapper = $this->createMock(CaseItemMapper::class);
		$mapper->method('findByObject')->willReturnCallback(
			fn (string $objectUuid): array => array_values(array_filter($this->caseItems, static fn (CaseItem $item): bool => $item->getObjectUuid() === $objectUuid))
		);
		$mapper->method('delete')->willReturnCallback(function (CaseItem $item): CaseItem {
			unset($this->caseItems[$item->getObjectUuid().' '.$item->getItemKey()]);
			return $item;
		});

		return $mapper;
	}

	/**
	 * A calendar link mapper over $calendarLinks.
	 *
	 * @return CalendarLinkMapper
	 */
	private function calendarLinkMapper(): CalendarLinkMapper {
		$mapper = $this->createMock(CalendarLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (CalendarLink $link): CalendarLink {
			$link->setId(count($this->calendarLinks) + 1);
			$this->calendarLinks[$link->getId()] = $link;
			return $link;
		});
		$mapper->method('deleteByObjectAndEvent')->willReturnCallback(function (string $objectUuid, string $eventUid): int {
			$before              = count($this->calendarLinks);
			$this->calendarLinks = array_filter(
				$this->calendarLinks,
				static fn (CalendarLink $link): bool => !($link->getObjectUuid() === $objectUuid && $link->getEventUid() === $eventUid)
			);
			return $before - count($this->calendarLinks);
		});

		return $mapper;
	}

	/**
	 * A Deck link mapper over $deckLinks.
	 *
	 * @return DeckLinkMapper
	 */
	private function deckLinkMapper(): DeckLinkMapper {
		$mapper = $this->createMock(DeckLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (DeckLink $link): DeckLink {
			$link->setId(count($this->deckLinks) + 1);
			$this->deckLinks[$link->getId()] = $link;
			return $link;
		});
		$mapper->method('deleteByObjectAndCard')->willReturnCallback(function (string $objectUuid, int $cardId): int {
			$before          = count($this->deckLinks);
			$this->deckLinks = array_filter($this->deckLinks, static fn (DeckLink $link): bool => !($link->getObjectUuid() === $objectUuid && $link->getCardId() === $cardId));
			return $before - count($this->deckLinks);
		});

		return $mapper;
	}

	/**
	 * An email link mapper over $emailLinks.
	 *
	 * @return EmailLinkMapper
	 */
	private function emailLinkMapper(): EmailLinkMapper {
		$mapper = $this->createMock(originalClassName: EmailLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (EmailLink $link): EmailLink {
			$link->setId(count($this->emailLinks) + 1);
			$this->emailLinks[$link->getId()] = $link;
			return $link;
		});
		$mapper->method('find')->willReturnCallback(function (int $id): EmailLink {
			if (isset($this->emailLinks[$id]) === false) {
				throw new DoesNotExistException('no email link '.$id);
			}

			return $this->emailLinks[$id];
		});
		$mapper->method('delete')->willReturnCallback(function (EmailLink $link): EmailLink {
			unset($this->emailLinks[$link->getId()]);
			return $link;
		});

		return $mapper;
	}//end emailLinkMapper()

	/**
	 * A flow run mapper over $flowRuns, keyed by uuid.
	 *
	 * @return FlowRunMapper
	 */
	private function flowRunMapper(): FlowRunMapper {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('insert')->willReturnCallback(function (FlowRun $run): FlowRun {
			$this->flowRuns[(string) $run->getUuid()] = $run;
			return $run;
		});

		return $mapper;
	}

	/**
	 * An organisation service that puts new entities in org-1.
	 *
	 * @return OrganisationService
	 */
	private function organisationService(): OrganisationService {
		$service = $this->createMock(OrganisationService::class);
		$service->method('getOrganisationForNewEntity')->willReturn('org-1');

		return $service;
	}

	/**
	 * A comments manager over $notes, keyed by comment id.
	 *
	 * @return ICommentsManager
	 */
	private function commentsManager(): ICommentsManager {
		$pending = [];
		$manager = $this->createMock(ICommentsManager::class);
		$manager->method('create')->willReturnCallback(function (string $actorType, string $actorId, string $objectType, string $objectId) use (&$pending): IComment {
			$state   = new \ArrayObject(['user' => $actorId, 'object' => $objectId, 'message' => '', 'createdAt' => null, 'id' => '']);
			$comment = $this->createMock(IComment::class);
			$comment->method('setMessage')->willReturnCallback(function (string $message) use ($state, $comment): IComment {
				$state['message'] = $message;
				return $comment;
			});
			$comment->method('setVerb')->willReturn($comment);
			$comment->method('setCreationDateTime')->willReturnCallback(function (\DateTime $at) use ($state, $comment): IComment {
				$state['createdAt'] = $at;
				return $comment;
			});
			$comment->method('getId')->willReturnCallback(fn (): string => (string) $state['id']);
			$pending[spl_object_id($comment)] = $state;
			return $comment;
		});
		$manager->method('save')->willReturnCallback(function (IComment $comment) use (&$pending): bool {
			$state       = $pending[spl_object_id($comment)];
			$state['id'] = (string) (count($this->notes) + 100);
			$this->notes[$state['id']] = ['user' => $state['user'], 'object' => $state['object'], 'message' => $state['message'], 'createdAt' => $state['createdAt']];
			return true;
		});
		$manager->method('get')->willReturnCallback(function (string $id): IComment {
			if (isset($this->notes[$id]) === false) {
				throw new CommentNotFoundException();
			}

			return $this->createMock(IComment::class);
		});
		$manager->method('delete')->willReturnCallback(function (string $id): bool {
			if (isset($this->notes[$id]) === false) {
				throw new CommentNotFoundException();
			}

			unset($this->notes[$id]);
			return true;
		});

		return $manager;
	}

	/**
	 * A read-state mapper over $readStates, keyed `<user> <object uuid>`.
	 *
	 * @return ObjectReadStateMapper
	 */
	private function readStateMapper(): ObjectReadStateMapper {
		$mapper = $this->createMock(ObjectReadStateMapper::class);
		$mapper->method('markSeen')->willReturnCallback(
			function (string $userId, string $objectUuid, ?string $register = null, ?string $schema = null, ?array $subSeen = null, ?\DateTime $seenAt = null): ObjectReadState {
				$this->readStates[$userId.' '.$objectUuid] = ($seenAt ?? new \DateTime());
				return new ObjectReadState();
			}
		);
		$mapper->method('findOne')->willReturnCallback(fn (string $userId, string $objectUuid): ?ObjectReadState => (isset($this->readStates[$userId.' '.$objectUuid]) === true ? new ObjectReadState() : null));
		$mapper->method('markUnread')->willReturnCallback(function (string $userId, string $objectUuid): bool {
			$had = isset($this->readStates[$userId.' '.$objectUuid]);
			unset($this->readStates[$userId.' '.$objectUuid]);
			return $had;
		});

		return $mapper;
	}

	/**
	 * A watcher mapper over $follows, keyed `<user> <object uuid>`.
	 *
	 * @return WatcherMapper
	 */
	private function watcherMapper(): WatcherMapper {
		$mapper = $this->createMock(WatcherMapper::class);
		$mapper->method('subscribe')->willReturnCallback(
			function (string $userId, string $objectUuid, ?string $register = null, ?string $schema = null, ?bool $notify = null): Watcher {
				$watcher = new Watcher();
				$watcher->setUserId($userId);
				$watcher->setObjectUuid($objectUuid);
				$watcher->setRegister($register);
				$watcher->setSchema($schema);
				$watcher->setNotify($notify ?? true);
				$this->follows[$userId.' '.$objectUuid] = $watcher;
				return $watcher;
			}
		);
		$mapper->method('findOne')->willReturnCallback(fn (string $userId, string $objectUuid): ?Watcher => ($this->follows[$userId.' '.$objectUuid] ?? null));
		$mapper->method('unsubscribe')->willReturnCallback(function (string $userId, string $objectUuid): bool {
			$had = isset($this->follows[$userId.' '.$objectUuid]);
			unset($this->follows[$userId.' '.$objectUuid]);
			return $had;
		});

		return $mapper;
	}

	/**
	 * A party role service whose trusted import puts a link in $partyLinks.
	 *
	 * @return PartyRoleService
	 */
	private function partyRoles(): PartyRoleService {
		$roles = $this->createMock(PartyRoleService::class);
		$roles->method('importParty')->willReturnCallback(
			function (string $objectUuid, int $registerId, int|string|null $schemaId, array $payload, string $linkedBy): ContactLink {
				if (str_starts_with((string) $payload['partyUuid'], 'not-a-party') === true) {
					throw new \Exception('Object "'.$payload['partyUuid'].'" is not a party', 400);
				}

				$this->partyLinks[$objectUuid.' '.$payload['partyUuid'].' '.$payload['role']] = ['payload' => $payload, 'linkedBy' => $linkedBy, 'registerId' => $registerId];
				return new ContactLink();
			}
		);
		$roles->method('removeParty')->willReturnCallback(function (string $objectUuid, string $partyUuid, ?string $role = null): int {
			unset($this->partyLinks[$objectUuid.' '.$partyUuid.' '.$role]);
			return 1;
		});

		return $roles;
	}

	/**
	 * A party service as an occ install meets it: `find` reads with RBAC and
	 * no user, which a schema refusing "Anonymous" answers with null;
	 * `findUnscoped` finds every object.
	 *
	 * @return PartyService
	 */
	private function partyService(): PartyService {
		$parties = $this->createMock(PartyService::class);
		$parties->method('find')->willReturn(null);
		$parties->method('findUnscoped')->willReturnCallback(function (string $uuid): ?ObjectEntity {
			// A task is not an object, as on an instance.
			if (str_starts_with($uuid, 'missing') === true || isset($this->engineTasks[$uuid]) === true) {
				return null;
			}

			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setRegister('7');
			$object->setSchema('9');
			$object->setOwner($this->store->owners[$uuid] ?? null);
			return $object;
		});

		return $parties;
	}

	/**
	 * A link mapper over $partyLinks.
	 *
	 * @return ContactLinkMapper
	 */
	private function contactLinks(): ContactLinkMapper {
		$links = $this->createMock(ContactLinkMapper::class);
		$links->method('findByObjectContactAndRole')->willReturnCallback(
			function (string $objectUuid, string $contactUid, ?string $role): ?ContactLink {
				foreach (array_keys($this->partyLinks) as $key) {
					[$object, $party, $linkRole] = explode(' ', $key, 3);
					if ($object === $objectUuid && ContactLink::partyUid(partyUuid: $party) === $contactUid && $linkRole === $role) {
						return new ContactLink();
					}
				}

				return null;
			}
		);

		return $links;
	}

	/**
	 * A fixed install day.
	 *
	 * @return DateTimeImmutable
	 */
	protected function today(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-13 12:00', new DateTimeZone('Europe/Amsterdam'));
	}

	/**
	 * An app config over $appValues and $sensitive.
	 *
	 * @return IAppConfig
	 */
	private function appConfigOfApps(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(fn (string $app, string $key): bool => isset($this->appValues[$app][$key]));
		$config->method('isSensitive')->willReturnCallback(fn (string $app, string $key): bool => ($this->sensitive[$app][$key] ?? false));
		// As Nextcloud's typed app config: a key written as an integer is not read or written as text.
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (($this->appTypes[$app][$key] ?? 'string') === 'int'
				? throw new \RuntimeException('the key '.$key.' is typed int')
				: ($this->appValues[$app][$key] ?? $default))
		);
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int) ($this->appValues[$app][$key] ?? $default));
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->appValues[$app][$key] = (string) $value;
				$this->appTypes[$app][$key]  = 'int';
				return true;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				if (($this->appTypes[$app][$key] ?? 'string') === 'int') {
					throw new \RuntimeException('the key '.$key.' is typed int');
				}

				$this->appValues[$app][$key] = $value;
				$this->sensitive[$app][$key] = $sensitive;
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->appValues[$app][$key], $this->sensitive[$app][$key]);
		});

		return $config;
	}

	/**
	 * A random source that gives a fixed text.
	 *
	 * @return ISecureRandom
	 */
	private function random(): ISecureRandom {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $length): string => str_repeat('r', $length));

		return $random;
	}

	/**
	 * OpenRegister's FileService over $files.
	 *
	 * @return FileService
	 */
	private function fileService(): FileService {
		$service = $this->createMock(FileService::class);
		$service->method('addFile')->willReturnCallback(
			function (string $objectEntity, string $fileName, mixed $content, bool $share = false): File {
				$id = (string) (count($this->files, COUNT_RECURSIVE) + 100);
				$this->files[$objectEntity][$id] = ['name' => $fileName, 'shared' => $share, 'content' => (string) $content];
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn((int) $id);
				return $file;
			}
		);
		$service->method('getFiles')->willReturnCallback(function (string $object): array {
			$nodes = [];
			foreach (($this->files[$object] ?? []) as $file) {
				$node = $this->createMock(File::class);
				$node->method('getName')->willReturn($file['name']);
				$nodes[] = $node;
			}
			return $nodes;
		});
		// As the real FileService: an id handed over without its object is
		// resolved against getObjectFolder(null), which throws; only a node
		// found by id (in OpenRegister's own account) can be deleted.
		$service->method('getFileById')->willReturnCallback(function (int $fileId): ?File {
			foreach ($this->files as $files) {
				if (isset($files[(string) $fileId]) === true) {
					$node = $this->createMock(File::class);
					$node->method('getId')->willReturn($fileId);
					return $node;
				}
			}
			return null;
		});
		$service->method('deleteFile')->willReturnCallback(function (mixed $file): bool {
			if ($file instanceof File === false) {
				throw new \TypeError('FolderManagementHandler::getObjectFolder(): Argument #1 ($objectEntity) must be of type ObjectEntity|string, null given');
			}
			if (in_array($file->getId(), $this->undeletableFiles, true) === true) {
				throw new \RuntimeException('File is not deletable by the current session');
			}
			foreach ($this->files as $object => $files) {
				unset($this->files[$object][(string) $file->getId()]);
			}
			return true;
		});

		return $service;
	}

	/**
	 * OpenRegister's organisation mapper over $organisations.
	 *
	 * @return OrganisationMapper
	 */
	private function organisationMapper(): OrganisationMapper {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findAll')->willReturnCallback(fn (): array => array_values($this->organisations));
		$mapper->method('findBySlug')->willReturnCallback(function (string $slug): Organisation {
			foreach ($this->organisations as $organisation) {
				if ($organisation->getSlug() === $slug) {
					return $organisation;
				}
			}
			throw new DoesNotExistException('no such organisation');
		});
		$mapper->method('findByUuid')->willReturnCallback(fn (string $uuid): Organisation => ($this->organisations[$uuid] ?? throw new DoesNotExistException('gone')));
		$mapper->method('insert')->willReturnCallback(function (Organisation $organisation): Organisation {
			$organisation->setUuid('org-'.(count($this->organisations) + 1));
			$this->organisations[(string) $organisation->getUuid()] = $organisation;
			return $organisation;
		});
		$mapper->method('delete')->willReturnCallback(function (Organisation $organisation): Organisation {
			unset($this->organisations[(string) $organisation->getUuid()]);
			return $organisation;
		});

		return $mapper;
	}

	/**
	 * Remove a folder and everything in it.
	 *
	 * @param string $path The folder.
	 *
	 * @return void
	 */
	private function removeTree(string $path): void {
		if ($path === '' || is_dir($path) === false) {
			return;
		}

		foreach ((array) scandir($path) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path.'/'.$entry;
			if (is_dir($child) === true) {
				$this->removeTree(path: $child);
				continue;
			}

			unlink($child);
		}

		rmdir($path);
	}
}
