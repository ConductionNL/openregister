<?php

/**
 * The rule inventory endpoint passes its scope filter through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RulesController;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Rules\RuleEnablementService;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleReplayService;
use OCA\OpenRegister\Service\Rules\RuleTrialService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `GET /api/schemas/{schema}/rules?scope[caseType]=a`.
 *
 * @package OCA\OpenRegister\Tests\Unit\Controller
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class RulesControllerScopeTest extends TestCase {

	/**
	 * The controller over a schema with one unscoped and two scoped calculations.
	 *
	 * @param mixed $scope What the request carries as `scope`.
	 *
	 * @return RulesController The controller.
	 */
	private function controller(mixed $scope): RulesController {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties([]);
		$schema->setConfiguration(
			[
				'x-openregister-calculations' => [
					'algemeen' => ['type' => 'string', 'expression' => ['prop' => 'a']],
					'voorA' => ['type' => 'string', 'expression' => ['prop' => 'a'], 'scope' => ['property' => 'caseType', 'value' => 'a']],
					'voorB' => ['type' => 'string', 'expression' => ['prop' => 'a'], 'scope' => ['property' => 'caseType', 'value' => 'b']],
				],
			]
		);

		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'scope' ? $scope : $default)
		);

		$inventory = new RuleInventoryService(
			triggers: $this->createMock(originalClassName: FlowTriggerMapper::class),
			summaries: $this->createMock(originalClassName: RuleRunSummaryMapper::class),
			propertyCalculations: new PropertyCalculations(),
			vocabulary: new RuleVocabulary()
		);

		return new RulesController(
			appName: 'openregister',
			request: $request,
			schemaMapper: $schemas,
			inventory: $inventory,
			runs: $this->createMock(originalClassName: RuleRunMapper::class),
			trials: (new ReflectionClass(RuleTrialService::class))->newInstanceWithoutConstructor(),
			enablement: (new ReflectionClass(RuleEnablementService::class))->newInstanceWithoutConstructor(),
			vocabulary: new RuleVocabulary(),
			replays: (new ReflectionClass(RuleReplayService::class))->newInstanceWithoutConstructor(),
			userSession: $this->createMock(originalClassName: IUserSession::class)
		);

	}//end controller()

	/**
	 * The keys of the rules the endpoint returns.
	 *
	 * @param mixed $scope What the request carries as `scope`.
	 *
	 * @return array<int, string> The rule keys.
	 */
	private function keys(mixed $scope): array {
		return array_column($this->controller(scope: $scope)->index(schema: 'zaak')->getData()['rules'], 'key');

	}//end keys()

	/**
	 * `scope[caseType]=a` lists case type a's rule and the schema-wide one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testTheScopeParameterNarrowsTheInventory(): void {
		$this->assertSame(expected: ['algemeen', 'voorA'], actual: $this->keys(scope: ['caseType' => 'a']));

	}//end testTheScopeParameterNarrowsTheInventory()

	/**
	 * No scope, or a scope that is not a map, lists every rule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testWithoutAUsableScopeEveryRuleIsListed(): void {
		$this->assertSame(expected: ['algemeen', 'voorA', 'voorB'], actual: $this->keys(scope: []));
		$this->assertSame(expected: ['algemeen', 'voorA', 'voorB'], actual: $this->keys(scope: 'a'));

	}//end testWithoutAUsableScopeEveryRuleIsListed()
}//end class
