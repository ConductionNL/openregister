<?php

/**
 * OpenRegister ObjectTransferController
 *
 * POST /api/objects/{register}/{schema}/{id}/transfer/preview and
 * POST /api/objects/{register}/{schema}/{id}/transfer: move an object and the
 * objects its relations reach to another organisation under a policy per
 * object type, previewed first and run only on the approved preview
 * (several-legal-entities-in-one-instance, section 2). Administrators only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Organisation\ObjectGraphTransfer;
use OCA\OpenRegister\Service\Organisation\ObjectGraphTransferRefusal;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Previews and runs an object graph transfer between organisations.
 *
 * Both routes are administrator-only: no NoAdminRequired attribute, so the
 * framework refuses anybody else before the action runs.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 */
class ObjectTransferController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request  The request.
	 * @param ObjectOperationsInterface $objects  Reads the root as the caller.
	 * @param ObjectGraphTransfer       $transfer Plans and runs the transfer.
	 * @param LoggerInterface           $logger   Logs a transfer that failed while writing.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectOperationsInterface $objects,
		private readonly ObjectGraphTransfer $transfer,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The plan: every object the transfer reaches with its policy, and the digest that approves it.
	 *
	 * Body: `targetOrganisation` (uuid), `policies` (`{schemaId: move|copy|drop}`), optional `depth` (1 to 3).
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The root object's id or uuid.
	 *
	 * @return JSONResponse The plan, or the refusal with its status.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
	 */
	public function preview(string $register, string $schema, string $id): JSONResponse {
		$root = $this->read(register: $register, schema: $schema, id: $id);
		if ($root === null) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse(
				$this->transfer->preview(root: $root, target: $this->target(), policies: $this->policies(), depth: $this->depth())
			);
		} catch (ObjectGraphTransferRefusal $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getCode());
		}
	}//end preview()

	/**
	 * Run the transfer the caller approved: the same body as the preview plus `approval`, the preview's digest.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The root object's id or uuid.
	 *
	 * @return JSONResponse What moved, was copied and was dropped, with the correlation; or the refusal.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
	 */
	public function run(string $register, string $schema, string $id): JSONResponse {
		$root = $this->read(register: $register, schema: $schema, id: $id);
		if ($root === null) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse(
				$this->transfer->transfer(
					root: $root,
					target: $this->target(),
					policies: $this->policies(),
					approval: (string)$this->request->getParam('approval', ''),
					depth: $this->depth()
				)
			);
		} catch (ObjectGraphTransferRefusal $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getCode());
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ObjectTransferController] The transfer failed and was rolled back',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'The transfer failed and nothing was changed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end run()

	/**
	 * The root as the caller may read it, or null.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 * @param string $id       The object id or uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function read(string $register, string $schema, string $id): ?ObjectEntity {
		try {
			return $this->objects->find(
				id: $id,
				options: new ObjectFindOptions(register: $register, schema: $schema, render: false, audit: false),
				_rbac: true,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end read()

	/**
	 * The target organisation uuid from the body.
	 *
	 * @return string
	 */
	private function target(): string {
		$target = $this->request->getParam('targetOrganisation', '');

		if (is_string($target) === false) {
			return '';
		}

		return $target;
	}//end target()

	/**
	 * The policy per schema id from the body; a non-object body names no policy.
	 *
	 * @return array<string, string>
	 */
	private function policies(): array {
		$policies = $this->request->getParam('policies', []);
		if (is_array($policies) === false) {
			return [];
		}

		$named = [];
		foreach ($policies as $schema => $policy) {
			$named[(string)$schema] = '';
			if (is_string($policy) === true) {
				$named[(string)$schema] = $policy;
			}
		}

		return $named;
	}//end policies()

	/**
	 * How far the relations are followed, from the body.
	 *
	 * @return int
	 */
	private function depth(): int {
		return (int)$this->request->getParam('depth', 1);
	}//end depth()
}//end class
