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

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
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
	 * Fields the platform fills on every newly appended entry; a
	 * caller-supplied value under any of these keys is discarded.
	 *
	 * @var array<int, string>
	 */
	private const EVIDENCE_FIELDS = ['by', 'timestamp', 'ip', 'userAgent', 'contentHash'];

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
			$reference = $newObject->getSchema();
			if ($reference === null || $reference === '') {
				return;
			}

			$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
			$consentProperties = $this->consentProperties(schema: $schema);
			if ($consentProperties === []) {
				return;
			}

			$incomingData = $newObject->getObject();
			if (is_array($incomingData) === false) {
				return;
			}

			$persistedData = [];
			if ($oldObject !== null && is_array($oldObject->getObject()) === true) {
				$persistedData = $oldObject->getObject();
			}

			$changed = false;
			foreach ($consentProperties as $name => $annotation) {
				$result = $this->evaluateProperty(
					event: $event,
					name: $name,
					annotation: $annotation,
					incoming: ($incomingData[$name] ?? []),
					persisted: ($persistedData[$name] ?? []),
					allData: $incomingData
				);

				if ($event->isPropagationStopped() === true) {
					// Refused — the event already carries the reason.
					return;
				}

				if ($result !== ($incomingData[$name] ?? [])) {
					$incomingData[$name] = $result;
					$changed = true;
				}
			}

			if ($changed === true) {
				// Mutate the entity directly, the same idiom
				// CalculationOnSaveListener uses (`$object->setObject($data)`),
				// rather than the separate setModifiedData()/MagicMapper-merge
				// path: this listener has already assembled the complete,
				// correct payload for every touched property, so a shallow
				// merge downstream would be redundant, not additive.
				$newObject->setObject($incomingData);
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
	 * Evaluate one consent-shaped property: enforce append-only, then fill
	 * evidence on every newly appended entry.
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
		if (is_array($incoming) === true) {
			$incoming = array_values($incoming);
		} else {
			$incoming = [];
		}

		if (is_array($persisted) === true) {
			$persisted = array_values($persisted);
		} else {
			$persisted = [];
		}

		if (count($incoming) < count($persisted)) {
			$this->refuse(event: $event, name: $name, message: sprintf(
				'Property "%s" is append-only (x-openregister-consent): the submitted value has fewer entries than the persisted value.',
				$name
			));

			return $incoming;
		}

		for ($index = 0; $index < count($persisted); $index++) {
			if (($incoming[$index] ?? null) !== $persisted[$index]) {
				$this->refuse(event: $event, name: $name, message: sprintf(
					'Property "%s" is append-only (x-openregister-consent): entry %d cannot be changed, only new entries may be appended.',
					$name,
					$index
				));

				return $incoming;
			}
		}

		$purpose = (string)($annotation['purpose'] ?? '');
		$subjectProperty = $annotation['subjectProperty'] ?? null;

		$actor = $this->userSession->getUser();
		$actingIdentity = null;
		if ($actor !== null) {
			$actingIdentity = $actor->getUID();
		}

		if ($actingIdentity === null && is_string($subjectProperty) === true) {
			$resolved = ($allData[$subjectProperty] ?? null);
			if (is_string($resolved) === true) {
				$actingIdentity = $resolved;
			}
		}

		for ($index = count($persisted); $index < count($incoming); $index++) {
			$entry = $incoming[$index];
			if (is_array($entry) === false) {
				continue;
			}

			$incoming[$index] = $this->fillEvidence(entry: $entry, purpose: $purpose, actingIdentity: $actingIdentity);
		}

		return $incoming;
	}//end evaluateProperty()

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
	 * Fill the read-only evidentiary fields on one newly appended entry.
	 *
	 * @param array<string, mixed> $entry The caller-submitted entry.
	 * @param string $purpose The property's declared purpose.
	 * @param string|null $actingIdentity The resolved acting identity ("by").
	 *
	 * @return array<string, mixed> The entry with evidence fields filled.
	 */
	private function fillEvidence(array $entry, string $purpose, ?string $actingIdentity): array {
		foreach (self::EVIDENCE_FIELDS as $field) {
			unset($entry[$field]);
		}

		$decision = (string)($entry['decision'] ?? '');
		$evidenceOf = (string)($entry['evidenceOf'] ?? '');
		$timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

		$entry['by'] = $actingIdentity;
		$entry['timestamp'] = $timestamp;
		$entry['ip'] = $this->safeRemoteAddress();
		$entry['userAgent'] = $this->safeUserAgent();
		$entry['contentHash'] = hash('sha256', $purpose . $decision . $evidenceOf);

		$entry['withdrawnAt'] = null;
		if ($decision === 'withdrawn') {
			$entry['withdrawnAt'] = $timestamp;
		}

		return $entry;
	}//end fillEvidence()

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
