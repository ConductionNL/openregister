<?php

/**
 * A macro reads its flow nodes, schema and declared flow tolerantly: anything unreadable or absent is empty or null.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\MacroActionResolver
 * @uses   \OCA\OpenRegister\Service\Flow\MacroRunChanges
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Flow\FlowSelection
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class MacroActionResolverReadsTest extends TestCase {

	/**
	 * A flow service that knows `flow-1` with one labelled node, and nothing else.
	 *
	 * @return FlowService The service.
	 */
	private function flows(): FlowService {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes([['id' => 'n1', 'type' => 'custom.step', 'label' => 'Zet status']]);

		$flows = $this->createMock(FlowService::class);
		$flows->method('find')->willReturnCallback(
			static function (string $uuid) use ($flow): Flow {
				if ($uuid !== 'flow-1') {
					throw new DoesNotExistException('no flow ' . $uuid);
				}

				return $flow;
			}
		);

		return $flows;
	}//end flows()

	/**
	 * The resolver over these collaborators.
	 *
	 * @param SchemaMapperInterface|null $schemas   The schemas, a bare mock when null.
	 * @param FlowMapperInterface|null   $flowStore The declared-flow store.
	 *
	 * @return MacroActionResolver The resolver.
	 */
	private function resolver(?SchemaMapperInterface $schemas = null, ?FlowMapperInterface $flowStore = null): MacroActionResolver {
		return new MacroActionResolver(
			$schemas ?? $this->createMock(SchemaMapperInterface::class),
			$this->flows(),
			$flowStore
		);
	}//end resolver()

	/**
	 * A readable flow gives its nodes; an unreadable one gives none.
	 *
	 * @return void
	 */
	public function testNodesOfReadsTheFlowOrGivesNone(): void {
		$resolver = $this->resolver();

		self::assertSame([['id' => 'n1', 'type' => 'custom.step', 'label' => 'Zet status']], $resolver->nodesOf(flowUuid: 'flow-1'));
		self::assertSame([], $resolver->nodesOf(flowUuid: 'gone'));
	}//end testNodesOfReadsTheFlowOrGivesNone()

	/**
	 * The changes of a run are its completed, labelled steps, named by their label.
	 *
	 * @return void
	 */
	public function testChangesOfNamesCompletedStepsByTheirLabel(): void {
		$run = new FlowRun();
		$run->setLog(
			[
				['transition' => 'n1', 'type' => 'custom.step', 'status' => 'completed'],
				['transition' => 'n1', 'type' => 'custom.step', 'status' => 'failed'],
				['transition' => 'n9', 'type' => 'custom.step', 'status' => 'completed'],
			]
		);

		self::assertSame([['step' => 'Zet status', 'kind' => 'step']], $this->resolver()->changesOf(run: $run, flowUuid: 'flow-1'));
		self::assertSame([], $this->resolver()->changesOf(run: $run, flowUuid: 'gone'));
	}//end testChangesOfNamesCompletedStepsByTheirLabel()

	/**
	 * Without a flow store no declared flow can be found.
	 *
	 * @return void
	 */
	public function testNoFlowStoreFindsNoDeclaredFlow(): void {
		$schema = new Schema();
		$schema->setSlug('zaak');

		self::assertNull($this->resolver()->declaredFlowUuid(schema: $schema, name: 'Opschorten'));
	}//end testNoFlowStoreFindsNoDeclaredFlow()

	/**
	 * A declaration with no stored flow, or a stored flow without a uuid, is null; the lookup uses the schema slug.
	 *
	 * @return void
	 */
	public function testAnUnstoredOrUuidlessDeclaredFlowIsNull(): void {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$store  = $this->createMock(FlowMapperInterface::class);
		$store->expects(self::exactly(2))->method('findDeclared')->with('Opschorten', 'zaak')
			->willReturnOnConsecutiveCalls(null, new Flow());
		$resolver = $this->resolver(flowStore: $store);

		self::assertNull($resolver->declaredFlowUuid(schema: $schema, name: 'Opschorten'));
		self::assertNull($resolver->declaredFlowUuid(schema: $schema, name: 'Opschorten'));
	}//end testAnUnstoredOrUuidlessDeclaredFlowIsNull()

	/**
	 * A schema loads without RBAC or multitenancy, and an unreadable one is null.
	 *
	 * @return void
	 */
	public function testLoadSchemaBypassesRbacAndToleratesFailure(): void {
		$schema  = new Schema();
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($schema): Schema {
				if ($id !== 'zaak' || $_rbac !== false || $_multitenancy !== false) {
					throw new RuntimeException('not found');
				}

				return $schema;
			}
		);
		$resolver = $this->resolver(schemas: $schemas);

		self::assertSame($schema, $resolver->loadSchema(schema: 'zaak'));
		self::assertNull($resolver->loadSchema(schema: 'onbekend'));
	}//end testLoadSchemaBypassesRbacAndToleratesFailure()
}//end class
