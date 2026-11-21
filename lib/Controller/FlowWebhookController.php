<?php

/**
 * The public address of incoming-webhook flow triggers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `#[PublicPage]` because the caller is another system with no account here.
 * The signature over the raw body is the only credential, and it is checked
 * before the body is parsed or anything is queued. A bad or missing
 * signature, an unknown token, a disabled flow and a refused run all answer
 * the same 404, so the address reveals nothing and a refused call leaves no
 * run. The body is data: it becomes the run's input as parsed JSON and is
 * never evaluated.
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
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\RawRequestBody;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowWebhookSecrets;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerWebhookNode;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Accepts a signed call and queues one run of the addressed flow.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
class FlowWebhookController extends Controller {

	/**
	 * The largest body accepted, in bytes.
	 *
	 * @var integer
	 */
	public const MAX_BODY = 1048576;

	/**
	 * How long a delivery id is remembered, in seconds.
	 *
	 * @var integer
	 */
	public const DELIVERY_TTL = 86400;

	/**
	 * The signature header.
	 *
	 * @var string
	 */
	public const SIGNATURE_HEADER = 'X-OpenRegister-Signature';

	/**
	 * The delivery id header.
	 *
	 * @var string
	 */
	public const DELIVERY_HEADER = 'X-Delivery-Id';

	/**
	 * The run trigger name of a webhook-fired run.
	 *
	 * @var string
	 */
	public const TRIGGER = 'webhook';

	/**
	 * Constructor.
	 *
	 * @param string                  $appName  App id.
	 * @param IRequest                $request  The call.
	 * @param RawRequestBody          $body     Reads the raw body, bounded.
	 * @param FlowWebhookSecrets      $secrets  Finds the trigger and its secret by token.
	 * @param FlowMapperInterface     $flows    Loads the addressed flow.
	 * @param FlowRunServiceInterface $runs     The run queue.
	 * @param ICacheFactory           $caches   Remembers delivery ids.
	 * @param LoggerInterface         $logger   Logs a refused run.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RawRequestBody $body,
		private readonly FlowWebhookSecrets $secrets,
		private readonly FlowMapperInterface $flows,
		private readonly FlowRunServiceInterface $runs,
		private readonly ICacheFactory $caches,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Receive a call on a trigger's address.
	 *
	 * @param string $token The address token.
	 *
	 * @return JSONResponse 202 with `runId`; 404 for anything refused; 413 for an oversize body; 400 for a body that is not JSON.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#requirement-an-incoming-webhook-trigger-starts-a-flow-from-a-signed-call-by-another-system
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function receive(string $token): JSONResponse {
		$raw = $this->body->read(max: (self::MAX_BODY + 1));
		if (strlen($raw) > self::MAX_BODY) {
			return new JSONResponse(['error' => 'The body is larger than 1 MB.'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		$trigger = $this->secrets->find(token: $token);
		$signature = (string)$this->request->getHeader(self::SIGNATURE_HEADER);
		if ($trigger === null || self::signed(raw: $raw, secret: $trigger['secret'], header: $signature) === false) {
			return self::notFound();
		}

		if ($this->isLive(flowUuid: $trigger['flow'], nodeId: $trigger['node'], token: $token) === false) {
			return self::notFound();
		}

		$input = json_decode($raw, true);
		if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
			return new JSONResponse(['error' => 'The body must be JSON.'], Http::STATUS_BAD_REQUEST);
		}

		return $this->queue(trigger: $trigger, token: $token, input: $input);
	}//end receive()

	/**
	 * Queue the run, or answer the run a repeated delivery already started.
	 *
	 * @param array{flow: string, node: string, secret: string} $trigger The trigger.
	 * @param string                                            $token   The address token.
	 * @param mixed                                             $input   The parsed body.
	 *
	 * @return JSONResponse
	 */
	private function queue(array $trigger, string $token, mixed $input): JSONResponse {
		$cache = $this->caches->createDistributed('openregister-flow-hooks');
		$delivery = trim((string)$this->request->getHeader(self::DELIVERY_HEADER));
		$deliveryKey = md5($token . '|' . $delivery);
		if ($delivery !== '') {
			$seen = $cache->get($deliveryKey);
			if (is_string($seen) === true && $seen !== '') {
				return new JSONResponse(['runId' => $seen], Http::STATUS_ACCEPTED);
			}
		}

		try {
			$run = $this->runs->queueRun(
				flowId: $trigger['flow'],
				options: new FlowRunQueueOptions(
					subject: [],
					trigger: self::TRIGGER,
					context: ['payload' => $input, 'webhook' => ['nodeId' => $trigger['node'], 'deliveryId' => $delivery]]
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowWebhookController] A signed call was not run: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $trigger['flow']]
			);
			return self::notFound();
		}

		$runId = (string)$run->getUuid();
		if ($delivery !== '') {
			$cache->set($deliveryKey, $runId, self::DELIVERY_TTL);
		}

		return new JSONResponse(['runId' => $runId], Http::STATUS_ACCEPTED);
	}//end queue()

	/**
	 * Whether the flow is enabled and still carries this trigger with this token.
	 *
	 * @param string $flowUuid The flow.
	 * @param string $nodeId   The trigger node.
	 * @param string $token    The address token.
	 *
	 * @return bool
	 */
	private function isLive(string $flowUuid, string $nodeId, string $token): bool {
		try {
			$flow = $this->flows->findByUuid(uuid: $flowUuid);
		} catch (Throwable $e) {
			return false;
		}

		if ($flow->canDispatch() === false) {
			return false;
		}

		foreach (($flow->getNodes() ?? []) as $node) {
			if (is_array($node) === true
				&& ($node['type'] ?? null) === TriggerWebhookNode::TYPE
				&& (string)($node['id'] ?? '') === $nodeId
				&& (string)($node['config']['token'] ?? '') === $token
			) {
				return true;
			}
		}

		return false;
	}//end isLive()

	/**
	 * Whether the header holds the HMAC-SHA256 of the raw body under the secret.
	 *
	 * @param string $raw    The raw body.
	 * @param string $secret The trigger's secret.
	 * @param string $header The signature header, hex, optionally prefixed `sha256=`.
	 *
	 * @return bool
	 */
	private static function signed(string $raw, string $secret, string $header): bool {
		$given = strtolower(trim($header));
		if (str_starts_with($given, 'sha256=') === true) {
			$given = substr($given, 7);
		}

		return $given !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $given) === true;
	}//end signed()

	/**
	 * The one answer to every refused call.
	 *
	 * @return JSONResponse
	 */
	private static function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
