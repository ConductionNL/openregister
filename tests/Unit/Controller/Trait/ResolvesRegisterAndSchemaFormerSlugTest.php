<?php

/**
 * ResolvesRegisterAndSchemaTrait — the former-slug signal on object responses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Trait
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Trait;

use OCA\OpenRegister\Controller\Trait\ResolvesRegisterAndSchemaTrait;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/objects/meldingen-register/melding` after `melding` was renamed to
 * `signaal` answers as `signaal`, with `Deprecation` and a `Link` to the new path.
 *
 * @covers \OCA\OpenRegister\Controller\Trait\ResolvesRegisterAndSchemaTrait
 * @uses \OCA\OpenRegister\Service\Schema\FormerSlugs
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-former-slug-keeps-answering-and-says-where-to-go
 */
class ResolvesRegisterAndSchemaFormerSlugTest extends TestCase {

	/**
	 * A trait consumer with the request a controller carries.
	 *
	 * @param string $uri The request URI.
	 *
	 * @return object The consumer.
	 */
	private function consumer(string $uri): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn($uri);

		return new class($request) {
			use ResolvesRegisterAndSchemaTrait;

			public function __construct(public IRequest $request) {
			}//end __construct()

			public function answer(string $register, string $schema, ObjectOperationsInterface $service): JSONResponse {
				$this->resolveRegisterSchemaRef(ref: new RegisterSchemaRef(register: $register, schema: $schema), objectService: $service);
				return $this->signalFormerSlug(response: new JSONResponse(data: ['results' => []]));
			}//end answer()
		};
	}//end consumer()

	/**
	 * An object service that resolves to the given schema.
	 *
	 * @param Schema $schema The schema the path resolves to.
	 *
	 * @return ObjectOperationsInterface The service.
	 */
	private function service(Schema $schema): ObjectOperationsInterface {
		$service = $this->createMock(ObjectOperationsInterface::class);
		$service->method('getCurrentRegisterEntity')->willReturn(new Register());
		$service->method('getCurrentSchemaEntity')->willReturn($schema);
		$service->method('getSchema')->willReturn($schema->getId());
		return $service;
	}//end service()

	/**
	 * The schema `signaal`, formerly `melding`.
	 *
	 * @return Schema
	 */
	private function signaal(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('signaal');
		$schema->setFormerSlugs(['melding']);
		return $schema;
	}//end signaal()

	public function testAFormerSlugAnswersWithDeprecationAndTheSuccessorLink(): void {
		$consumer = $this->consumer('/index.php/apps/openregister/api/objects/meldingen-register/melding?_limit=20');

		$headers = $consumer->answer('meldingen-register', 'melding', $this->service($this->signaal()))->getHeaders();

		$this->assertSame('true', $headers['Deprecation']);
		$this->assertSame('</index.php/apps/openregister/api/objects/meldingen-register/signaal>; rel="successor-version"', $headers['Link']);
	}//end testAFormerSlugAnswersWithDeprecationAndTheSuccessorLink()

	public function testTheSuccessorOfOneObjectNamesThatObject(): void {
		$consumer = $this->consumer('/index.php/apps/openregister/api/objects/meldingen-register/melding/0f6b8a52-3c1e-4d2a-9b7e-5f4c3d2e1a0b');

		$headers = $consumer->answer('meldingen-register', 'melding', $this->service($this->signaal()))->getHeaders();

		$this->assertSame(
			'</index.php/apps/openregister/api/objects/meldingen-register/signaal/0f6b8a52-3c1e-4d2a-9b7e-5f4c3d2e1a0b>; rel="successor-version"',
			$headers['Link']
		);
	}//end testTheSuccessorOfOneObjectNamesThatObject()

	public function testTheCurrentSlugAnswersWithoutTheSignal(): void {
		$consumer = $this->consumer('/index.php/apps/openregister/api/objects/meldingen-register/signaal');

		$headers = $consumer->answer('meldingen-register', 'signaal', $this->service($this->signaal()))->getHeaders();

		$this->assertArrayNotHasKey('Deprecation', $headers);
		$this->assertArrayNotHasKey('Link', $headers);
	}//end testTheCurrentSlugAnswersWithoutTheSignal()

	public function testAnIdAnswersWithoutTheSignal(): void {
		$consumer = $this->consumer('/index.php/apps/openregister/api/objects/18/7');

		$headers = $consumer->answer('18', '7', $this->service($this->signaal()))->getHeaders();

		$this->assertArrayNotHasKey('Deprecation', $headers);
	}//end testAnIdAnswersWithoutTheSignal()

	public function testTheSignalDoesNotCarryOverToTheNextResolution(): void {
		$consumer = $this->consumer('/index.php/apps/openregister/api/objects/meldingen-register/melding');
		$service = $this->service($this->signaal());

		$consumer->answer('meldingen-register', 'melding', $service);
		$headers = $consumer->answer('meldingen-register', 'signaal', $service)->getHeaders();

		$this->assertArrayNotHasKey('Deprecation', $headers);
	}//end testTheSignalDoesNotCarryOverToTheNextResolution()
}//end class
