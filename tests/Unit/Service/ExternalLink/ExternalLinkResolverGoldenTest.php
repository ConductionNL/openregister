<?php

/**
 * Golden test for ExternalLinkResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExternalLink
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExternalLink;

use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case resolves a list of link declarations against one object and
 * records the links, or the error thrown. Compared with
 * fixtures/external-link-resolver-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver
 */
class ExternalLinkResolverGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/external-link-resolver-golden.json';

	/**
	 * @return array<string, array{0: array<mixed>}>
	 */
	public static function cases(): array {
		$base = ['title' => 'Kadaster', 'url' => 'https://k.example/{id}'];
		return [
			'plain'                      => [[$base]],
			'non-array declaration'      => [['x', 5, null, $base]],
			'blank title'                => [[['title' => '  ', 'url' => 'https://x']]],
			'blank url'                  => [[['title' => 'T', 'url' => '']]],
			'missing title'              => [[['url' => 'https://x']]],
			'trimmed title and url'      => [[['title' => ' T ', 'url' => ' https://x/{id} ']]],
			'numeric title'              => [[['title' => 12, 'url' => 'https://x']]],
			'description and target'     => [[$base + ['description' => ' Desc ', 'target' => ' _blank ']]],
			'blank description target'   => [[$base + ['description' => ' ', 'target' => '']]],
			'nested placeholder'         => [[['title' => 'T', 'url' => 'https://x/{address.city}/{address.no}']]],
			'repeated placeholder'       => [[['title' => 'T', 'url' => 'https://x/{id}/{id}']]],
			'missing placeholder'        => [[['title' => 'T', 'url' => 'https://x/{nope}']]],
			'null value placeholder'     => [[['title' => 'T', 'url' => 'https://x/{empty}']]],
			'blank string placeholder'   => [[['title' => 'T', 'url' => 'https://x/{blank}']]],
			'array value placeholder'    => [[['title' => 'T', 'url' => 'https://x/{address}']]],
			'scalar mid-path'            => [[['title' => 'T', 'url' => 'https://x/{id.deeper}']]],
			'too deep'                   => [[['title' => 'T', 'url' => 'https://x/{a.b.c.d.e.f.g}']]],
			'max depth'                  => [[['title' => 'T', 'url' => 'https://x/{a.b.c.d.e.x}']]],
			'encodes value'              => [[['title' => 'T', 'url' => 'https://x/?q={name}']]],
			'int float bool values'      => [[['title' => 'T', 'url' => 'https://x/{count}/{ratio}/{yes}/{no}']]],
			'no placeholders'            => [[['title' => 'T', 'url' => 'https://x/static']]],
			'invalid placeholder syntax' => [[['title' => 'T', 'url' => 'https://x/{1bad}/{ok-}']]],
			'condition null'             => [[$base + ['condition' => null]]],
			'condition empty'            => [[$base + ['condition' => []]]],
			'condition scalar'           => [[$base + ['condition' => 'status']]],
			'condition met'              => [[$base + ['condition' => ['status' => 'open']]]],
			'condition unmet'            => [[$base + ['condition' => ['status' => 'closed']]]],
			'condition missing path'     => [[$base + ['condition' => ['nope' => 'x']]]],
			'condition nested'           => [[$base + ['condition' => ['address.city' => 'Utrecht']]]],
			'condition list'             => [[$base + ['condition' => ['status' => ['closed', 'open']]]]],
			'condition list miss'        => [[$base + ['condition' => ['status' => ['closed', null, ['open']]]]]],
			'condition empty list'       => [[$base + ['condition' => ['status' => []]]]],
			'condition int vs string'    => [[$base + ['condition' => ['count' => '3']]]],
			'condition int expected'     => [[$base + ['condition' => ['count' => 3]]]],
			'condition bool expected'    => [[$base + ['condition' => ['yes' => true, 'no' => 'false']]]],
			'condition null expected'    => [[$base + ['condition' => ['status' => null]]]],
			'condition expected trimmed' => [[$base + ['condition' => ['status' => ' open ']]]],
			'condition all must hold'    => [[$base + ['condition' => ['status' => 'open', 'count' => 4]]]],
			'condition int key'          => [[$base + ['condition' => [0 => 'zero']]]],
			'several declarations'       => [[$base, ['title' => 'B', 'url' => 'https://b/{nope}'], ['title' => 'C', 'url' => 'https://c/{count}']]],
		];
	}

	/**
	 * @param array<mixed> $declarations The link declarations.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testResolve(array $declarations): void {
		$object = [
			'id'      => 'abc 1/2',
			'name'    => 'Jan & Piet',
			'status'  => ' open',
			'count'   => 3,
			'ratio'   => 1.5,
			'yes'     => true,
			'no'      => false,
			'empty'   => null,
			'blank'   => '   ',
			0         => 'zero',
			'address' => ['city' => 'Utrecht', 'no' => 12],
			'a'       => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'seven'], 'x' => 1]]]]],
		];

		try {
			$actual = (new ExternalLinkResolver())->resolve($declarations, $object);
		} catch (\Throwable $e) {
			$actual = ['thrown' => get_class($e)];
		}

		$actual = json_decode((string) json_encode($actual), true);
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}

	/**
	 * @return void
	 */
	public function testPlaceholdersIn(): void {
		$this->assertSame(['id', 'a.b'], ExternalLinkResolver::placeholdersIn('x/{id}/{a.b}/{id}/{1x}'));
		$this->assertSame([], ExternalLinkResolver::placeholdersIn('none'));
	}
}
