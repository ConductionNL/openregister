<?php

/**
 * Pins the released signatures fleet apps still implement or call.
 *
 * Apps on the last release implement lifecycle actions, flow nodes, bulk
 * actions and integration providers in the released positional form, and
 * call released AuditTrailMapper and TaskService methods. A base interface
 * that declares the newer parameter-object form makes those classes fatal at
 * load time; a removed method makes those calls fail. This test fails when
 * either comes back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\LegacyBulkActionInterface;
use OCA\OpenRegister\BulkAction\StepBulkActionInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Lifecycle\ContextLifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LegacyLifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\Flow\ICallFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\ILegacyFlowNode;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\LegacyIntegrationProvider;
use OCA\OpenRegister\Service\Integration\RefIntegrationProvider;
use OCA\OpenRegister\Service\TaskService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

class ReleasedSignatureGuardTest extends TestCase {

	/**
	 * Base types that must not declare a form-specific method.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function formFreeBases(): array {
		$cases = [
			'LifecycleActionInterface::execute' => [LifecycleActionInterface::class, 'execute'],
			'IFlowNode::execute'                => [IFlowNode::class, 'execute'],
			'BulkActionInterface::apply'        => [BulkActionInterface::class, 'apply'],
		];
		foreach (['list', 'get', 'create', 'update', 'delete'] as $operation) {
			$cases['IntegrationProvider::'.$operation]         = [IntegrationProvider::class, $operation];
			$cases['AbstractIntegrationProvider::'.$operation] = [AbstractIntegrationProvider::class, $operation];
		}

		return $cases;
	}

	/**
	 * The signatures fleet apps implement or call, as `(params): return`.
	 *
	 * @return array<string, array{class-string, string, string}>
	 */
	public static function pinnedSignatures(): array {
		return [
			'released lifecycle action' => [LegacyLifecycleActionInterface::class, 'execute', '(array $objectData, array $previousData, array $parameters, string $actionName): array'],
			'context lifecycle action'  => [ContextLifecycleActionInterface::class, 'execute', '(OCA\OpenRegister\Lifecycle\LifecycleActionContext $context): array'],
			'released flow node'        => [ILegacyFlowNode::class, 'execute', '(array $items, array $config, array $context): array'],
			'call flow node'            => [ICallFlowNode::class, 'execute', '(OCA\OpenRegister\Service\Flow\FlowNodeCall $call): array'],
			'released bulk action'      => [LegacyBulkActionInterface::class, 'apply', '(OCA\OpenRegister\Db\ObjectEntity $object, array $parameters, bool $commit, ?OCP\IUser $actor = null): OCA\OpenRegister\BulkAction\BulkActionResult'],
			'step bulk action'          => [StepBulkActionInterface::class, 'apply', '(OCA\OpenRegister\BulkAction\BulkStep $step): OCA\OpenRegister\BulkAction\BulkActionResult'],
			'released provider list'    => [LegacyIntegrationProvider::class, 'list', '(string $register, string $schema, string $objectId, array $filters = []): array'],
			'released provider get'     => [LegacyIntegrationProvider::class, 'get', '(string $register, string $schema, string $objectId, string $entityId): array'],
			'released provider create'  => [LegacyIntegrationProvider::class, 'create', '(string $register, string $schema, string $objectId, array $payload): array'],
			'released provider update'  => [LegacyIntegrationProvider::class, 'update', '(string $register, string $schema, string $objectId, string $entityId, array $payload): array'],
			'released provider delete'  => [LegacyIntegrationProvider::class, 'delete', '(string $register, string $schema, string $objectId, string $entityId): void'],
			'ref provider list'         => [RefIntegrationProvider::class, 'list', '(OCA\OpenRegister\Kernel\Contracts\ObjectRef $ref, array $filters = []): array'],
			'ref provider get'          => [RefIntegrationProvider::class, 'get', '(OCA\OpenRegister\Kernel\Contracts\ObjectRef $ref, string $entityId): array'],
			'ref provider create'       => [RefIntegrationProvider::class, 'create', '(OCA\OpenRegister\Kernel\Contracts\ObjectRef $ref, array $payload): array'],
			'ref provider update'       => [RefIntegrationProvider::class, 'update', '(OCA\OpenRegister\Kernel\Contracts\ObjectRef $ref, string $entityId, array $payload): array'],
			'ref provider delete'       => [RefIntegrationProvider::class, 'delete', '(OCA\OpenRegister\Kernel\Contracts\ObjectRef $ref, string $entityId): void'],
			'AuditTrailMapper::findAll' => [AuditTrailMapper::class, 'findAll', "(?int \$limit = null, ?int \$offset = null, ?array \$filters = [], ?array \$sort = {\"created\":\"DESC\"}, ?string \$search = null): array"],
			'AuditTrailMapper::getActionChartData' => [AuditTrailMapper::class, 'getActionChartData', '(?DateTime $from = null, ?DateTime $till = null, ?int $registerId = null, ?int $schemaId = null): array'],
			'TaskService::getAllUserTasks' => [TaskService::class, 'getAllUserTasks', '(?string $status = null, int $limit = 50, int $offset = 0): array'],
			'TaskService::createTask'      => [TaskService::class, 'createTask', '(int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data): ?array'],
			'TaskService::updateTask'      => [TaskService::class, 'updateTask', '(string $calendarId, string $taskUri, array $data): ?array'],
		];
	}

	#[DataProvider('formFreeBases')]
	public function testBaseDeclaresNoFormSpecificMethod(string $class, string $method): void {
		$this->assertFalse(
			(new ReflectionClass($class))->hasMethod($method),
			sprintf('%s::%s() is declared again; implementers of the other published form would no longer load.', $class, $method)
		);
	}

	#[DataProvider('pinnedSignatures')]
	public function testSignatureIsPinned(string $class, string $method, string $expected): void {
		$this->assertSame($expected, $this->signature(new ReflectionMethod($class, $method)));
	}

	public function testFormInterfacesExtendTheirBase(): void {
		$this->assertTrue(is_subclass_of(LegacyLifecycleActionInterface::class, LifecycleActionInterface::class));
		$this->assertTrue(is_subclass_of(ContextLifecycleActionInterface::class, LifecycleActionInterface::class));
		$this->assertTrue(is_subclass_of(ILegacyFlowNode::class, IFlowNode::class));
		$this->assertTrue(is_subclass_of(ICallFlowNode::class, IFlowNode::class));
		$this->assertTrue(is_subclass_of(LegacyBulkActionInterface::class, BulkActionInterface::class));
		$this->assertTrue(is_subclass_of(StepBulkActionInterface::class, BulkActionInterface::class));
		$this->assertTrue(is_subclass_of(LegacyIntegrationProvider::class, IntegrationProvider::class));
		$this->assertTrue(is_subclass_of(RefIntegrationProvider::class, IntegrationProvider::class));
	}

	private function signature(ReflectionMethod $method): string {
		$parameters = array_map(fn (ReflectionParameter $p): string => $this->parameter($p), $method->getParameters());

		return '('.implode(', ', $parameters).'): '.$this->type($method->getReturnType());
	}

	private function parameter(ReflectionParameter $parameter): string {
		$text = trim($this->type($parameter->getType()).' $'.$parameter->getName());
		if ($parameter->isDefaultValueAvailable() === true) {
			$text .= ' = '.json_encode($parameter->getDefaultValue());
		}

		return $text;
	}

	private function type(?\ReflectionType $type): string {
		if ($type instanceof ReflectionNamedType && $type->allowsNull() === true && $type->getName() !== 'mixed' && $type->getName() !== 'null') {
			return '?'.$type->getName();
		}

		return (string)$type;
	}
}
