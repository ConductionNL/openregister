<?php

declare(strict_types=1);

/*
 * The workflow endpoints must stay admin-only.
 *
 * ScheduledWorkflow, WorkflowEngine and WorkflowExecution have NO owner column,
 * so "the caller's own workflow" is not a thing that can be expressed and there
 * is no per-object guard available to write. Admin is therefore the only posture
 * that is not a privilege escalation, and the way that posture is expressed in
 * Nextcloud is by the ABSENCE of #[NoAdminRequired] — which means a single added
 * attribute silently opens every one of these endpoints to any authenticated
 * user, with no diff to any guard to give it away.
 *
 * That is what this test exists to catch. It asserts the absence, so adding the
 * attribute back turns it red.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/workflow-engine-abstraction/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ScheduledWorkflowController;
use OCA\OpenRegister\Controller\WorkflowEngineController;
use OCA\OpenRegister\Controller\WorkflowExecutionController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class WorkflowEndpointsAdminPostureTest extends TestCase {

	/**
	 * Every endpoint that must remain reachable by administrators only.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function adminOnlyEndpoints(): array {
		return [
			'ScheduledWorkflow::create' => [ScheduledWorkflowController::class, 'create'],
			'ScheduledWorkflow::update' => [ScheduledWorkflowController::class, 'update'],
			'ScheduledWorkflow::destroy' => [ScheduledWorkflowController::class, 'destroy'],
			'WorkflowEngine::index' => [WorkflowEngineController::class, 'index'],
			'WorkflowEngine::show' => [WorkflowEngineController::class, 'show'],
			'WorkflowEngine::create' => [WorkflowEngineController::class, 'create'],
			'WorkflowEngine::update' => [WorkflowEngineController::class, 'update'],
			'WorkflowEngine::destroy' => [WorkflowEngineController::class, 'destroy'],
			'WorkflowEngine::health' => [WorkflowEngineController::class, 'health'],
			'WorkflowEngine::available' => [WorkflowEngineController::class, 'available'],
			'WorkflowEngine::testHook' => [WorkflowEngineController::class, 'testHook'],
			'WorkflowExecution::destroy' => [WorkflowExecutionController::class, 'destroy'],
		];
	}//end adminOnlyEndpoints()

	/**
	 * The endpoint must not carry #[NoAdminRequired].
	 *
	 * This is the load-bearing assertion: the attribute's absence IS the admin
	 * gate, so its presence would make the endpoint reachable by every logged-in
	 * user without any other line of the diff changing.
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointDoesNotDeclareNoAdminRequired(string $controller, string $method): void {
		$attributes = (new ReflectionMethod($controller, $method))->getAttributes(NoAdminRequired::class);

		$this->assertSame(
			[],
			$attributes,
			$controller . '::' . $method . '() carries #[NoAdminRequired]. These endpoints operate on '
			. 'instance-wide records with no owner column, so there is no per-object guard that could '
			. 'make them safe for a non-admin. Remove the attribute, or add an owner column and a guard '
			. 'first.'
		);
	}//end testEndpointDoesNotDeclareNoAdminRequired()

	/**
	 * The endpoint must not be a public page either.
	 *
	 * #[PublicPage] drops authentication altogether, so it would be a strictly
	 * worse version of the same mistake and the NoAdminRequired assertion above
	 * would not notice it.
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointIsNotAPublicPage(string $controller, string $method): void {
		$attributes = (new ReflectionMethod($controller, $method))->getAttributes(PublicPage::class);

		$this->assertSame(
			[],
			$attributes,
			$controller . '::' . $method . '() carries #[PublicPage], which removes authentication entirely.'
		);
	}//end testEndpointIsNotAPublicPage()

	/**
	 * The legacy docblock form must be absent too.
	 *
	 * Nextcloud still honours `@NoAdminRequired` at docblock-tag position, so an
	 * attribute-only check would pass over an endpoint opened the old way. The
	 * tag is only recognised at tag position — preceded on its line by nothing
	 * but whitespace and comment punctuation — which is what the pattern below
	 * matches, so prose mentioning the name in a sentence does not trip it.
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointDoesNotCarryTheLegacyDocblockTag(string $controller, string $method): void {
		$doc = (new ReflectionMethod($controller, $method))->getDocComment();
		if ($doc === false) {
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertDoesNotMatchRegularExpression(
			'/^[[:space:]]*(\/?\*+[[:space:]]*)@NoAdminRequired\b/m',
			$doc,
			$controller . '::' . $method . '() declares @NoAdminRequired at docblock-tag position, which '
			. 'Nextcloud honours exactly like the attribute.'
		);
	}//end testEndpointDoesNotCarryTheLegacyDocblockTag()

	/**
	 * The admin-only posture must be stated, not merely implied by an absence.
	 *
	 * An endpoint that is admin-only because nobody wrote an attribute reads
	 * identically to one where the attribute was forgotten. `@auth admin-only`
	 * is what separates the two, and it is the marker the mechanical gates read.
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointDeclaresItsAdminOnlyPostureWithAReason(string $controller, string $method): void {
		$doc = (new ReflectionMethod($controller, $method))->getDocComment();

		$this->assertIsString($doc, $controller . '::' . $method . '() has no docblock to declare a posture in.');
		$this->assertMatchesRegularExpression(
			'/^[[:space:]]*(\/?\*+[[:space:]]*)@auth[[:space:]]+admin-only[[:space:]]+.{20,}/m',
			$doc,
			$controller . '::' . $method . '() does not declare `@auth admin-only <reason>`. Being admin-only '
			. 'by Nextcloud default is indistinguishable from having forgotten an attribute; say which it is.'
		);
	}//end testEndpointDeclaresItsAdminOnlyPostureWithAReason()
}//end class
