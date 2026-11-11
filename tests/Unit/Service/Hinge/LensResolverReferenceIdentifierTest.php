<?php

/**
 * Characterisation of LensResolver::referenceIdentifier().
 *
 * Pins which identifier a reference property yields for every shape it may
 * hold: a bare identifier, a uri (with or without a trailing slash), an
 * integer, an extended object with an `id` or a `uuid`, and the values that
 * yield none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Hinge;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Hinge\LensLookups;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

class LensResolverReferenceIdentifierTest extends TestCase {

	public static function values(): array {
		return [
			'bare uuid' => ['a1b2', 'a1b2'],
			'uri' => ['https://example.org/api/objects/pub/page/a1b2', 'a1b2'],
			'uri with trailing slash' => ['/api/objects/a1b2/', 'a1b2'],
			'only slashes' => ['///', ''],
			'empty string' => ['', null],
			'integer' => [42, '42'],
			'zero' => [0, '0'],
			'object with id' => [['id' => 'i-1', 'uuid' => 'u-1'], 'i-1'],
			'object with uuid' => [['uuid' => 'u-1'], 'u-1'],
			'object with null id and uuid' => [['id' => null, 'uuid' => 'u-1'], 'u-1'],
			'object with empty id' => [['id' => '', 'uuid' => 'u-1'], null],
			'object with integer id' => [['id' => 7], null],
			'object without id' => [['title' => 'x'], null],
			'null' => [null, null],
			'float' => [4.2, null],
			'boolean' => [true, null],
		];
	}//end values()

	#[DataProvider('values')]
	public function testTheIdentifierOfAReference(mixed $value, ?string $expected): void {
		$resolver = new LensResolver(
			lookups: new LensLookups(magicMapper: $this->createMock(MagicMapper::class), schemaMapper: $this->createMock(SchemaMapper::class)),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $this->createMock(IUserSession::class),
			logger: new NullLogger()
		);

		$this->assertSame($expected, (new ReflectionMethod(LensResolver::class, 'referenceIdentifier'))->invoke($resolver, $value));
	}//end testTheIdentifierOfAReference()
}//end class
