<?php

/**
 * This file contains \QUI\REST\EventHandler
 */

namespace QUI\REST;

use QUI;
use QUI\Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function in_array;

/**
 * QUIQQER Event Handling
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class EventHandler
{
    private const OPENAPI_PATH = '/openapi.json';

    /**
     * @param QUI\Rewrite $Rewrite
     * @param string $url
     * @throws Exception
     * @throws \Exception
     */
    public static function onRequest(QUI\Rewrite $Rewrite, string $url): void
    {
        $Request = QUI::getRequest();

        if ($Request->getPathInfo() === self::OPENAPI_PATH && self::matchesHost($Request)) {
            self::sendOpenApi($Request);
        }

        if (!self::matchesRequest($Request)) {
            return;
        }

        $requestedLanguage = QUI\REST\Utils\RequestUtils::getRequestedLanguage();
        $availableLanguages = QUI::availableLanguages();

        if ($requestedLanguage && in_array($requestedLanguage, $availableLanguages)) {
            QUI::getUserBySession()->getLocale()->setCurrent($requestedLanguage);
            QUI::getLocale()->setCurrent($requestedLanguage);
        } else {
            // set default system language
            $language = QUI::conf('globals', 'standardLanguage');
            QUI::getUserBySession()->getLocale()->setCurrent($language);
            QUI::getLocale()->setCurrent($language);
        }

        $Server = Server::getCurrentInstance();
        QUI::getEvents()->fireEvent('restInit', [$Server, $Request]);
        $Server->run();
        exit;
    }

    public static function onWebsiteLockerAllowRequest(
        Request $Request,
        QUI\Rewrite $Rewrite,
        string $url
    ): bool {
        return self::matchesRequest($Request);
    }

    protected static function matchesRequest(Request $Request): bool
    {
        $basePath = Settings::getConfig()->getValue('general', 'basePath');

        return self::matchesHost($Request)
            && self::isRestRequest($Request->getRequestUri(), is_string($basePath) ? $basePath : '');
    }

    private static function matchesHost(Request $Request): bool
    {
        $baseHost = Settings::getConfig()->getValue('general', 'baseHost');

        if (!is_string($baseHost) || $baseHost === '') {
            return true;
        }

        $baseHost = str_replace(['http://', 'https://'], '', $baseHost);
        $baseHost = rtrim($baseHost, '/');

        return $baseHost === '' || $Request->getHost() === $baseHost;
    }

    private static function sendOpenApi(Request $Request): never
    {
        if (!in_array($Request->getMethod(), ['GET', 'HEAD'], true)) {
            $Response = new JsonResponse(['error' => 'Method not allowed.'], 405, ['Allow' => 'GET, HEAD']);
        } else {
            try {
                $Config = Settings::getConfig();
                $stored = $Config->getValue('openapi', 'selection');
                $selection = OpenApiPublication::parseSelection(is_string($stored) ? $stored : '');

                if ($selection === []) {
                    $Response = new JsonResponse(['error' => 'OpenAPI publication is disabled.'], 404);
                } else {
                    $contact = Settings::getOpenApiContact($Config);
                    $specification = Server::getCurrentInstance()->getPublishedOpenApi($selection, $contact);
                    $Response = new JsonResponse($specification);
                }
            } catch (Throwable $Exception) {
                QUI\System\Log::writeException($Exception);
                $Response = new JsonResponse(['error' => 'OpenAPI publication is unavailable.'], 500);
            }
        }

        $Response->prepare($Request)->send();
        exit;
    }

    public static function isRestRequest(string $requestUri, string $basePath): bool
    {
        if (trim($basePath, '/') === '') {
            return true;
        }

        $requestPath = parse_url($requestUri, PHP_URL_PATH);

        if (!is_string($requestPath)) {
            return false;
        }

        $requestPath = '/' . trim($requestPath, '/');
        $basePath = '/' . trim($basePath, '/');

        return $requestPath === $basePath
            || str_starts_with($requestPath, $basePath . '/');
    }
}
