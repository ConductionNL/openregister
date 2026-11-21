<?php

/**
 * Unit test for FlowNodeTaxonomyResolver: a declared kind and category are
 * served when they are in the vocabulary, the fallback with a warning when not,
 * and the fallback without one when the node declares nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeTaxonomyResolver;
use OCA\OpenRegister\Service\Flow\IFlowEndNode;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeTaxonomyResolver
 * @covers \OCA\OpenRegister\Service\Flow\FlowTaxonomyVocabulary
 */
class FlowNodeTaxonomyResolverBehaviourTest extends TestCase {

	/**
	 * A node declaring the given kind and category.
	 *
	 * @param string $kind     The declared kind.
	 * @param string $category The declared category.
	 *
	 * @return IFlowNode
	 */
	private function declaring(string $kind, string $category): IFlowNode {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeTaxonomy::class]);
		$node->method('getId')->willReturn('x.node');
		$node->method('getKind')->willReturn($kind);
		$node->method('getCategory')->willReturn($category);
		return $node;
	}//end declaring()

	/**
	 * A declared kind and category in the vocabulary are served without a warning.
	 *
	 * @return void
	 */
	public function testAValidDeclarationIsServed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$resolver = new FlowNodeTaxonomyResolver(logger: $logger);
		$node     = $this->declaring(kind: IFlowNodeTaxonomy::KIND_USER_TASK, category: IFlowNodeTaxonomy::CATEGORY_HUMAN);

		$this->assertSame('userTask', $resolver->kindOf(node: $node));
		$this->assertSame('human', $resolver->categoryOf(node: $node));
	}//end testAValidDeclarationIsServed()

	/**
	 * A declared kind or category outside the vocabulary is served as the fallback, with a warning naming it.
	 *
	 * @return void
	 */
	public function testAnInvalidDeclarationFallsBackWithAWarning(): void {
		$warnings = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context['type']];
			}
		);
		$resolver = new FlowNodeTaxonomyResolver(logger: $logger);
		$node     = $this->declaring(kind: 'robotTask', category: 'magic');

		$this->assertSame('serviceTask', $resolver->kindOf(node: $node));
		$this->assertSame('other', $resolver->categoryOf(node: $node));
		$this->assertCount(2, $warnings);
		$this->assertStringContainsString('The node "x.node" declared the kind "robotTask", which is not one of: ', $warnings[0][0]);
		$this->assertStringEndsWith('. Serving "serviceTask".', $warnings[0][0]);
		$this->assertStringContainsString('declared the category "magic"', $warnings[1][0]);
		$this->assertStringEndsWith('Serving "other".', $warnings[1][0]);
		$this->assertSame('x.node', $warnings[0][1]);
	}//end testAnInvalidDeclarationFallsBackWithAWarning()

	/**
	 * A node that declares nothing gets the fallbacks silently, and its role follows its marker interfaces.
	 *
	 * @return void
	 */
	public function testAnUndeclaringNodeGetsTheFallbacksAndARole(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$resolver = new FlowNodeTaxonomyResolver(logger: $logger);
		$plain    = $this->createMock(IFlowNode::class);
		$trigger  = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowTriggerNode::class]);
		$end      = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowEndNode::class]);

		$this->assertSame('serviceTask', $resolver->kindOf(node: $plain));
		$this->assertSame('other', $resolver->categoryOf(node: $plain));
		$this->assertSame('step', $resolver->roleOf(node: $plain));
		$this->assertSame('trigger', $resolver->roleOf(node: $trigger));
		$this->assertSame('end', $resolver->roleOf(node: $end));
		$this->assertTrue($resolver->isEnd(node: $end));
		$this->assertFalse($resolver->isTrigger(node: $end));
	}//end testAnUndeclaringNodeGetsTheFallbacksAndARole()
}//end class
