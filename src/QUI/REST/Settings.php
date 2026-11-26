<?php

/**
 * This file contains QUI\REST\Settings
 */

namespace QUI\REST;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use QUI;
use QUI\Projects\Site\Utils as SiteUtils;

/**
 * REST package settings
 */
class Settings extends QUI\Utils\Singleton
{
    /**
     * Return the required REST package configuration.
     *
     * @throws QUI\Exception
     */
    public static function getConfig(): QUI\Config
    {
        $Config = QUI::getPackage('quiqqer/rest')->getConfig();

        if ($Config === null) {
            throw new QUI\Exception('REST configuration is not available.');
        }

        return $Config;
    }

    /** @return array{name?: string, url?: string, email?: string} */
    public static function getOpenApiContact(QUI\Config $Config): array
    {
        $contact = [];
        $name = $Config->getValue('openapi', 'contactName');
        $siteLink = $Config->getValue('openapi', 'contactSite');
        $email = $Config->getValue('openapi', 'contactEmail');
        $email = is_string($email) ? trim($email) : '';

        if (is_string($name) && trim($name) !== '') {
            $contact['name'] = trim($name);
        }

        if (is_string($siteLink) && str_starts_with($siteLink, 'index.php?')) {
            try {
                $Site = SiteUtils::getSiteByLink($siteLink);
                $siteBase = new Uri($Site->getProject()->getVHost(true, true));
                $installationPath = rtrim('/' . ltrim(URL_DIR, '/'), '/') . '/';
                $siteReference = new Uri($installationPath . $Site->getUrl());
                $contact['url'] = (string)UriResolver::resolve($siteBase, $siteReference);
            } catch (QUI\Exception | InvalidArgumentException $Exception) {
                QUI\System\Log::writeDebugException($Exception);
            }
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $contact['email'] = $email;
        }

        return $contact;
    }
}
