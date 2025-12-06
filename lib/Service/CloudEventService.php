<?php
/**
 * OpenRegister CloudEvent Service
 *
 * Service for formatting events as CloudEvents specification compliant payloads.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

/**
 * CloudEventService formats events as CloudEvents specification compliant payloads
 *
 * Formats events according to the CloudEvents specification (https://cloudevents.io/).
 * CloudEvents is a specification for describing event data in a common way, making it
 * easier to integrate services that produce and consume events.
 *
 * CloudEvents provides:
 * - Consistent event structure across systems
 * - Interoperability between different event systems
 * - Standardized metadata (source, type, id, time)
 * - Extensibility through attributes
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */
class CloudEventService
{


    /**
     * Format a request as a CloudEvent
     *
     * Creates a CloudEvent-compliant payload from an HTTP request.
     * This allows external systems to receive standardized event notifications
     * before requests are processed by controllers.
     *
     * @param IRequest $request   The HTTP request
     * @param string   $eventType The event type (e.g., 'object.creating', 'object.updating')
     * @param array    $data      Additional event data
     *
     * @return array CloudEvent-formatted payload
     */
    public function formatRequestAsCloudEvent(
        IRequest $request,
        string $eventType,
        array $data=[]
    ): array {
        // Get request body.
        $requestBody = $request->getParams();
        $rawBody     = '';
        if (method_exists($request, 'getRawBody') === true) {
            $rawBody = $request->getRawBody();
        }

        // Parse JSON body if present.
        $parsedBody = [];
        if (empty($rawBody) === false) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsedBody = $decoded;
            }
        }

        // Merge parsed body with request params.
        $bodyData = array_merge($parsedBody, $requestBody);

        // Build CloudEvent payload according to CloudEvents 1.0 specification.
        return [
            // Required CloudEvent attributes.
            'specversion'     => '1.0',
            'type'            => $eventType,
            'source'          => $this->getSource($request),
            'id'              => Uuid::v4()->toRfc4122(),
            'time'            => date('c'),

            // Optional CloudEvent attributes.
            'datacontenttype' => $this->getContentTypeHeader($request),
            'subject'         => $this->getSubject($request),
            'dataschema'      => null,

            // Request-specific data.
            'data'            => array_merge(
                [
                    'method'      => $request->getMethod(),
                    'path'        => $request->getPathInfo(),
                    'queryParams' => $request->getParams(),
                    'headers'     => $this->getRequestHeaders($request),
                    'body'        => $bodyData,
                ],
                $data
            ),

            // OpenRegister-specific extensions.
            'openregister'    => [
                'app'     => 'openregister',
                'version' => $this->getAppVersion(),
            ],
        ];

    }//end formatRequestAsCloudEvent()


    /**
     * Get event source
     *
     * Determines the source identifier for the CloudEvent.
     * The source identifies the context in which the event occurred.
     * Format: {protocol}://{host}/apps/openregister
     *
     * @param IRequest $request The HTTP request to extract source from
     *
     * @return string Event source identifier (URI format)
     */
    private function getSource(IRequest $request): string
    {
        // Build source URI from request protocol and host.
        // Protocol is determined from server protocol (https or http).
        $host  = $request->getServerProtocol() === 'https' ? 'https://' : 'http://';
        $host .= $request->getServerHost();

        // Append OpenRegister app path to source.
        return $host.'/apps/openregister';

    }//end getSource()


    /**
     * Get event subject
     *
     * Determines the subject identifier for the CloudEvent.
     * The subject identifies the resource that the event relates to.
     *
     * @param IRequest $request The HTTP request
     *
     * @return string|null Event subject identifier or null
     */
    private function getSubject(IRequest $request): ?string
    {
        $path = $request->getPathInfo();

        // Extract resource identifiers from path.
        // Example: /api/objects/{register}/{schema}/{id}.
        if (preg_match('#/api/objects/([^/]+)/([^/]+)(?:/([^/]+))?#', $path, $matches) === 1) {
            if (($matches[3] ?? null) !== null) {
                return 'object:'.$matches[1].'/'.$matches[2].'/'.$matches[3];
            }

            return 'object:'.$matches[1].'/'.$matches[2];
        }

        return null;

    }//end getSubject()


    /**
     * Get request headers
     *
     * Extracts relevant headers from the request.
     *
     * @param IRequest $request The HTTP request
     *
     * @return array Request headers
     */
    private function getRequestHeaders(IRequest $request): array
    {
        $headers = [];

        // Get common headers.
        $headerKeys = [
            'Content-Type',
            'Accept',
            'Authorization',
            'User-Agent',
            'X-Requested-With',
        ];

        foreach ($headerKeys as $key) {
            $value = $request->getHeader($key);
            if ($value !== '') {
                $headers[$key] = $value;
            }
        }

        return $headers;

    }//end getRequestHeaders()


    /**
     * Get content type header from request
     *
     * Returns Content-Type header if present, otherwise defaults to 'application/json'.
     *
     * @param mixed $request Request object with getHeader method
     *
     * @return string Content type header value
     */
    private function getContentTypeHeader(mixed $request): string
    {
        $contentType = $request->getHeader('Content-Type');

        if (empty($contentType) === false) {
            return $contentType;
        }

        return 'application/json';
    }//end getContentTypeHeader()

    /**
     * Get application version
     *
     * @return string Application version
     */
    private function getAppVersion(): string
    {
        // @todo Get actual version from appinfo/info.xml or composer.json.
        return '1.0.0';
    }//end getAppVersion()


}//end class
