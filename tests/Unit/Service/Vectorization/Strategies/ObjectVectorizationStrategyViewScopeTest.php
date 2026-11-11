<?php

/**
 * The vectoriser's view must actually bound what it indexes.
 *
 * This strategy runs inside a background job, so there is no session. It asks
 * for its objects with `_rbac: false` and `_multitenancy: false`, which leaves
 * the view as the only bound on the query — and without `_viewScopeRequired`
 * the shared search path resolved that view under RBAC, was denied for want of
 * a user, logged the refusal and carried on with the query unchanged. The
 * vectoriser then indexed whatever the unbounded query returned rather than the
 * view it was configured with.
 *
 * The test pins the argument rather than the outcome, deliberately. A search
 * returning rows proves nothing here: the unfixed code returned rows too, just
 * the wrong ones. What distinguishes the two is the flag on the call.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vectorization\Strategies
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Strategies;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ObjectVectorizationStrategyViewScopeTest extends TestCase {

	private ObjectService&MockObject $objects;

	private ObjectVectorizationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->strategy = new ObjectVectorizationStrategy(
			$this->objects,
			$this->createMock(SettingsService::class),
			new NullLogger()
		);
	}

	public function testTheViewIsLoadBearingSoAFailureToApplyItIsFatal(): void {
		// THE REGRESSION TEST. Without the flag the view was logged and skipped
		// and the job indexed an unbounded result set.
		$this->objects->expects($this->once())
			->method('searchObjects')
			->with(
				$this->anything(),
				false,
				false,
				null,
				null,
				['view-uuid'],
				true
			)
			->willReturn([]);

		$this->strategy->fetchEntities(['views' => ['view-uuid'], 'batch_size' => 10]);
	}

	public function testARefusalToApplyTheViewReachesTheCallerRatherThanBeingSwallowed(): void {
		// The job must fail rather than index the wrong corpus.
		$this->objects->method('searchObjects')
			->willThrowException(new \RuntimeException('view no longer resolves'));

		$this->expectException(\RuntimeException::class);

		$this->strategy->fetchEntities(['views' => ['view-uuid'], 'batch_size' => 10]);
	}
}//end class
