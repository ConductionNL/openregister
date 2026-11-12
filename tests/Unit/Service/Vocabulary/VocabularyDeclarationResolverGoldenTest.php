<?php

/**
 * Golden test pinning which declaration VocabularyDeclarationResolver answers.
 *
 * Pins both sources (a saved schema property, and a scheme named on the
 * query with its knobs read off the request), the fallback from the first to
 * the second, and every path that answers null. Written before the resolve
 * body was split into one helper per source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables;
use OCA\OpenRegister\Service\Vocabulary\DeclarationLookup;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The declarations the resolver answers, pinned.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolver
 */
final class VocabularyDeclarationResolverGoldenTest extends TestCase {

	/**
	 * Every case's answer, as the declaration's fields or null.
	 *
	 * @return void
	 */
	public function testDeclarationsArePinned(): void {
		$knobs = ['branch' => ' urn:b ', 'store' => 'notation', 'maxDepth' => '2', 'leafOnly' => 'true', 'allowDeprecated' => '0'];
		$cases = [
			'saved coded property' => [['zaak', 'resultaat', 'urn:query'], []],
			'saved property not coded, query scheme' => [['zaak', 'naam', 'urn:query'], $knobs],
			'saved property not coded, no query scheme' => [['zaak', 'naam', ''], $knobs],
			'unknown schema, query scheme' => [['missing', 'resultaat', 'urn:query'], $knobs],
			'schema without property name, query scheme' => [['zaak', '', 'urn:query'], []],
			'query scheme only, with knobs' => [['', '', 'urn:query'], $knobs],
			'nothing given' => [['', '', ''], $knobs],
		];

		$actual = [];
		foreach ($cases as $name => [$arguments, $params]) {
			$declaration = $this->resolver(params: $params)->resolveDeclaration(new DeclarationLookup(...$arguments));
			$actual[$name] = self::fields(declaration: $declaration);
		}

		$this->assertSame(self::expected(), $actual);
	}//end testDeclarationsArePinned()

	/**
	 * The pinned answers.
	 *
	 * @return array<string,array<string,mixed>|null>
	 */
	private static function expected(): array {
		$plain = ['scheme' => 'urn:query', 'store' => 'uri', 'allowDeprecated' => false, 'branch' => null, 'leafOnly' => false, 'maxDepth' => null];
		$knobbed = ['scheme' => 'urn:query', 'store' => 'notation', 'allowDeprecated' => false, 'branch' => 'urn:b', 'leafOnly' => true, 'maxDepth' => 2];

		return [
			'saved coded property' => ['scheme' => 'urn:saved', 'store' => 'uri', 'allowDeprecated' => true, 'branch' => 'urn:root', 'leafOnly' => false, 'maxDepth' => 3],
			'saved property not coded, query scheme' => $knobbed,
			'saved property not coded, no query scheme' => null,
			'unknown schema, query scheme' => null,
			'schema without property name, query scheme' => $plain,
			'query scheme only, with knobs' => $knobbed,
			'nothing given' => null,
		];
	}//end expected()

	/**
	 * The fields a declaration carries, or null.
	 *
	 * @param CodedPropertyDeclaration|null $declaration The declaration.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function fields(?CodedPropertyDeclaration $declaration): ?array {
		if ($declaration === null) {
			return null;
		}

		return [
			'scheme' => $declaration->scheme,
			'store' => $declaration->store,
			'allowDeprecated' => $declaration->allowDeprecated,
			'branch' => $declaration->branch,
			'leafOnly' => $declaration->leafOnly,
			'maxDepth' => $declaration->maxDepth,
		];
	}//end fields()

	/**
	 * The resolver over one saved schema `zaak`, with the given query parameters.
	 *
	 * @param array<string,mixed> $params The request's query parameters.
	 *
	 * @return VocabularyDeclarationResolver
	 */
	private function resolver(array $params): VocabularyDeclarationResolver {
		$zaak = new Schema();
		$zaak->setSlug('zaak');
		$zaak->setProperties(
			[
				'resultaat' => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'urn:saved', 'branch' => 'urn:root', 'maxDepth' => 3, 'allowDeprecated' => true],
				],
				'naam' => ['type' => 'string'],
			]
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id) use ($zaak): Schema {
				if ($id !== 'zaak') {
					throw new RuntimeException('not found');
				}

				return $zaak;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		return new VocabularyDeclarationResolver(
			tables: new CodedSchemaTables(
				schemas: $schemas,
				declarations: new CodedPropertyDeclarationFactory(),
				objects: $this->createMock(MagicMapperInterface::class)
			),
			request: $request
		);
	}//end resolver()
}//end class
