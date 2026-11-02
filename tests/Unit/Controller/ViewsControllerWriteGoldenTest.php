<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\ViewsController;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\ViewPresentationService;
use OCA\OpenRegister\Service\ViewService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the view write endpoints: create(), update() and patch().
 *
 * Every case records each collaborator call (the session uid, the reach the
 * checks run for, the field and recipient checks, the view service, the
 * logger) and the response, and compares the recording with
 * fixtures/views-write-golden.json. The resolver answers only for the acting
 * user `alice`: a reach built for anyone else reaches nothing and may change
 * nothing, so a forged owner or userId in the body shows up as a refusal or
 * as a different recorded uid. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ViewsController
 * @uses \OCA\OpenRegister\Db\View
 * @uses \OCA\OpenRegister\Service\Rbac\ViewerReach
 * @uses \OCA\OpenRegister\Service\View\ViewAlert
 * @uses \OCA\OpenRegister\Exception\InvalidViewAlertException
 */
class ViewsControllerWriteGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/views-write-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$config = ['registers' => [1], 'schemas' => [2], 'source' => 'database', 'searchTerms' => ['x'], 'facetFilters' => ['a' => 1], 'enabledFacets' => ['f'], 'extra' => 'dropped'];
		$query = ['registers' => [9], 'custom' => true];
		$cases = [];
		foreach (['create', 'update', 'patch'] as $action) {
			$cases[$action . ': no session'] = [['action' => $action, 'uid' => '', 'body' => ['name' => 'N', 'query' => $query]]];
			$cases[$action . ': config wins over query'] = [['action' => $action, 'body' => ['name' => 'N', 'configuration' => $config, 'query' => $query, 'description' => 'D', 'isPublic' => true, 'isDefault' => true]]];
			$cases[$action . ': partial config gets defaults'] = [['action' => $action, 'body' => ['name' => 'N', 'configuration' => ['schemas' => [3]]]]];
			$cases[$action . ': query only'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, 'presentation' => ['type' => 'table']]]];
			$cases[$action . ': config not an array falls to query'] = [['action' => $action, 'body' => ['name' => 'N', 'configuration' => 'x', 'query' => $query, 'presentation' => 'not array']]];
			$cases[$action . ': neither query nor config'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => 'nope']]];
			$cases[$action . ': no name'] = [['action' => $action, 'body' => ['query' => $query]]];
			$cases[$action . ': empty name'] = [['action' => $action, 'body' => ['name' => '', 'query' => $query]]];
			$cases[$action . ': invalid shares'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, 'sharedWith' => ['bad']], 'findings' => [['message' => 'one.'], ['message' => 'two.']]]];
			$cases[$action . ': valid shares'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, 'sharedWith' => [['type' => 'user', 'id' => 'bob']]]]];
			$cases[$action . ': null shares'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, 'sharedWith' => null]]];
			$cases[$action . ': alert not an object'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, 'alert' => 'high']]];
			$cases[$action . ': empty alert'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, 'alert' => '']]];
			$cases[$action . ': recipients refused'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query], 'recipientsThrow' => 'not your recipient']];
			$cases[$action . ': service throws'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query], 'serviceThrows' => 'db down']];
			$cases[$action . ': forged owner and userId'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, 'owner' => 'mallory', 'userId' => 'mallory', 'requestedBy' => 'mallory']]];
		}

		foreach (['update', 'patch'] as $action) {
			$cases[$action . ': view does not exist'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query], 'missing' => true]];
			$cases[$action . ': view out of reach'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query], 'stored' => ['owner' => 'carol']]];
			$cases[$action . ': fields refused'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query, '_limit' => 5], 'refused' => ['name', 'query']]];
			$cases[$action . ': member saves the owner view'] = [['action' => $action, 'body' => ['name' => 'N', 'query' => $query], 'stored' => ['owner' => 'dave', 'reachable' => true]]];
		}

		$cases['patch: empty body keeps the stored view'] = [['action' => 'patch', 'body' => []]];
		$cases['patch: explicit false flags'] = [['action' => 'patch', 'body' => ['isPublic' => false, 'isDefault' => false, 'favoredBy' => ['bob']]]];
		$cases['patch: null flags keep stored'] = [['action' => 'patch', 'body' => ['isPublic' => null, 'isDefault' => null, 'name' => null, 'description' => null]]];
		$cases['patch: stored view without query or names'] = [['action' => 'patch', 'body' => ['presentation' => null], 'stored' => ['bare' => true]]];
		return $cases;
	}

	#[DataProvider('cases')]
	public function testWriteMatchesTheRecording(array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['request.getParams' => []];
			return $case['body'];
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (...$args) {
			unset($args[1]['line'], $args[1]['file']);
			$this->calls[] = ['logger.error' => $args];
		});

		$controller = new ViewsController(
			'openregister',
			$request,
			$this->viewService($case),
			$this->createMock(ViewPresentationService::class),
			$logger,
			$this->resolver($case)
		);

		$response = match ($case['action']) {
			'create' => $controller->create(),
			'update' => $controller->update('7'),
			'patch' => $controller->patch('7'),
		};
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $actual);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function resolver(array $case): ViewerReachResolver {
		$resolver = $this->createMock(ViewerReachResolver::class);
		$resolver->method('currentUid')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['viewers.currentUid' => []];
			return $case['uid'] ?? 'alice';
		});
		$resolver->method('reachOf')->willReturnCallback(function (string $userId) {
			$this->calls[] = ['viewers.reachOf' => $userId];
			return new ViewerReach(userId: $userId, groups: [], isAdmin: false);
		});
		$resolver->method('reaches')->willReturnCallback(function (array $view, ViewerReach $reach) {
			$this->calls[] = ['viewers.reaches' => [$view['owner'] ?? null, $reach->userId]];
			return $reach->userId === 'alice' && ($view['owner'] === 'alice' || $view['name'] === 'reachable');
		});
		$resolver->method('refusedFields')->willReturnCallback(function (array $view, ViewerReach $reach, array $update) use ($case) {
			$this->calls[] = ['viewers.refusedFields' => [$view['owner'] ?? null, $reach->userId, $update]];
			if ($reach->userId !== 'alice') {
				return array_keys($update);
			}

			return $case['refused'] ?? [];
		});
		$resolver->method('assertMayNameRecipients')->willReturnCallback(function (array $view, ViewerReach $reach, array $data) use ($case) {
			$this->calls[] = ['viewers.assertMayNameRecipients' => [$view['owner'] ?? null, $reach->userId, $data]];
			if ($reach->userId !== 'alice') {
				throw new InvalidArgumentException('reach is not the acting user');
			}

			if (isset($case['recipientsThrow']) === true) {
				throw new InvalidArgumentException($case['recipientsThrow']);
			}
		});
		$resolver->method('shareFindings')->willReturnCallback(function ($sharedWith) use ($case) {
			$this->calls[] = ['viewers.shareFindings' => $sharedWith];
			return $case['findings'] ?? [];
		});
		return $resolver;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function viewService(array $case): ViewService {
		$service = $this->createMock(ViewService::class);
		$service->method('findById')->willReturnCallback(function ($id) use ($case) {
			$this->calls[] = ['viewService.findById' => $id];
			if (($case['missing'] ?? false) === true) {
				throw new DoesNotExistException('gone');
			}

			return $this->storedView($case['stored'] ?? []);
		});
		foreach (['create', 'update'] as $method) {
			$service->method($method)->willReturnCallback(function (...$args) use ($case, $method) {
				$this->calls[] = ['viewService.' . $method => $args];
				if (isset($case['serviceThrows']) === true) {
					throw new Exception($case['serviceThrows']);
				}

				$view = new View();
				$view->setName($args['name'] ?? $args[0] ?? 'saved');
				$view->setOwner('saved-owner');
				return $view;
			});
		}

		return $service;
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	private function storedView(array $stored): View {
		$view = new View();
		$view->setOwner($stored['owner'] ?? 'alice');
		if (($stored['bare'] ?? false) === true) {
			return $view;
		}

		$view->setName(($stored['reachable'] ?? false) === true ? 'reachable' : 'Stored');
		$view->setDescription('Stored description');
		$view->setIsPublic(true);
		$view->setIsDefault(true);
		$view->setQuery(['registers' => [5]]);
		$view->setPresentation(['type' => 'cards']);
		$view->setFavoredBy(['erin']);
		return $view;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
