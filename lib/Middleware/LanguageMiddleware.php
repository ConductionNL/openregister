<?php

/**
 * OpenRegister Language Middleware
 *
 * Middleware that intercepts all requests to parse the Accept-Language header
 * and store the preferred language in the LanguageService. Also checks for
 * the _translations=all query parameter.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Service\LanguageService;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Middleware that reads the Accept-Language header and _translations query parameter.
 *
 * This middleware runs before any controller action and populates the
 * LanguageService with the client's language preferences. It also adds
 * the Content-Language response header to outgoing responses.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class LanguageMiddleware extends Middleware
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The incoming request
     * @param LanguageService $languageService The request-scoped language service
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly LanguageService $languageService
    ) {
    }//end __construct()

    /**
     * Called before the controller method is invoked.
     *
     * Parses the Accept-Language header and stores the result in LanguageService.
     * Also checks for the _translations=all query parameter.
     *
     * @param mixed  $controller The controller instance
     * @param string $methodName The method name being called
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeController($controller, $methodName): void
    {
        // Parse Accept-Language header.
        $acceptLanguage = $this->request->getHeader('Accept-Language');
        if ($acceptLanguage !== '' && $acceptLanguage !== null) {
            $acceptedLanguages = LanguageService::parseAcceptLanguageHeader($acceptLanguage);
            $this->languageService->setAcceptedLanguages($acceptedLanguages);

            if (empty($acceptedLanguages) === false) {
                // Use the base language (strip region) as preferred.
                $preferred = strtolower(explode('-', $acceptedLanguages[0])[0]);
                $this->languageService->setPreferredLanguage($preferred);
            }
        }

        // Check for _translations query parameter.
        $translations = $this->request->getParam('_translations');
        if ($translations === 'all') {
            $this->languageService->setReturnAllTranslations(true);
        }
    }//end beforeController()

    /**
     * Called after the controller method returns a response.
     *
     * Adds the Content-Language header to the response indicating
     * which language was served.
     *
     * @param mixed    $controller The controller instance
     * @param string   $methodName The method name that was called
     * @param Response $response   The response object
     *
     * @return Response The modified response with Content-Language header
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterController($controller, $methodName, Response $response): Response
    {
        // Add Content-Language header.
        $language = $this->languageService->getPreferredLanguage();
        $response->addHeader('Content-Language', $language);

        // If fallback was used, add a custom header to indicate this.
        if ($this->languageService->isFallbackUsed() === true) {
            $response->addHeader('X-Content-Language-Fallback', 'true');
        }

        return $response;
    }//end afterController()
}//end class
