<?php

/**
 * The object store reads the request's languages through the Core port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Kernel\Contracts\Core\RequestLanguagesInterface;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\LanguageServiceInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\PlatformServiceBindings;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The language service is the port; the search handler asks only the port.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Service\PlatformServiceBindings
 * @covers \OCA\OpenRegister\Service\CoreServiceBindings
 */
final class RequestLanguagesPortTest extends TestCase {

	public function testTheLanguageServiceIsThePort(): void {
		$this->assertTrue(is_subclass_of(LanguageServiceInterface::class, RequestLanguagesInterface::class));
		$this->assertTrue(is_subclass_of(LanguageService::class, RequestLanguagesInterface::class));
	}

	public function testThePortResolvesToTheSharedLanguageService(): void {
		// MagicMapper asks the container for the port; it must reach the same
		// request-scoped instance the language middleware fills.
		$this->assertSame(LanguageService::class, CoreServiceBindings::BINDINGS[RequestLanguagesInterface::class]);
		$this->assertSame(PlatformServiceBindings::BINDINGS[LanguageServiceInterface::class], CoreServiceBindings::BINDINGS[RequestLanguagesInterface::class]);
	}

	public function testTheSearchHandlerTakesThePort(): void {
		$parameter = null;
		foreach ((new ReflectionMethod(MagicSearchHandler::class, '__construct'))->getParameters() as $candidate) {
			if ($candidate->getName() === 'languageService') {
				$parameter = $candidate;
			}
		}

		$this->assertNotNull($parameter);
		$type = $parameter->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(RequestLanguagesInterface::class, $type->getName());
		$this->assertTrue($type->allowsNull());
	}

	public function testThePortAloneOrdersTheLanguageChain(): void {
		$languages = $this->createMock(RequestLanguagesInterface::class);
		$languages->method('getPreferredLanguage')->willReturn('nl');
		$languages->method('getAcceptedLanguages')->willReturn(['en-GB', 'de']);

		$chain = new ReflectionMethod(MagicSearchHandler::class, 'requestLanguageChain');

		$this->assertSame(['nl'], $chain->invoke($this->handler(languages: $languages), []));
		$this->assertSame(['en', 'de', 'de'], $chain->invoke($this->handler(languages: $languages), ['de', 'en']));
		$this->assertSame([], $chain->invoke($this->handler(languages: null), ['de', 'en']));
	}

	private function handler(?RequestLanguagesInterface $languages): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);

		return new MagicSearchHandler(
			db: $this->createMock(IDBConnection::class),
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class),
			languageService: $languages
		);
	}
}//end class
