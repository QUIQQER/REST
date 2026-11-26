<?php

/**
 * This file contains QUI\REST\Settings
 */

namespace QUI\REST;

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
        $contactUrl = $Config->getValue('openapi', 'contactSite');
        $contactUrl = is_string($contactUrl) ? trim($contactUrl) : '';
        $email = $Config->getValue('openapi', 'contactEmail');
        $email = is_string($email) ? trim($email) : '';

        if (is_string($name) && trim($name) !== '') {
            $contact['name'] = trim($name);
        }

        $siteLink = ltrim($contactUrl, '/');

        if (str_starts_with($siteLink, 'index.php?') && SiteUtils::isSiteLink($siteLink)) {
            try {
                $Site = SiteUtils::getSiteByLink($siteLink);
                $contactUrl = $Site->getUrlRewrittenWithHost();
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeDebugException($Exception);
            }
        }

        if (
            filter_var($contactUrl, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string)parse_url($contactUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            $contact['url'] = $contactUrl;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $contact['email'] = $email;
        }

        return $contact;
    }
}
