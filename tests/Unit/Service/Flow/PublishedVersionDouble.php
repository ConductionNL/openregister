<?php

/**
 * A container answer for "which version of this flow is published?".
 *
 * 🔑 WHY EVERY QUEUE TEST NEEDS THIS. Since versioning, `FlowRunService::queue()`
 * refuses any flow with no published version — that refusal is the feature. A
 * unit test that builds the service by hand therefore has to say which version
 * is live, exactly as a real instance does, or it is testing the refusal
 * instead of the behaviour it names.
 *
 * The double deliberately answers ONE published version whose graph is the
 * fixture's own. A test that wants the refusal simply does not install it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use Psr\Log\NullLogger;

trait PublishedVersionDouble {
	/**
	 * A version mapper reporting version 1 published for every flow.
	 *
	 * @param string $hash The definition hash the version names.
	 *
	 * @return FlowVersionMapper The double.
	 */
	private function publishedVersionMapper(string $hash = 'test-hash'): FlowVersionMapper {
		$mapper = $this->createMock(FlowVersionMapper::class);

		$mapper->method('findPublished')->willReturnCallback(
			function (string $flowUuid) use ($hash): FlowVersion {
				$v = new FlowVersion();
				$v->setFlowUuid($flowUuid);
				$v->setVersion(1);
				$v->setStatus(FlowVersion::STATUS_PUBLISHED);
				$v->setDefinitionHash($hash);

				return $v;
			}
		);

		$mapper->method('find')->willReturnCallback(
			function (string $flowUuid, int $number) use ($hash): ?FlowVersion {
				if ($number !== 1) {
					return null;
				}

				$v = new FlowVersion();
				$v->setFlowUuid($flowUuid);
				$v->setVersion(1);
				$v->setStatus(FlowVersion::STATUS_PUBLISHED);
				$v->setDefinitionHash($hash);

				return $v;
			}
		);

		return $mapper;
	}//end publishedVersionMapper()

	/**
	 * A pin whose stored definition is the given graph.
	 *
	 * 🔑 THE DEFAULT DELIBERATELY CARRIES NONE OF THE FOUR GRAPH KEYS. These
	 * suites are about what a run DOES — suspending, resuming, carrying its
	 * items, acting as the right user — not about which graph is pinned. A
	 * definition with no `nodes`/`edges`/`limits`/`executionMode` makes
	 * `overlayOnto()` copy nothing, so the fixture's own document is what runs,
	 * while the real lookup path (version row -> hash -> definition) is still
	 * exercised rather than stubbed past. Which graph a pin selects has its own
	 * tests in FlowDefinitionPinTest and FlowVersionPinningTest.
	 *
	 * @param array  $graph The graph the hash resolves to.
	 * @param string $hash  The hash.
	 *
	 * @return FlowDefinitionPin The pin.
	 */
	private function pinReturning(array $graph = ['pinnedBy' => 'test'], string $hash = 'test-hash'): FlowDefinitionPin {
		$definitions = $this->createMock(FlowDefinitionMapper::class);

		$entity = new FlowDefinition();
		$entity->setHash($hash);
		$entity->setDefinition((string)json_encode($graph));

		$definitions->method('findByHash')->willReturnCallback(
			function (string $wanted) use ($hash, $entity): ?FlowDefinition {
				if ($wanted !== $hash) {
					return null;
				}

				return $entity;
			}
		);

		return new FlowDefinitionPin($definitions, new NullLogger());
	}//end pinReturning()
}//end trait
