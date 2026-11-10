<?php

/**
 * Unit tests for OpenProjectProvider after Phase B-3 payload widening.
 *
 * The provider's `normalizeRow()` flattens the OpenProject hAL-style
 * `_links` / `_embedded` envelopes onto top-level keys (`type`,
 * `priority`, `assignee`, `project`, `subject`) so that
 * `CnOpenprojectTab` can render those columns without hand-walking
 * nested structures. This file pokes `normalizeRow()` via reflection
 * across three representative input shapes — flat (source pre-maps),
 * `_links` only, and `_embedded` only — and asserts the contract.
 *
 * Metadata + auth health are already covered by the leaf-providers
 * metadata aggregator; this test isolates the flattening regression
 * surface introduced by the widening commit.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-openproject/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for OpenProjectProvider.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OpenProjectProviderTest extends TestCase {

	/**
	 * Build a provider with stub collaborators.
	 *
	 * @return OpenProjectProvider
	 */
	private function buildProvider(): OpenProjectProvider {
		$router = $this->createMock(ExternalIntegrationRouter::class);
		$appManager = $this->createMock(IAppManager::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new OpenProjectProvider(router: $router, appManager: $appManager, l10n: $l10n);
	}//end buildProvider()

	/**
	 * Invoke the private normalizeRow() via reflection.
	 *
	 * @param OpenProjectProvider $provider Provider instance.
	 * @param array $row Raw source row.
	 *
	 * @return array Normalised row.
	 */
	private function normalize(OpenProjectProvider $provider, array $row): array {
		$method = new ReflectionMethod(OpenProjectProvider::class, 'normalizeRow');
		$method->setAccessible(true);
		return $method->invoke($provider, $row);
	}//end normalize()

	public function testNormaliseFlatRow(): void {
		$provider = $this->buildProvider();
		$row = [
			'id' => 17,
			'subject' => 'Implement export',
			'status' => 'In progress',
			'type' => 'Feature',
			'priority' => 'High',
			'assignee' => 'Alice',
			'project' => 'Customer Portal',
			'url' => 'https://op.example.org/wp/17',
		];

		$out = $this->normalize($provider, $row);
		self::assertSame('17', $out['id']);
		self::assertSame('Implement export', $out['title']);
		self::assertSame('Implement export', $out['subject']);
		self::assertSame('In progress', $out['status']);
		self::assertSame('Feature', $out['type']);
		self::assertSame('High', $out['priority']);
		self::assertSame('Alice', $out['assignee']);
		self::assertSame('Customer Portal', $out['project']);
		self::assertSame('https://op.example.org/wp/17', $out['url']);
	}//end testNormaliseFlatRow()

	public function testNormaliseFlattensLinksEnvelope(): void {
		$provider = $this->buildProvider();
		$row = [
			'id' => 8,
			'subject' => 'Refactor auth',
			'_links' => [
				'self' => ['href' => '/api/v3/work_packages/8'],
				'status' => ['title' => 'Open'],
				'type' => ['title' => 'Bug'],
				'priority' => ['title' => 'Normal'],
				'assignee' => ['title' => 'Bob'],
				'project' => ['title' => 'Internal Tools'],
			],
		];

		$out = $this->normalize($provider, $row);
		self::assertSame('Bug', $out['type']);
		self::assertSame('Normal', $out['priority']);
		self::assertSame('Bob', $out['assignee']);
		self::assertSame('Internal Tools', $out['project']);
		self::assertSame('Open', $out['status']);
		self::assertSame('/api/v3/work_packages/8', $out['url']);
	}//end testNormaliseFlattensLinksEnvelope()

	public function testNormaliseFlattensEmbeddedEnvelope(): void {
		$provider = $this->buildProvider();
		$row = [
			'id' => 9,
			'subject' => 'Onboarding flow',
			'_embedded' => [
				'type' => ['name' => 'Task'],
				'priority' => ['name' => 'Low'],
				'assignee' => ['name' => 'Carol'],
				'project' => ['name' => 'Marketing'],
			],
		];

		$out = $this->normalize($provider, $row);
		self::assertSame('Task', $out['type']);
		self::assertSame('Low', $out['priority']);
		self::assertSame('Carol', $out['assignee']);
		self::assertSame('Marketing', $out['project']);
	}//end testNormaliseFlattensEmbeddedEnvelope()

	public function testFlatKeysWinOverEnvelopes(): void {
		// When a source mapping pre-flattens, the explicit field on the
		// row root must take precedence over a stale `_links` label.
		$provider = $this->buildProvider();
		$row = [
			'id' => 1,
			'subject' => 'Migration',
			'type' => 'Epic',
			'_links' => ['type' => ['title' => 'Story']],
		];
		$out = $this->normalize($provider, $row);
		self::assertSame('Epic', $out['type']);
	}//end testFlatKeysWinOverEnvelopes()
}//end class
