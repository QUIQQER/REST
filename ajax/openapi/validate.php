<?php

use QUI\REST\OpenApiPublication;
use QUI\REST\Server;

QUI::getAjax()->registerFunction(
    'package_quiqqer_rest_ajax_openapi_validate',
    static function (string $selection): array {
        try {
            $providers = OpenApiPublication::parseSelection($selection);
            Server::getInstance()->getPublishedOpenApi($providers);
        } catch (InvalidArgumentException | JsonException $Exception) {
            return ['error' => $Exception->getMessage()];
        }

        return ['error' => null];
    },
    ['selection'],
    ['Permission::checkAdminUser', 'quiqqer.settings']
);
