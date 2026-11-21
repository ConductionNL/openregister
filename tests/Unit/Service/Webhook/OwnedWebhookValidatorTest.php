<?php

/**
 * What an owned webhook may be (webhooks-for-owners, D-4).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Webhook;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Webhook\OwnedWebhookValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each refusal names its field; a good subscription is stored with its scope as ids.
 */
#[CoversClass(OwnedWebhookValidator::class)]
#[UsesClass(Webhook::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
class OwnedWebhookValidatorTest extends TestCase {

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private PermissionCheckInterface&MockObject $permissions;

	private WebhookMapperInterface&MockObject $webhooks;

	private IAppConfig&MockObject $appConfig;

	private OwnedWebhookValidator $validator;

	protected function setUp(): void {
		parent::setUp();

		$this->registers   = $this->createMock(RegisterMapperInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->webhooks    = $this->createMock(WebhookMapperInterface::class);
		$this->appConfig   = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default) => $default
		);

		$register = new Register();
		$register->setId(7);
		$this->registers->method('find')->willReturnCallback(
			function (string|int $id) use ($register) {
				if ((string)$id === '7' || $id === 'planninq') {
					return $register;
				}

				throw new DoesNotExistException('no register');
			}
		);

		$this->schemas->method('find')->willReturnCallback(
			function (string|int $id) {
				$ids = ['task' => 11, '11' => 11, 'budget' => 12, '12' => 12];
				if (isset($ids[(string)$id]) === false) {
					throw new DoesNotExistException('no schema');
				}

				$schema = new Schema();
				$schema->setId($ids[(string)$id]);
				return $schema;
			}
		);

		$this->validator = new OwnedWebhookValidator(
			registers: $this->registers,
			schemas: $this->schemas,
			permissions: $this->permissions,
			webhooks: $this->webhooks,
			appConfig: $this->appConfig,
		);
	}//end setUp()

	/**
	 * A subscription the owner may have.
	 *
	 * @return array<string, mixed>
	 */
	private function good(): array {
		return [
			'name' => 'Tasks',
			'url' => 'https://receiver.example/hook',
			'events' => [ObjectCreatedEvent::class, ObjectUpdatedEvent::class],
			'filters' => ['register' => 'planninq', 'schema' => 'task'],
		];
	}//end good()

	public function testAGoodSubscriptionIsAcceptedWithItsScopeAsIds(): void {
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->isInstanceOf(Schema::class), 'read', 'alice', null, true, null, '7')
			->willReturn(true);

		$checked = $this->validator->check(data: $this->good(), owner: 'alice', creating: true);

		$this->assertNull($checked['refusal']);
		$this->assertSame(['register' => '7', 'schema' => ['11']], $checked['data']['filters']);
		$this->assertSame('https://receiver.example/hook', $checked['data']['url']);
	}//end testAGoodSubscriptionIsAcceptedWithItsScopeAsIds()

	public function testEverySchemaIsCheckedAndListedOnce(): void {
		$this->permissions->expects($this->exactly(3))->method('hasPermission')->willReturn(true);
		$data = $this->good();
		$data['filters']['schema'] = ['task', 12, '11'];
		$data['events'] = [ObjectDeletedEvent::class];

		$checked = $this->validator->check(data: $data, owner: 'alice', creating: false);

		$this->assertNull($checked['refusal']);
		$this->assertSame(['11', '12'], $checked['data']['filters']['schema']);
	}//end testEverySchemaIsCheckedAndListedOnce()

	/**
	 * Refusals and the field each must name.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refusals(): array {
		$good = [
			'events' => [ObjectCreatedEvent::class],
			'filters' => ['register' => 'planninq', 'schema' => 'task'],
		];

		return [
			'empty events' => [['events' => []] + $good, 'events'],
			'no events' => [['events' => null] + $good, 'events'],
			'an instance event' => [['events' => [ObjectCreatedEvent::class, SchemaCreatedEvent::class]] + $good, 'events'],
			'no filters' => [['filters' => null] + $good, 'filters.register'],
			'no register' => [['filters' => ['schema' => 'task']] + $good, 'filters.register'],
			'a list of registers' => [['filters' => ['register' => ['planninq', 8], 'schema' => 'task']] + $good, 'filters.register'],
			'an unknown register' => [['filters' => ['register' => 'nope', 'schema' => 'task']] + $good, 'filters.register'],
			'no schema' => [['filters' => ['register' => 'planninq']] + $good, 'filters.schema'],
			'an empty schema list' => [['filters' => ['register' => 'planninq', 'schema' => []]] + $good, 'filters.schema'],
			'an unknown schema' => [['filters' => ['register' => 'planninq', 'schema' => ['task', 'nope']]] + $good, 'filters.schema'],
			'interception' => [['configuration' => ['interceptRequests' => true]] + $good, 'configuration.interceptRequests'],
			'private targets' => [['configuration' => ['allowPrivateTargets' => 'yes']] + $good, 'configuration.allowPrivateTargets'],
			'configuration not an object' => [['configuration' => 5] + $good, 'configuration'],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testARefusalNamesItsField(array $data, string $field): void {
		$this->permissions->method('hasPermission')->willReturn(true);

		$checked = $this->validator->check(data: $data, owner: 'alice', creating: false);

		$this->assertSame($field, $checked['refusal']['field'] ?? null);
		$this->assertStringStartsWith($field . ': ', $checked['refusal']['message']);
	}//end testARefusalNamesItsField()

	public function testExplicitlyOffAdminOnlyConfigurationIsAccepted(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$data = $this->good();
		$data['configuration'] = json_encode(['interceptRequests' => false, 'allowPrivateTargets' => null, 'timeout' => 5]);

		$this->assertNull($this->validator->check(data: $data, owner: 'alice', creating: false)['refusal']);
	}//end testExplicitlyOffAdminOnlyConfigurationIsAccepted()

	public function testASchemaTheOwnerCannotReadIsRefused(): void {
		$this->permissions->method('hasPermission')->willReturn(false);

		$checked = $this->validator->check(data: $this->good(), owner: 'alice', creating: false);

		$this->assertSame('filters.schema', $checked['refusal']['field']);
	}//end testASchemaTheOwnerCannotReadIsRefused()

	public function testAPermissionCheckThatFailsRefuses(): void {
		$this->permissions->method('hasPermission')->willThrowException(new \RuntimeException('no session'));

		$checked = $this->validator->check(data: $this->good(), owner: 'alice', creating: false);

		$this->assertSame('filters.schema', $checked['refusal']['field']);
	}//end testAPermissionCheckThatFailsRefuses()

	public function testTheTwentyFirstWebhookIsRefused(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->webhooks->expects($this->once())->method('findOwnedBy')->with('alice')
			->willReturn(array_fill(0, 20, new Webhook()));

		$checked = $this->validator->check(data: $this->good(), owner: 'alice', creating: true);

		$this->assertSame('owner', $checked['refusal']['field']);
		$this->assertStringContainsString('20', $checked['refusal']['message']);
	}//end testTheTwentyFirstWebhookIsRefused()

	public function testTheCapIsReadFromAppConfigAndNotCountedOnUpdate(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueInt')->with('openregister', 'webhookOwnerMax', 20)->willReturn(1);
		$this->webhooks->method('findOwnedBy')->willReturn([new Webhook()]);
		$this->permissions->method('hasPermission')->willReturn(true);
		$validator = new OwnedWebhookValidator(
			registers: $this->registers,
			schemas: $this->schemas,
			permissions: $this->permissions,
			webhooks: $this->webhooks,
			appConfig: $appConfig,
		);

		$this->assertSame('owner', $validator->check(data: $this->good(), owner: 'alice', creating: true)['refusal']['field']);
		$this->assertNull($validator->check(data: $this->good(), owner: 'alice', creating: false)['refusal']);
	}//end testTheCapIsReadFromAppConfigAndNotCountedOnUpdate()

	public function testACountThatFailsRefuses(): void {
		$this->webhooks->method('findOwnedBy')->willThrowException(new \RuntimeException('db down'));

		$checked = $this->validator->check(data: $this->good(), owner: 'alice', creating: true);

		$this->assertSame('owner', $checked['refusal']['field']);
	}//end testACountThatFailsRefuses()
}//end class
