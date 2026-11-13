<?php

/**
 * Maintenance routes are admin-only, and the GraphiQL page loads pinned assets.
 *
 * Nextcloud's security middleware lets a non-administrator through only when a
 * method declares NoAdminRequired (as an attribute or a docblock tag), so the
 * absence of that declaration is the admin check, enforced before the method runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\TablesController;
use OCA\OpenRegister\Search\Controller\GraphQLController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * @coversNothing
 */
class AdminOnlyMaintenanceRoutesTest extends TestCase {

	/**
	 * Routed methods that only an administrator may call.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function adminOnlyMethods(): array {
		return [
			'table sync' => [TablesController::class, 'sync'],
			'table sync all' => [TablesController::class, 'syncAll'],
			'object batch vectorization' => [ObjectsController::class, 'vectorizeBatch'],
			'object vectorization statistics' => [ObjectsController::class, 'getObjectVectorizationStats'],
			'graphql explorer' => [GraphQLController::class, 'explorer'],
		];
	}//end adminOnlyMethods()

	/**
	 * @dataProvider adminOnlyMethods
	 *
	 * @param class-string $class  The controller.
	 * @param string       $method The routed method.
	 */
	public function testTheMethodDoesNotLetANonAdministratorThrough(string $class, string $method): void {
		$reflection = new ReflectionMethod($class, $method);
		$docblock = (string)$reflection->getDocComment();

		$this->assertDoesNotMatchRegularExpression('/@NoAdminRequired\b/', $docblock);
		$this->assertDoesNotMatchRegularExpression('/@PublicPage\b/', $docblock);
		$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class));
		$this->assertSame([], $reflection->getAttributes(PublicPage::class));
		$this->assertMatchesRegularExpression('/@auth admin-only \S/', $docblock);
	}//end testTheMethodDoesNotLetANonAdministratorThrough()

	public function testEveryExplorerAssetIsPinnedWithAnIntegrityHash(): void {
		$html = (string)(new ReflectionClassConstant(GraphQLController::class, 'EXPLORER_PAGE'))->getValue();

		preg_match_all('/<(?:script|link)\b[^>]*\b(?:src|href)="https?:\/\/[^"]+"[^>]*>/', $html, $tags);
		$this->assertCount(4, $tags[0]);
		foreach ($tags[0] as $tag) {
			$this->assertMatchesRegularExpression('/@\d+\.\d+\.\d+\//', $tag, 'unpinned asset: ' . $tag);
			$this->assertMatchesRegularExpression('/\bintegrity="sha384-[A-Za-z0-9+\/=]{64}"/', $tag, 'asset without integrity: ' . $tag);
			$this->assertStringContainsString('crossorigin="anonymous"', $tag);
		}
	}//end testEveryExplorerAssetIsPinnedWithAnIntegrityHash()

	public function testTheExplorerPolicyDoesNotAllowInlineScripts(): void {
		if (method_exists(ContentSecurityPolicy::class, 'allowEvalScript') === false) {
			$this->markTestSkipped('explorerPolicy() needs ContentSecurityPolicy::allowEvalScript()');
		}

		$controller = (new \ReflectionClass(GraphQLController::class))->newInstanceWithoutConstructor();
		$policy = (new ReflectionMethod(GraphQLController::class, 'explorerPolicy'))->invoke($controller)->buildPolicy();

		preg_match('/script-src [^;]*/', $policy, $scriptSrc);
		$this->assertNotSame([], $scriptSrc);
		$this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc[0]);
	}//end testTheExplorerPolicyDoesNotAllowInlineScripts()
}//end class
