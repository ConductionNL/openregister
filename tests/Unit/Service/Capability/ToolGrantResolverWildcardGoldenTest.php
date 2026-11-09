<?php

/**
 * Golden outcomes of ToolGrantResolver's schema-wildcard expansion and waiver check.
 *
 * Pins which ids a wildcard grant allows and which it refuses against a
 * catalog that holds only some of a schema's verbs, and when a waiver applies.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Capability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for wildcard expansion against a partial catalog, and waivers.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
 */
class ToolGrantResolverWildcardGoldenTest extends TestCase {

	/**
	 * A catalog holding only some verbs of one schema, listed out of verb order.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function partialCatalog(): array {
		return [
			['mcpId' => 'pipelinq.lead.delete'],
			['mcpId' => 'pipelinq.lead.get'],
			['mcpId' => 'pipelinq.leadnote.search'],
			['mcpId' => 'pipelinq.lead.update'],
		];
	}//end partialCatalog()

	/**
	 * A read wildcard allows the read verbs the catalog has, in verb order, and nothing else.
	 *
	 * @return void
	 */
	public function testReadWildcardAllowsOnlyCatalogReadVerbs(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(['pipelinq.lead.get'], $resolver->resolve(['pipelinq.lead.*'], $this->partialCatalog()));
	}//end testReadWildcardAllowsOnlyCatalogReadVerbs()

	/**
	 * A write wildcard allows every catalog verb of the schema, in verb order.
	 *
	 * @return void
	 */
	public function testWriteWildcardAllowsCatalogVerbsInVerbOrder(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame(
			['pipelinq.lead.get', 'pipelinq.lead.update', 'pipelinq.lead.delete'],
			$resolver->resolve(['pipelinq.lead.*:write'], $this->partialCatalog())
		);
	}//end testWriteWildcardAllowsCatalogVerbsInVerbOrder()

	/**
	 * A wildcard over a schema the catalog does not hold, or a prefix of one, allows nothing.
	 *
	 * @return void
	 */
	public function testWildcardOverAnUnknownSchemaAllowsNothing(): void {
		$resolver = new ToolGrantResolver();

		$this->assertSame([], $resolver->resolve(['pipelinq.contact.*:write'], $this->partialCatalog()));
		$this->assertSame([], $resolver->resolve(['pipelinq.lea.*:write'], $this->partialCatalog()));
	}//end testWildcardOverAnUnknownSchemaAllowsNothing()

	/**
	 * A waiver applies only to a waived tool whose arguments satisfy one waived alternative.
	 *
	 * @return void
	 */
	public function testWaiverAppliesOnlyToASatisfiedWaivedAlternative(): void {
		$sets = ['pipelinq.lead.update' => [['status' => ['mode' => 'pin', 'values' => ['won']]]], 'pipelinq.lead.delete' => [[]]];

		$this->assertTrue(ToolGrantResolver::waives($sets, 'pipelinq.lead.update', ['status' => 'won']));
		$this->assertFalse(ToolGrantResolver::waives($sets, 'pipelinq.lead.update', ['status' => 'lost']));
		$this->assertFalse(ToolGrantResolver::waives($sets, 'pipelinq.lead.update', []));
		$this->assertTrue(ToolGrantResolver::waives($sets, 'pipelinq.lead.delete', ['id' => 'x']));
		$this->assertFalse(ToolGrantResolver::waives($sets, 'pipelinq.lead.get', []));
		$this->assertFalse(ToolGrantResolver::waives(['pipelinq.lead.get' => []], 'pipelinq.lead.get', []));
	}//end testWaiverAppliesOnlyToASatisfiedWaivedAlternative()
}//end class
