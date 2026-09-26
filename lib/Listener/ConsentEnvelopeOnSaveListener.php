<?php

/**
 * OpenRegister ConsentEnvelopeOnSaveListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. For each
 * `x-openregister-consent` property declared on the schema, fills the
 * read-only evidentiary fields on every newly appended array entry and
 * refuses any write that mutates or drops an already-persisted entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/consent-evidence-envelope/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills evidence and enforces append-only on every `x-openregister-consent` property.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class ConsentEnvelopeOnSaveListener implements IEventListener {

	/**
	 * The error code a refused write carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'consent-envelope-mutated';

	/**
	 * The dialect key a schema property carries.
	 *
	 * @var string
	 */
	private const ANNOTATION_KEY = 'x-openregister-consent';

	/**
	 * Pure property-level evaluator (append-only check + evidence fill). No
	 * Nextcloud dependency, so it needs no DI registration — this listener
	 * owns the one instance it needs.
	 *
	 * @var ConsentEnvelopeEvaluator
	 */
	private readonly ConsentEnvelopeEvaluator $evaluator;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemas Resolves the schema an object belongs to.
	 * @param IUserSession $userSession Current user session (acting identity).
	 * @param IRequest $request Current request (IP + user agent).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
		$this->evaluator = new ConsentEnvelopeEvaluator();
	}//end __construct()

	/**
	 * Handle the inbound event.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consent-evidence-envelope/specs/consent-evidence-envelope/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->evaluate(event: $event, newObject: $event->getObject(), oldObject: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->evaluate(event: $event, newObject: $event->getNewObject(), oldObject: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Evaluate every `x-openregister-consent` property for one write.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event (refused via `setErrors()`+`stopPropagation()`).
	 * @param ObjectEntity $newObject The object as the caller submitted it.
	 * @param ObjectEntity|null $oldObject The previously persisted object, or null on create.
	 *
	 * @return void
	 */
	private function evaluate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $newObject, ?ObjectEntity $oldObject): void {
		try {
			$context = $this->resolveContext(newObject: $newObject, oldObject: $oldObject);
			if ($context === null) {
				return;
			}

			$changed = $this->applyConsentProperties(
				event: $event,
				properties: $context['properties'],
				incomingData: $context['incoming'],
				persistedData: $context['persisted']
			);

			if ($event->isPropagationStopped() === true) {
				// Refused — the event already carries the reason.
				return;
			}

			if ($changed !== null) {
				// Mutate the entity directly, the same idiom
				// CalculationOnSaveListener uses (`$object->setObject($data)`),
				// rather than the separate setModifiedData()/MagicMapper-merge
				// path: this listener has already assembled the complete,
				// correct payload for every touched property, so a shallow
				// merge downstream would be redundant, not additive.
				$newObject->setObject($changed);
			}
		} catch (Throwable $failure) {
			// A consent property that cannot be evaluated must not become the
			// reason nothing can be written; the failure is named in the log
			// (mirrors UniqueConstraintListener's fail-open-on-internal-error
			// posture — this guards against a bug in this listener, not
			// against a caller's malformed input, which is refused above).
			$this->logger->warning(
				message: '[ConsentEnvelopeOnSaveListener] The evaluation itself failed, allowing the save: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try
	}//end evaluate()

	/**
	 * Resolve the schema's consent-shaped properties plus the incoming and
	 * previously persisted payloads for one write, or null when there is
	 * nothing for this listener to do.
	 *
	 * @param ObjectEntity $newObject The object as the caller submitted it.
	 * @param ObjectEntity|null $oldObject The previously persisted object, or null on create.
	 *
	 * @return array{properties: array<string, array<string, mixed>>, incoming: array<string, mixed>, persisted: array<string, mixed>}|null
	 */
	private function resolveContext(ObjectEntity $newObject, ?ObjectEntity $oldObject): ?array {
		$reference = $newObject->getSchema();
		if ($reference === null || $reference === '') {
			return null;
		}

		$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
		$consentProperties = $this->consentProperties(schema: $schema);
		if ($consentProperties === []) {
			return null;
		}

		$incomingData = $newObject->getObject();
		if (is_array($incomingData) === false) {
			return null;
		}

		$persistedData = [];
		if ($oldObject !== null && is_array($oldObject->getObject()) === true) {
			$persistedData = $oldObject->getObject();
		}

		return [
			'properties' => $consentProperties,
			'incoming' => $incomingData,
			'persisted' => $persistedData,
		];
	}//end resolveContext()

	/**
	 * Evaluate every declared consent-shaped property against one write.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event (refused via `setErrors()`+`stopPropagation()`).
	 * @param array<string, array<string, mixed>> $properties The schema's `x-openregister-consent` properties.
	 * @param array<string, mixed> $incomingData The caller-submitted object payload.
	 * @param array<string, mixed> $persistedData The previously persisted object payload (empty on create).
	 *
	 * @return array<string, mixed>|null The updated payload to persist, or null when nothing changed
	 *     (also null once the event is stopped — the caller checks `isPropagationStopped()`).
	 */
	private function applyConsentProperties(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		array $properties,
		array $incomingData,
		array $persistedData
	): ?array {
		$changed = false;
		foreach ($properties as $name => $annotation) {
			$result = $this->evaluateProperty(
				event: $event,
				name: $name,
				annotation: $annotation,
				incoming: ($incomingData[$name] ?? []),
				persisted: ($persistedData[$name] ?? []),
				allData: $incomingData
			);

			if ($event->isPropagationStopped() === true) {
				return null;
			}

			if ($result !== ($incomingData[$name] ?? [])) {
				$incomingData[$name] = $result;
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $incomingData;
	}//end applyConsentProperties()

	/**
	 * The `x-openregister-consent` properties declared on a schema, keyed by property name.
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function consentProperties(Schema $schema): array {
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$found = [];
		foreach ($properties as $name => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$annotation = ($definition[self::ANNOTATION_KEY] ?? null);
			if (is_array($annotation) === true) {
				$found[(string)$name] = $annotation;
			}
		}

		return $found;
	}//end consentProperties()

	/**
	 * Evaluate one consent-shaped property: resolves the NC-coupled inputs
	 * (acting identity, IP, user agent) and delegates the pure append-only
	 * check + evidence fill to {@see ConsentEnvelopeEvaluator}.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event (refused via `setErrors()`+`stopPropagation()`).
	 * @param string $name The property name (for error messages).
	 * @param array<string, mixed> $annotation The property's `x-openregister-consent` declaration.
	 * @param mixed $incoming The caller-submitted value for this property.
	 * @param mixed $persisted The previously persisted value for this property.
	 * @param array<string, mixed> $allData The full incoming object payload (for `subjectProperty` resolution).
	 *
	 * @return array<int, array<string, mixed>> The array to persist (unchanged from the caller's array
	 *     when the event was stopped — the caller must check `isPropagationStopped()`).
	 */
	private function evaluateProperty(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		string $name,
		array $annotation,
		mixed $incoming,
		mixed $persisted,
		array $allData
	): array {
		$result = $this->evaluator->evaluate(
			name: $name,
			annotation: $annotation,
			incoming: $incoming,
			persisted: $persisted,
			actingIdentity: $this->resolveActingIdentity(annotation: $annotation, allData: $allData),
			ipAddress: $this->safeRemoteAddress(),
			userAgent: $this->safeUserAgent()
		);

		if ($result['refused'] === true) {
			$this->refuse(event: $event, name: $name, message: (string)$result['message']);
		}

		return $result['value'];
	}//end evaluateProperty()

	/**
	 * Resolve the identity to record as "by" on a newly appended entry: the
	 * acting Nextcloud user, or — when the caller writes on a data subject's
	 * behalf and no user is active — the declared `subjectProperty`'s value.
	 *
	 * @param array<string, mixed> $annotation The property's `x-openregister-consent` declaration.
	 * @param array<string, mixed> $allData The full incoming object payload.
	 *
	 * @return string|null
	 */
	private function resolveActingIdentity(array $annotation, array $allData): ?string {
		$actor = $this->userSession->getUser();
		if ($actor !== null) {
			return $actor->getUID();
		}

		$subjectProperty = ($annotation['subjectProperty'] ?? null);
		if (is_string($subjectProperty) === false) {
			return null;
		}

		$resolved = ($allData[$subjectProperty] ?? null);
		if (is_string($resolved) === true) {
			return $resolved;
		}

		return null;
	}//end resolveActingIdentity()

	/**
	 * Refuse the current write with a structured error.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event to stop.
	 * @param string $name The offending property.
	 * @param string $message The reason.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $name, string $message): void {
		$event->setErrors([
			'code' => self::ERROR_CODE,
			'message' => $message,
			'property' => $name,
		]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * The caller's remote address, or null when unavailable (e.g. a CLI/occ write).
	 *
	 * @return string|null
	 */
	private function safeRemoteAddress(): ?string {
		try {
			$address = $this->request->getRemoteAddress();
			if ($address === '') {
				return null;
			}

			return $address;
		} catch (Throwable $failure) {
			return null;
		}
	}//end safeRemoteAddress()

	/**
	 * The caller's User-Agent header, or null when unavailable.
	 *
	 * @return string|null
	 */
	private function safeUserAgent(): ?string {
		try {
			$agent = $this->request->getHeader('User-Agent');
			if ($agent === '') {
				return null;
			}

			return $agent;
		} catch (Throwable $failure) {
			return null;
		}
	}//end safeUserAgent()
}//end class
