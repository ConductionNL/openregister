<?php

/**
 * Golden snapshots of a flow node's observable surface.
 *
 * A node is read through every method its callers use — palette metadata,
 * scope availability, taxonomy, config vocabulary, config form, and the
 * outcome of validating a set of configs — and the result is compared,
 * key order included, with a JSON file recorded on the code BEFORE a
 * refactor. A refactor that keeps that file green changed no behaviour the
 * palette, preflight or engine can see.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCP\IL10N;
use OCP\IURLGenerator;
use Throwable;

/**
 * Builds and compares node surface snapshots. Used by the golden node tests.
 */
trait NodeSurfaceSnapshot {

	/**
	 * An IL10N whose t() returns the text with its parameters appended, so a
	 * snapshot pins both the source string and what was substituted into it.
	 *
	 * @return IL10N The stub.
	 */
	private function snapshotL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text . (((array)$parameters) === [] ? '' : ' ' . json_encode((array)$parameters))
		);

		return $l10n;
	}//end snapshotL10n()

	/**
	 * An IURLGenerator whose imagePath() returns "app:path".
	 *
	 * @return IURLGenerator The stub.
	 */
	private function snapshotUrls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(
			static fn (string $app, string $path): string => $app . ':' . $path
		);

		return $urls;
	}//end snapshotUrls()

	/**
	 * The outcome of a call: its return value, or the class and message it threw.
	 *
	 * @param callable $call The call.
	 *
	 * @return array The outcome.
	 */
	private function outcome(callable $call): array {
		try {
			return ['returned' => $call()];
		} catch (Throwable $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end outcome()

	/**
	 * The surface of one node.
	 *
	 * @param object $node    The node.
	 * @param array  $configs Configs to validate, keyed by a label.
	 *
	 * @return array The surface.
	 */
	private function surface(object $node, array $configs): array {
		$interfaces = array_values(class_implements($node));
		sort($interfaces);
		$surface = [
			'class' => get_class($node),
			'interfaces' => $interfaces,
			'id' => $node->getId(),
			'name' => $node->getDisplayName(),
			'description' => $node->getDescription(),
			'icon' => $node->getIcon(),
			'scopes' => array_map(static fn (int $scope): bool => $node->isAvailableForScope($scope), [0, 1, 2, 3]),
		];
		foreach (['getKind', 'getCategory', 'configKeys', 'configForm', 'isTrigger', 'isEnd'] as $method) {
			if (method_exists($node, $method) === true) {
				$surface[$method] = $this->outcome(static fn () => $node->$method());
			}
		}

		foreach ($configs as $label => $config) {
			$surface['validate'][$label] = $this->outcome(static fn () => $node->validateConfig($config));
		}

		return $surface;
	}//end surface()

	/**
	 * Compare a value with its recorded golden file, key order included.
	 *
	 * @param string $name   The golden file's base name.
	 * @param array  $actual The value now.
	 *
	 * @return void
	 */
	private function assertGolden(string $name, array $actual): void {
		$file = __DIR__ . '/Golden/' . $name . '.json';
		$encoded = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('OR_GOLDEN_RECORD') === '1' && is_file($file) === false) {
			file_put_contents($file, $encoded . "\n");
		}

		$this->assertFileExists($file, 'golden file missing: record it on the code before the refactor');
		$this->assertSame(
			json_decode((string)file_get_contents($file), true),
			json_decode((string)$encoded, true),
			$name . ' drifted from its golden recording'
		);
	}//end assertGolden()
}//end trait
