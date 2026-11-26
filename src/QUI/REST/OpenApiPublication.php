<?php

declare(strict_types=1);

namespace QUI\REST;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

final class OpenApiPublication
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var array<string, array<string, mixed>> */
    private array $publishedComponents = [];

    /**
     * @param array<string, mixed> $specification
     * @param array<string, true> $operationIds
     */
    private function __construct(
        private readonly array $specification,
        private readonly string $sourceUrl,
        private readonly string $prefix,
        private readonly array $operationIds
    ) {
    }

    /** @return array<string, true|list<string>> */
    public static function parseSelection(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid OpenAPI selection.');
        }

        $selection = [];

        foreach ($decoded as $provider => $operations) {
            if (
                !is_string($provider)
                || $provider === ''
                || ($operations !== true && (!is_array($operations) || !array_is_list($operations)))
            ) {
                throw new InvalidArgumentException('Invalid OpenAPI provider selection.');
            }

            if ($operations !== true) {
                foreach ($operations as $operation) {
                    if (!is_string($operation)) {
                        throw new InvalidArgumentException('Invalid OpenAPI endpoint selection.');
                    }
                }

                if ($operations === []) {
                    continue;
                }

                $operations = array_values(array_unique($operations));
            }

            $selection[$provider] = $operations;
        }

        return $selection;
    }

    /**
     * @param array<string, mixed> $specification
     * @return array<string, array{string, string}>
     */
    public static function getOperations(array $specification): array
    {
        $operations = [];

        foreach ($specification['paths'] ?? [] as $path => $pathItem) {
            if (!is_string($path) || !str_starts_with($path, '/') || !is_array($pathItem)) {
                continue;
            }

            foreach (self::METHODS as $method) {
                if (isset($pathItem[$method]) && is_array($pathItem[$method])) {
                    $operations[strtoupper($method) . ' ' . $path] = [$path, $method];
                }
            }
        }

        return $operations;
    }

    /** @param array<string, mixed> $specification */
    public static function getVersion(array $specification): string
    {
        $version = $specification['openapi'] ?? '';

        if (!is_string($version) || !preg_match('/^3\.[01]\.\d+$/D', $version)) {
            throw new InvalidArgumentException('OpenAPI publication supports versions 3.0 and 3.1.');
        }

        return $version;
    }

    /**
     * @param array<string, array{
     *     specification: array<string, mixed>,
     *     url: string,
     *     operations: true|list<string>
     * }> $sources
     * @return array<string, mixed>
     */
    public static function build(array $sources, string $serverUrl): array
    {
        $serverUri = new Uri($serverUrl);

        if (!in_array($serverUri->getScheme(), ['http', 'https'], true) || $serverUri->getHost() === '') {
            throw new InvalidArgumentException('An absolute REST server URL is required.');
        }

        $combined = count($sources) > 1;
        $version = '';
        $paths = [];
        $pathTemplates = [];
        $components = [];
        $tags = [];
        $operationIds = [];
        $providerNumber = 0;
        $info = [
            'title' => 'QUIQQER REST API',
            'version' => '1.0.0'
        ];

        foreach ($sources as $provider => $source) {
            $specification = $source['specification'];
            $sourceVersion = self::getVersion($specification);

            if ($version !== '' && substr($version, 0, 4) !== substr($sourceVersion, 0, 4)) {
                throw new InvalidArgumentException('Select providers using the same OpenAPI version family.');
            }

            if ($version === '' || version_compare($sourceVersion, $version, '>')) {
                $version = $sourceVersion;
            }

            if (!$combined) {
                $info = $specification['info'] ?? $info;
            }

            $operations = self::getOperations($specification);
            $selected = $source['operations'] === true ? array_keys($operations) : $source['operations'];
            $selectedIds = [];

            foreach ($selected as $id) {
                if (!isset($operations[$id])) {
                    throw new InvalidArgumentException('OpenAPI endpoint is unavailable: ' . $provider . ' / ' . $id);
                }

                [$path, $method] = $operations[$id];
                $operationId = $specification['paths'][$path][$method]['operationId'] ?? null;

                if (is_string($operationId)) {
                    $selectedIds[$operationId] = true;
                }
            }

            $providerNumber++;
            $prefix = $combined ? 'api' . $providerNumber . '_' : '';
            $Publication = new self($specification, $source['url'], $prefix, $selectedIds);
            $sourceTags = [];

            foreach ($selected as $id) {
                [$path, $method] = $operations[$id];
                $template = preg_replace('/\{[^}]+\}/', '{}', $path);

                if (
                    $template === null
                    || (isset($pathTemplates[$template]) && $pathTemplates[$template] !== $path)
                    || isset($paths[$path][$method])
                ) {
                    throw new InvalidArgumentException('Conflicting OpenAPI endpoint: ' . $id);
                }

                $pathTemplates[$template] = $path;
                $pathItem = $specification['paths'][$path];
                $pathSettings = array_diff_key($pathItem, array_flip(self::METHODS));
                $pathSettings = $Publication->rewrite($pathSettings);
                $existingSettings = array_diff_key($paths[$path] ?? [], array_flip(self::METHODS));

                if (isset($paths[$path]) && $existingSettings !== $pathSettings) {
                    throw new InvalidArgumentException('Conflicting OpenAPI path settings: ' . $path);
                }

                $operation = $pathItem[$method];
                $security = $operation['security'] ?? $specification['security'] ?? null;
                $operation = $Publication->rewrite($operation);

                if (is_array($security)) {
                    $operation['security'] = $Publication->copySecurity($security);
                }

                $publishedId = $operation['operationId'] ?? null;

                if (is_string($publishedId)) {
                    if (isset($operationIds[$publishedId])) {
                        throw new InvalidArgumentException('Duplicate OpenAPI operation ID: ' . $publishedId);
                    }

                    $operationIds[$publishedId] = true;
                }

                foreach ($operation['tags'] ?? [] as $tag) {
                    $sourceTags[$tag] = true;
                }

                $paths[$path] = ($paths[$path] ?? $pathSettings) + [$method => $operation];
            }

            foreach ($specification['tags'] ?? [] as $tag) {
                $name = $tag['name'] ?? '';

                if (isset($sourceTags[$name])) {
                    $tags[$name] ??= $Publication->rewrite($tag);
                }
            }

            foreach ($Publication->publishedComponents as $type => $entries) {
                $components[$type] = ($components[$type] ?? []) + $entries;
            }
        }

        if ($paths === []) {
            throw new InvalidArgumentException('Select at least one documented OpenAPI endpoint.');
        }

        $document = [
            'openapi' => $version,
            'info' => $info,
            'servers' => [['url' => $serverUrl]],
            'paths' => $paths
        ];

        if ($tags !== []) {
            $document['tags'] = array_values($tags);
        }

        if ($components !== []) {
            $document['components'] = $components;
        }

        return $document;
    }

    /**
     * Copy components on demand so individual endpoint selections only publish their dependencies.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function rewrite(array $data, bool $namedEntries = false): array
    {
        foreach ($data as $key => $value) {
            if ($namedEntries) {
                if (is_array($value)) {
                    $data[$key] = $this->rewrite($value);
                }

                continue;
            }

            if (is_string($value) && in_array($key, ['$ref', 'operationRef'], true)) {
                $data[$key] = $this->rewriteReference($value);
            } elseif ($key === 'operationId' && is_string($value) && isset($this->operationIds[$value])) {
                $data[$key] = $this->prefix . $value;
            } elseif ($key === 'discriminator' && is_array($value)) {
                foreach ($value['mapping'] ?? [] as $tag => $reference) {
                    if (isset($this->specification['components']['schemas'][$reference])) {
                        $value['mapping'][$tag] = $this->copyComponent('schemas', $reference);
                    } else {
                        $value['mapping'][$tag] = $this->rewriteReference($reference);
                    }
                }

                $data[$key] = $value;
            } elseif (is_array($value) && !in_array($key, ['example', 'value', 'default', 'enum', 'const'], true)) {
                if ($key === 'examples' && array_is_list($value)) {
                    continue;
                }

                $isMap = in_array($key, [
                    'properties', 'patternProperties', 'dependentSchemas', '$defs',
                    'headers', 'links', 'responses', 'content', 'encoding', 'callbacks', 'examples'
                ], true);
                $data[$key] = $this->rewrite($value, $isMap);
            }
        }

        return $data;
    }

    private function rewriteReference(string $reference): string
    {
        if (preg_match('~^#/components/([^/]+)/([^/]+)(.*)$~', $reference, $parts)) {
            [, $type, $pointer, $suffix] = $parts;
            $name = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($pointer));
            $publishedName = $this->copyComponent($type, $name);
            $publishedPointer = str_replace(['~', '/'], ['~0', '~1'], $publishedName);

            return '#/components/' . $type . '/' . rawurlencode($publishedPointer) . $suffix;
        }

        return (string)UriResolver::resolve(new Uri($this->sourceUrl), new Uri($reference));
    }

    private function copyComponent(string $type, string $name): string
    {
        $publishedName = $this->prefix . $name;

        if (!array_key_exists($publishedName, $this->publishedComponents[$type] ?? [])) {
            $definition = $this->specification['components'][$type][$name] ?? null;

            if ($definition === null) {
                throw new InvalidArgumentException('Missing OpenAPI component: ' . $type . '/' . $name);
            }

            // Register before visiting references so recursive schemas can refer to themselves.
            $this->publishedComponents[$type][$publishedName] = null;
            $this->publishedComponents[$type][$publishedName] = is_array($definition)
                ? $this->rewrite($definition)
                : $definition;
        }

        return $publishedName;
    }

    /**
     * @param array<array-key, mixed> $security
     * @return list<object>
     */
    private function copySecurity(array $security): array
    {
        $requirements = [];

        foreach ($security as $alternative) {
            $requirement = [];
            $alternative = $alternative instanceof \stdClass ? get_object_vars($alternative) : $alternative;

            if (!is_array($alternative)) {
                throw new InvalidArgumentException('Invalid OpenAPI security requirement.');
            }

            foreach ($alternative as $name => $scopes) {
                if (!is_string($name) || !is_array($scopes)) {
                    throw new InvalidArgumentException('Invalid OpenAPI security scheme.');
                }

                $publishedName = $this->copyComponent('securitySchemes', $name);
                $requirement[$publishedName] = $scopes;
            }

            $requirements[] = (object)$requirement;
        }

        return $requirements;
    }
}
