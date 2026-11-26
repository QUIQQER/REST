<?php

use QUI\REST\Server;

QUI::getAjax()->registerFunction(
    'package_quiqqer_rest_ajax_openapi_options',
    static function (): array {
        return Server::getInstance()->getOpenApiPublicationOptions();
    },
    [],
    ['Permission::checkAdminUser', 'quiqqer.settings']
);
