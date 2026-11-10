<?php

/**
 * Golden outcomes of the rules surface: refusals, the object guard and status mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RulesController;
use OCA\OpenRegister\Controller\Support\RulesControllerServices;
use OCA\OpenRegister\Controller\Support\RulesDomain;
use OCA\OpenRegister\Controller\Support\RulesMappers;
use OCA\OpenRegister\Controller\Support\DsarCasePlatform;
use OCA\OpenRegister\Controller\Support\RulesReplay;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\Rules\RuleCeilingException;
use OCA\OpenRegister\Service\Rules\RuleEnablementServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleInventoryServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleReplayServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleInventoryOptions;
use OCA\OpenRegister\Service\Rules\RuleTrialServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Rules\RuleTrialRequest;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins every RulesController outcome (status + body) a refactor could move.
 */
class RulesControllerA24GoldenTest extends TestCase {
	/**
	 * The params of the case.
	 *
	 * @var array
	 */
	private array $params = [];
	/**
	 * The schemas of the case.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;
	/**
	 * The inventory of the case.
	 *
	 * @var RuleInventoryServiceInterface&MockObject
	 */
	private RuleInventoryServiceInterface&MockObject $inventory;
	/**
	 * The runs of the case.
	 *
	 * @var RuleRunMapper&MockObject
	 */
	private RuleRunMapper&MockObject $runs;
	/**
	 * The trials of the case.
	 *
	 * @var RuleTrialServiceInterface&MockObject
	 */
	private RuleTrialServiceInterface&MockObject $trials;
	/**
	 * The enablement of the case.
	 *
	 * @var RuleEnablementServiceInterface&MockObject
	 */
	private RuleEnablementServiceInterface&MockObject $enablement;
	/**
	 * The replays of the case.
	 *
	 * @var RuleReplayServiceInterface&MockObject
	 */
	private RuleReplayServiceInterface&MockObject $replays;
	/**
	 * The userSession of the case.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;
	/**
	 * The controller of the case.
	 *
	 * @var RulesController
	 */
	private RulesController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->inventory = $this->createMock(RuleInventoryServiceInterface::class);
		$this->runs = $this->createMock(RuleRunMapper::class);
		$this->trials = $this->createMock(RuleTrialServiceInterface::class);
		$this->enablement = $this->createMock(RuleEnablementServiceInterface::class);
		$this->replays = $this->createMock(RuleReplayServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new RulesController(
			'openregister',
			$request,
			new RulesControllerServices(
				mappers: new RulesMappers(schemaMapper: $this->schemas, runs: $this->runs),
				domain: new RulesDomain(inventory: $this->inventory, trials: $this->trials, enablement: $this->enablement, vocabulary: new RuleVocabulary()),
				replay: new RulesReplay(replays: $this->replays),
				platform: new DsarCasePlatform(userSession: $this->userSession)
			)
		);
	}

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$this->schemas->method('find')->with('zaak')->willReturn($schema);
		return $schema;
	}

	private function pair($response): array {
		return [$response->getStatus(), $response->getData()];
	}

	public function testEveryActionButTheVocabularyIsAdminSettingGated(): void {
		foreach (['index', 'setEnabled', 'evaluate', 'replay', 'runs'] as $method) {
			$attributes = (new ReflectionMethod(RulesController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertSame(['settings' => OpenRegisterAdmin::class], $attributes[0]->getArguments());
		}
		$this->assertSame([], (new ReflectionMethod(RulesController::class, 'vocabulary'))->getAttributes(AuthorizedAdminSetting::class));
		$this->assertSame((new RuleVocabulary())->all(), $this->controller->vocabulary()->getData());
	}

	public function testAnUnreadableSchemaIsTheSame404Everywhere(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('rbac'));
		$expected = [404, ['ok' => false, 'error' => ['code' => 'rule-schema-not-found', 'message' => 'No schema "geheim".']]];

		$this->assertSame($expected, $this->pair($this->controller->index('geheim')));
		$this->assertSame($expected, $this->pair($this->controller->setEnabled('geheim', 'r')));
		$this->assertSame($expected, $this->pair($this->controller->evaluate('geheim', 'r')));
		$this->assertSame($expected, $this->pair($this->controller->replay('geheim', 'r')));
	}

	public function testIndexPassesWindowAndScope(): void {
		$schema = $this->schema();
		$this->params = ['idleDays' => '7', 'scope' => 'nope'];
		$this->inventory->expects($this->once())->method('inventory')->with($schema, new RuleInventoryOptions(7, null, []))->willReturn([['key' => 'a']]);

		$this->assertSame([200, ['schema' => 'zaak', 'rules' => [['key' => 'a']]]], $this->pair($this->controller->index('zaak')));
	}

	public function testSetEnabledNeedsABooleanAndMapsTheResult(): void {
		$schema = $this->schema();
		$this->params = ['enabled' => 'yes'];
		$this->assertSame(
			[422, ['ok' => false, 'error' => ['code' => 'rule-switch-no-state', 'message' => 'A boolean "enabled" is required.']]],
			$this->pair($this->controller->setEnabled('zaak', 'r'))
		);

		$this->params = ['enabled' => false];
		$unknown = ['ok' => false, 'error' => ['code' => RuleEnablementServiceInterface::CODE_UNKNOWN_RULE]];
		$this->enablement->method('setEnabled')->with($schema, 'r', false)->willReturnOnConsecutiveCalls(['ok' => true], $unknown, ['ok' => false]);
		$this->assertSame([200, ['ok' => true]], $this->pair($this->controller->setEnabled('zaak', 'r')));
		$this->assertSame([404, $unknown], $this->pair($this->controller->setEnabled('zaak', 'r')));
		$this->assertSame([422, ['ok' => false]], $this->pair($this->controller->setEnabled('zaak', 'r')));
	}

	public function testEvaluateAgainstAStoredObjectGoesThroughTheGuardedLookup(): void {
		$schema = $this->schema();
		$this->params = ['register' => 'zaken', 'objectId' => 'o1', 'rule' => ['x' => 1]];
		$hidden = ['ok' => false, 'error' => ['code' => RuleTrialServiceInterface::CODE_OBJECT_NOT_FOUND]];
		$this->trials->expects($this->once())->method('tryObject')->with(new RuleTrialRequest($schema, 'r', ['x' => 1]), new ObjectRef('zaken', (string)$schema->getId(), 'o1'))->willReturn($hidden);
		$this->trials->expects($this->never())->method('trySample');

		$this->assertSame([404, $hidden], $this->pair($this->controller->evaluate('zaak', 'r')));
	}

	public function testEvaluateWithoutACompleteObjectReferenceUsesTheSample(): void {
		$schema = $this->schema();
		$this->params = ['register' => 'zaken', 'objectId' => '', 'rule' => 'x', 'object' => 'bad'];
		$this->trials->expects($this->never())->method('tryObject');
		$this->trials->expects($this->once())->method('trySample')->with(new RuleTrialRequest($schema, 'r', null), [])->willReturn(['ok' => true]);

		$this->assertSame([200, ['ok' => true]], $this->pair($this->controller->evaluate('zaak', 'r')));
	}

	public function testAReplayNeedsAnActor(): void {
		$this->schema();
		$this->userSession->method('getUser')->willReturn(null);
		$this->replays->expects($this->never())->method('preview');

		$this->assertSame(
			[422, ['ok' => false, 'error' => ['code' => 'rule-replay-no-actor', 'message' => 'A replay is an act with an actor, and this request has none.']]],
			$this->pair($this->controller->replay('zaak', 'r'))
		);
	}

	public function testAReplayPreviewAndItsRefusals(): void {
		$schema = $this->schema();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->params = ['selection' => ['a' => 1], 'justification' => 'why', 'registerId' => '4'];
		$ceiling = new RuleCeilingException('too many', 'r', 10, 20);
		$this->replays->method('preview')->with($schema, 'r', ['a' => 1], 'alice', 'why', 4)->willReturnOnConsecutiveCalls(
			['ok' => true, 'job' => 1],
			$this->throwException($ceiling),
			$this->throwException(new BulkJobRefusedException('busy', 'job-running', ['jobId' => 3])),
			$this->throwException(new RuntimeException('kaboom'))
		);

		$this->assertSame([200, ['ok' => true, 'job' => 1]], $this->pair($this->controller->replay('zaak', 'r')));
		$this->assertSame([409, $ceiling->toArray()], $this->pair($this->controller->replay('zaak', 'r')));
		$this->assertSame(
			[409, ['ok' => false, 'error' => ['code' => 'job-running', 'message' => 'busy', 'jobId' => 3]]],
			$this->pair($this->controller->replay('zaak', 'r'))
		);
		$this->assertSame(
			[422, ['ok' => false, 'error' => ['code' => 'rule-replay-failed', 'message' => 'kaboom']]],
			$this->pair($this->controller->replay('zaak', 'r'))
		);
	}

	public function testAReplayWithLooseParamsPassesDefaults(): void {
		$schema = $this->schema();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->params = ['selection' => 'x', 'justification' => 3, 'registerId' => 'abc'];
		$this->replays->expects($this->once())->method('preview')->with($schema, 'r', [], 'alice', null, null)->willReturn(['ok' => false]);

		$this->assertSame([422, ['ok' => false]], $this->pair($this->controller->replay('zaak', 'r')));
	}

	public function testRunsRefusesAnUnknownVerdict(): void {
		$this->params = ['verdict' => 'maybe'];
		$this->runs->expects($this->never())->method('findByRule');

		$this->assertSame(
			[422, ['ok' => false, 'error' => ['code' => 'rule-runs-unknown-verdict', 'message' => '"maybe" is not a verdict this engine reaches.']]],
			$this->pair($this->controller->runs('r'))
		);
	}

	public function testRunsWithoutAVerdictAndWithBadMomentsListsEverything(): void {
		$this->params = ['verdict' => '', 'since' => 'not a date', 'until' => ' ', 'limit' => '5', 'offset' => '2'];
		$this->runs->expects($this->once())->method('findByRule')->with('r', null, null, null, 5, 2)->willReturn([]);
		$this->runs->expects($this->once())->method('countByRule')->with('r', null, null, null)->willReturn(0);

		$this->assertSame([200, ['ruleId' => 'r', 'total' => 0, 'runs' => []]], $this->pair($this->controller->runs('r')));
	}

	public function testRunsWithAKnownVerdictAndMomentsPassesThem(): void {
		$this->params = ['verdict' => 'fired', 'since' => '2020-01-01T00:00:00+00:00'];
		$this->runs->expects($this->once())->method('findByRule')
			->with('r', 'fired', $this->isInstanceOf(\DateTime::class), null, RuleRunMapper::DEFAULT_LIMIT, 0)
			->willReturn([]);
		$this->runs->method('countByRule')->willReturn(3);

		$this->assertSame(3, $this->controller->runs('r')->getData()['total']);
	}
}
