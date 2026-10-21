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
use OCP\AppFramework\Db\DoesNotExistException;
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

	protected FakeExampleSetRegisters $registers;

	/** @var array<string, array<string, string>> App config of the apps a fragment configures: app, key, value. */
	protected array $appValues = [];

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

	/** @var array<string, Watcher> The follows, by `<user> <object uuid>`. */
	protected array $follows = [];

	/** @var array<string, View> The saved views, by uuid. */
	protected array $savedViews = [];

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
				watchers: new ExampleSetWatchers(container: $container)
			)
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
				watchers: new ExampleSetWatchers(container: $container)
			)
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
				$view->setUuid('view-'.(count($this->savedViews) + 1));
				$view->setName($name);
				$view->setOwner($owner);
				$view->setIsPublic($isPublic);
				$view->setQuery($query);
				$view->setAlert($alert);
				$this->savedViews[(string) $view->getUuid()] = $view;
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
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->appValues[$app][$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
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
