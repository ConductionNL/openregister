<?php

/**
 * Applies the `portal` property key to the objects API for a caller acting on behalf of a portal subject.
 *
 * A request that carries `X-Portal-Subject` is made on behalf of a resident.
 * For it, a read leaves out every property whose `portal` is `none` or absent,
 * and a write that would change a property whose `portal` is not `edit` is
 * refused with 403, naming the properties, before the controller writes
 * anything. A request without the header is not touched.
 *
 * The header's mere presence switches the rules on, verified or not: they only
 * ever take away (fewer properties shown, fewer writes allowed), so a header
 * nobody could verify cannot buy a caller more than it had without it. Who the
 * subject is, and what it may reach, stays the job of the routes that verify
 * the assertion.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\PortalPropertyRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Schemas\PortalPropertyPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Throwable;

/**
 * The portal rules on objects#index, #show, #create, #update and #patch.
 *
 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#requirement-a-property-declares-what-the-portal-may-do-with-it
 */
class PortalPropertyMiddleware extends Middleware {

	/**
	 * The header a portal sends with a request made on behalf of a resident.
	 *
	 * @var string
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * The calls whose answer is filtered: the reads, and the writes, which answer with the object they wrote.
	 *
	 * @var array<int, string>
	 */
	private const READS = ['index', 'show', 'create', 'update', 'patch'];

	/**
	 * The writes that are checked, and whether each replaces the object.
	 *
	 * @var array<string, bool>
	 */
	private const WRITES = ['create' => false, 'update' => true, 'patch' => false];

	/**
	 * Route parameters that arrive among the body's keys and are no property.
	 *
	 * @var array<int, string>
	 */
	private const ROUTE_KEYS = ['register', 'schema'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request The request.
	 * @param SchemaMapperInterface     $schemas Resolves the route's schema.
	 * @param ObjectOperationsInterface $objects Reads the stored object a write would change, through RBAC.
	 * @param PortalPropertyPolicy      $policy  The portal rules.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly SchemaMapperInterface $schemas,
		private readonly ObjectOperationsInterface $objects,
		private readonly PortalPropertyPolicy $policy,
	) {
	}//end __construct()

	/**
	 * Refuse a portal write that changes a property the portal may not change.
	 *
	 * @param Controller $controller The controller.
	 * @param string     $methodName The method about to run.
	 *
	 * @return void
	 *
	 * @throws PortalPropertyRefusedException When the write changes such a property.
	 *
	 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#scenario-the-resident-changes-only-what-they-may-change
	 */
	public function beforeController(Controller $controller, string $methodName): void {
		if ($this->applies(controller: $controller) === false || array_key_exists($methodName, self::WRITES) === false) {
			return;
		}

		$schema  = $this->schema();
		$refused = $this->policy->refusedChanges(
			schema: ($schema ?? new Schema()),
			incoming: $this->body(),
			existing: $this->stored(methodName: $methodName),
			replace: self::WRITES[$methodName]
		);
		if ($refused !== []) {
			throw new PortalPropertyRefusedException(properties: $refused);
		}
	}//end beforeController()

	/**
	 * Leave the properties the portal may not see out of a portal read's answer.
	 *
	 * @param Controller $controller The controller.
	 * @param string     $methodName The method that ran.
	 * @param Response   $response   Its answer.
	 *
	 * @return Response The answer, filtered for a portal read.
	 *
	 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#scenario-an-internal-note-stays-off-the-portal
	 */
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($this->applies(controller: $controller) === false
			|| in_array($methodName, self::READS, true) === false
			|| ($response instanceof JSONResponse) === false
			|| in_array($response->getStatus(), [Http::STATUS_OK, Http::STATUS_CREATED], true) === false
		) {
			return $response;
		}

		$data = $response->getData();
		if (is_array($data) === false) {
			return $response;
		}

		// A schema that cannot be resolved shows nothing but the envelope.
		$schema = ($this->schema() ?? new Schema());
		$response->setData($this->filtered(methodName: $methodName, data: $data, schema: $schema));

		return $response;
	}//end afterController()

	/**
	 * Answer a refused portal write with 403, naming the properties.
	 *
	 * @param Controller $controller The controller.
	 * @param string     $methodName The method.
	 * @param Exception  $exception  What was thrown.
	 *
	 * @return Response The 403.
	 *
	 * @throws Exception Every other exception, untouched.
	 *
	 * @spec openspec/changes/a-property-says-what-the-portal-may-do-with-it/specs/schema-property-exploration/spec.md#scenario-the-resident-changes-only-what-they-may-change
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if (($exception instanceof PortalPropertyRefusedException) === false) {
			throw $exception;
		}

		return new JSONResponse(
			data: [
				'error'      => 'portal-property-not-editable',
				'message'    => $exception->getMessage(),
				'properties' => $exception->getProperties(),
			],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end afterException()

	/**
	 * Whether the rules apply: an objects API call made on behalf of a portal subject.
	 *
	 * @param Controller $controller The controller.
	 *
	 * @return bool True for ObjectsController with the header set.
	 */
	private function applies(Controller $controller): bool {
		return $controller instanceof ObjectsController && trim($this->request->getHeader(self::HEADER)) !== '';
	}//end applies()

	/**
	 * A read's answer without the properties the portal may not see.
	 *
	 * @param string               $methodName `index`, or a call that answers with one object.
	 * @param array<string, mixed> $data       The answer.
	 * @param Schema               $schema     The route's schema.
	 *
	 * @return array<string, mixed> The filtered answer.
	 */
	private function filtered(string $methodName, array $data, Schema $schema): array {
		if ($methodName !== 'index') {
			return $this->policy->readable(schema: $schema, object: $data);
		}

		if (is_array($data['results'] ?? null) === false) {
			return $data;
		}

		$data['results'] = array_map(
			fn (mixed $row): mixed => is_array($row) === true ? $this->policy->readable(schema: $schema, object: $row) : $row,
			$data['results']
		);

		return $data;
	}//end filtered()

	/**
	 * The route's schema, or null when it cannot be resolved.
	 *
	 * Read past RBAC and multitenancy: only its property declarations are
	 * used, and whether the caller may reach its objects is the controller's
	 * decision.
	 *
	 * @return Schema|null The schema.
	 */
	private function schema(): ?Schema {
		$id = $this->request->getParam('schema');
		if (is_string($id) === false && is_int($id) === false) {
			return null;
		}

		try {
			return $this->schemas->find($id, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end schema()

	/**
	 * The values the write sends, without the route parameters.
	 *
	 * @return array<string, mixed> The values.
	 */
	private function body(): array {
		$params = $this->request->getParams();

		return array_diff_key($params, array_flip(self::ROUTE_KEYS));
	}//end body()

	/**
	 * The stored values a write would change, read as the caller may read them; null for a create or an object the caller cannot read.
	 *
	 * @param string $methodName The write.
	 *
	 * @return array<string, mixed>|null The stored values.
	 */
	private function stored(string $methodName): ?array {
		$id = $this->request->getParam('id');
		if ($methodName === 'create' || (is_string($id) === false && is_int($id) === false)) {
			return null;
		}

		try {
			$this->objects->setRegister((string)$this->request->getParam('register'));
			$this->objects->setSchema((string)$this->request->getParam('schema'));
			$this->objects->setObject((string)$id);
			$object = $this->objects->getObject();
		} catch (Throwable $e) {
			// Nothing stored the caller may read: the write is judged as a
			// create, the stricter reading. The controller answers the miss.
			return null;
		}

		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return (array)$object->getObject();
	}//end stored()
}//end class
