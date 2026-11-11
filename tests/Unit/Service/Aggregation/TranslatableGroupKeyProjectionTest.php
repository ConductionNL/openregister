<?php

/**
 * Characterisation tests for TranslatableGroupKeyProjection: which group keys are resolved and which are left as-is.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\TranslatableGroupKeyProjection;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Object\TranslationScope;
use PHPUnit\Framework\TestCase;

final class TranslatableGroupKeyProjectionTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $resolved = [];

	private function projection(array $fields): TranslatableGroupKeyProjection {
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('resolveTranslationsForRender')->willReturnCallback(
			function (array $objectData, TranslationScope $scope): array {
				$this->resolved[] = $objectData;
				$out = [];
				foreach ($objectData as $field => $map) {
					if (isset($map['nl']) === true) {
						$out[$field] = $map['nl'];
					}
				}

				return $out;
			}
		);
		return new TranslatableGroupKeyProjection($translations, new Schema(), new Register(), $fields);
	}

	public function testJsonStringMapIsDecodedAndResolved(): void {
		$groups = $this->projection(['title'])->projectGroups([['key' => '{"nl":"Hallo","en":"Hello"}', 'count' => 2]]);
		$this->assertSame([['key' => 'Hallo', 'count' => 2]], $groups);
		$this->assertSame([['title' => ['nl' => 'Hallo', 'en' => 'Hello']]], $this->resolved);
	}

	public function testArrayMapIsResolved(): void {
		$groups = $this->projection(['title'])->projectGroups([['key' => ['nl' => 'Hallo']]]);
		$this->assertSame([['key' => 'Hallo']], $groups);
	}

	public function testPlainStringIsLeftUntouched(): void {
		$groups = $this->projection(['title'])->projectGroups([['key' => 'plain'], ['key' => '"quoted"']]);
		$this->assertSame([['key' => 'plain'], ['key' => '"quoted"']], $groups);
		$this->assertSame([], $this->resolved);
	}

	public function testScalarAndNullAreLeftUntouched(): void {
		$groups = $this->projection(['title'])->projectGroups([['key' => 7], ['key' => null]]);
		$this->assertSame([['key' => 7], ['key' => null]], $groups);
		$this->assertSame([], $this->resolved);
	}

	public function testUnresolvedFieldFallsBackToRawKey(): void {
		$groups = $this->projection(['title'])->projectGroups([['key' => '{"en":"Hello"}']]);
		$this->assertSame([['key' => '{"en":"Hello"}']], $groups);
	}

	public function testCompositeKeysResolveOnlyTranslatableFieldsPresent(): void {
		$groups = $this->projection(['title', 'label'])->projectGroups(
			[['keys' => ['title' => ['nl' => 'A'], 'status' => ['nl' => 'B']], 'count' => 1], 'not-a-row', ['count' => 3]]
		);
		$this->assertSame([['keys' => ['title' => 'A', 'status' => ['nl' => 'B']], 'count' => 1], 'not-a-row', ['count' => 3]], $groups);
	}
}
