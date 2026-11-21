<?php

/**
 * Golden test pinning what DeploymentService allows and refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
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

namespace OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRecords;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentValues;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationWrite;

/**
 * Runs deploy() over every combination of set state, four-eyes setting,
 * approver and preview outcome, and rollback() over its refusals and
 * restores. Each case records the outcome (the deployment written, or the
 * refusal's reason, status, message and refusals) and every collaborator
 * call in order, so a refusal is also pinned to happen before any write.
 * The generated deployment UUID and the deployment time are recorded as
 * placeholders. Compared with `fixtures/deployment-access-golden.json`;
 * set `OR_GOLDEN_WRITE=1` to rewrite it.
 */
class DeploymentServiceAccessGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The fixture path.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/deployment-access-golden.json';
	}//end goldenPath()

	/**
	 * Case name => case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$cases = [];
		foreach (['open', 'approved', 'deployed', 'discarded', 'none'] as $state) {
			foreach ([false, true] as $fourEyes) {
				foreach (['nobody', 'other', 'self'] as $approver) {
					foreach (['changes', 'refused', 'empty', 'write-fails'] as $preview) {
						$name = "deploy-{$state}-".($fourEyes === true ? 'four-eyes' : 'single')."-{$approver}-{$preview}";
						$cases[$name] = ['op' => 'deploy', 'state' => $state, 'fourEyes' => $fourEyes, 'approver' => $approver, 'preview' => $preview, 'named' => false];
					}
				}
			}
		}

		$cases['deploy-named'] = ['op' => 'deploy', 'state' => 'open', 'fourEyes' => false, 'approver' => 'nobody', 'preview' => 'changes', 'named' => true];
		$cases['deploy-unnamed-set'] = ['op' => 'deploy', 'state' => 'approved', 'fourEyes' => true, 'approver' => 'other', 'preview' => 'changes', 'named' => false, 'setName' => null];
		foreach (['unknown', 'empty', 'restore', 'write-fails', 'no-session'] as $kind) {
			$cases["rollback-{$kind}"] = ['op' => 'rollback', 'kind' => $kind, 'named' => false];
		}

		$cases['rollback-named'] = ['op' => 'rollback', 'kind' => 'restore', 'named' => true];
		return $cases;
	}//end corpus()

	/**
	 * One data set per corpus entry.
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
	 * The case's outcome and calls equal the recorded golden.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testOutcomeMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			if (is_dir(dirname(self::goldenPath())) === false) {
				mkdir(dirname(self::goldenPath()), 0777, true);
			}

			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testOutcomeMatchesGolden()

	/**
	 * Run one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		$service = $this->service(case: $case);
		$name = null;
		if ($case['named'] === true) {
			$name = 'given name';
		}

		try {
			if ($case['op'] === 'deploy') {
				$out = ['deployed' => $this->describe(deployment: $service->deploy('set-1', $name))];
			} else {
				$out = ['deployed' => $this->describe(deployment: $service->rollback('dep-1', $name))];
			}
		} catch (DeploymentRefusedException $e) {
			$out = ['refused' => [$e->getReason(), $e->getStatusCode(), $e->getMessage(), $e->getRefusals()]];
		}

		return json_decode((string) json_encode(self::normalise(value: $out + ['calls' => $this->calls])), true);
	}//end runCase()

	/**
	 * A deployment as plain data.
	 *
	 * @param ConfigurationDeployment $deployment The deployment.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(ConfigurationDeployment $deployment): array {
		return [
			'uuid' => $deployment->getUuid(),
			'name' => $deployment->getName(),
			'setUuid' => $deployment->getSetUuid(),
			'author' => $deployment->getAuthor(),
			'approver' => $deployment->getApprover(),
			'deployedBy' => $deployment->getDeployedBy(),
			'restoresUuid' => $deployment->getRestoresUuid(),
			'state' => $deployment->getState(),
			'changes' => $deployment->readChanges(),
		];
	}//end describe()

	/**
	 * Replace generated UUIDs and dates with placeholders.
	 *
	 * @param mixed $value The recorded value.
	 *
	 * @return mixed
	 */
	private static function normalise(mixed $value): mixed {
		if ($value instanceof \DateTimeInterface) {
			return 'DATETIME';
		}

		if (is_string($value) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1) {
			return 'GENERATED-UUID';
		}

		if (is_array($value) === true) {
			return array_map(self::normalise(...), $value);
		}

		return $value;
	}//end normalise()

	/**
	 * The service under test, wired to recording doubles.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return DeploymentService
	 */
	private function service(array $case): DeploymentService {
		$set = $this->draftSet(case: $case);
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('loadSet')->willReturnCallback(
			function (string $uuid) use ($set): ConfigurationDraftSet {
				$this->calls[] = ['drafts.loadSet', $uuid];
				return $set;
			}
		);
		$drafts->method('requiresFourEyes')->willReturnCallback(
			function () use ($case): bool {
				$this->calls[] = ['drafts.requiresFourEyes'];
				return ($case['fourEyes'] ?? false);
			}
		);

		$previews = $this->createMock(DeploymentPreviewService::class);
		$previews->method('preview')->willReturnCallback(
			function (ConfigurationDraftSet $set) use ($case): array {
				$this->calls[] = ['previews.preview', $set->getUuid()];
				return self::preview(kind: ($case['preview'] ?? 'changes'));
			}
		);

		$sets = $this->createMock(ConfigurationDraftSetMapper::class);
		$sets->method('save')->willReturnCallback(
			function (ConfigurationDraftSet $set): ConfigurationDraftSet {
				$this->calls[] = ['sets.save', $set->getUuid(), $set->getState(), $set->getDeploymentUuid()];
				return $set;
			}
		);

		return new DeploymentService(
			new DeploymentValues($drafts, $previews, $this->store(case: $case)),
			new DeploymentRecords($this->deployments(case: $case), $sets, $this->db()),
			$this->session(case: $case),
			$this->logger()
		);
	}//end service()

	/**
	 * The draft set of a deploy case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ConfigurationDraftSet
	 */
	private function draftSet(array $case): ConfigurationDraftSet {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setName('september tuning');
		if (array_key_exists('setName', $case) === true) {
			$set->setName($case['setName']);
		}

		if (($case['state'] ?? 'none') !== 'none') {
			$set->setState($case['state']);
		}

		$set->setCreatedBy('alice');
		$approvers = ['nobody' => null, 'other' => 'bob', 'self' => 'alice'];
		$set->setApprovedBy($approvers[$case['approver'] ?? 'nobody']);
		return $set;
	}//end draftSet()

	/**
	 * A preview of the given kind.
	 *
	 * @param string $kind The preview kind.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview(string $kind): array {
		if ($kind === 'refused') {
			return [
				'changes' => [self::verdict(key: 'a', status: 'change')],
				'refusals' => [['key' => 'a', 'reason' => 'not allowed'], ['key' => 'b']],
			];
		}

		if ($kind === 'empty') {
			return ['changes' => [], 'refusals' => []];
		}

		return [
			'changes' => [self::verdict(key: 'a', status: 'change'), self::verdict(key: 'fail-b', status: 'remove'), self::verdict(key: 'c', status: 'add')],
			'refusals' => [],
		];
	}//end preview()

	/**
	 * One preview verdict.
	 *
	 * @param string $key    The configuration key.
	 * @param string $status The verdict status.
	 *
	 * @return array<string, mixed>
	 */
	private static function verdict(string $key, string $status): array {
		$verdict = ['layer' => 'instance', 'layerRef' => null, 'key' => $key, 'from' => 'old-'.$key, 'to' => 'new-'.$key, 'status' => $status];
		if ($status !== 'add') {
			$verdict['fromPresent'] = true;
		}

		return $verdict;
	}//end verdict()

	/**
	 * The value store double; a `write-fails` case fails on the second address.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ConfigurationValueStore
	 */
	private function store(array $case): ConfigurationValueStore {
		$fails = (($case['preview'] ?? $case['kind'] ?? '') === 'write-fails');
		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('write')->willReturnCallback(
			function (ConfigurationAddress $address, ConfigurationWrite $write) use ($fails): array {
				$this->calls[] = ['store.write', $address->layer, $address->layerRef, $address->configKey, $write->value, $write->deploymentUuid, $write->actor];
				$configKey = $address->configKey;
				if ($fails === true && str_starts_with($configKey, 'fail-') === true) {
					throw new RuntimeException('disk full');
				}

				return ['undo' => $configKey];
			}
		);
		$store->method('remove')->willReturnCallback(
			function (string $layer, ?string $layerRef, string $configKey) use ($fails): array {
				$this->calls[] = ['store.remove', $layer, $layerRef, $configKey];
				if ($fails === true && str_starts_with($configKey, 'fail-') === true) {
					throw new RuntimeException('disk full');
				}

				return ['undo' => $configKey];
			}
		);
		$store->method('restore')->willReturnCallback(
			function (array $undo): int {
				$this->calls[] = ['store.restore', $undo];
				return count($undo);
			}
		);
		$store->method('read')->willReturnCallback(
			function (string $layer, ?string $layerRef, string $configKey): ConfigurationSnapshot {
				$this->calls[] = ['store.read', $layer, $layerRef, $configKey];
				return new ConfigurationSnapshot(new ConfigurationAddress($layer, $layerRef, $configKey), 'live-'.$configKey, true);
			}
		);
		return $store;
	}//end store()

	/**
	 * The deployment mapper double.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ConfigurationDeploymentMapper
	 */
	private function deployments(array $case): ConfigurationDeploymentMapper {
		$mapper = $this->createMock(ConfigurationDeploymentMapper::class);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $data): ConfigurationDeployment {
				$this->calls[] = ['deployments.createFromArray', $data];
				$deployment = new ConfigurationDeployment();
				$deployment->hydrate($data);
				return $deployment;
			}
		);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid) use ($case): ConfigurationDeployment {
				$this->calls[] = ['deployments.findByUuid', $uuid];
				return self::original(kind: ($case['kind'] ?? ''));
			}
		);
		return $mapper;
	}//end deployments()

	/**
	 * The deployment a rollback case restores.
	 *
	 * @param string $kind The rollback kind.
	 *
	 * @return ConfigurationDeployment
	 */
	private static function original(string $kind): ConfigurationDeployment {
		if ($kind === 'unknown') {
			throw new DoesNotExistException('missing');
		}

		$deployment = new ConfigurationDeployment();
		$deployment->setUuid('dep-1');
		$deployment->setName('september');
		$changes = [];
		if ($kind !== 'empty') {
			$changes = [
				['layer' => 'instance', 'layerRef' => null, 'key' => 'a', 'previous' => 'old-a', 'previousPresent' => true, 'value' => 'new-a'],
				['layer' => 'organisation', 'layerRef' => 'org-1', 'key' => 'fail-b', 'previous' => null, 'previousPresent' => false, 'value' => 'new-b'],
				['key' => ''],
				['key' => 'c', 'previous' => 'old-c'],
			];
		}

		$deployment->setChanges($changes);
		return $deployment;
	}//end original()

	/**
	 * The database connection double.
	 *
	 * @return IDBConnection
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$db->method($method)->willReturnCallback(
				function () use ($method): bool {
					$this->calls[] = ['db.'.$method];
					return true;
				}
			);
		}

		return $db;
	}//end db()

	/**
	 * The session double; a `no-session` case has no user.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return IUserSession
	 */
	private function session(array $case): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if (($case['kind'] ?? '') === 'no-session') {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('deployer');
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The logger double; records the message and the context keys.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$context['exception'] = get_class($context['exception']);
				$this->calls[] = ['log.error', (string) $message, $context];
			}
		);
		return $logger;
	}//end logger()
}//end class
