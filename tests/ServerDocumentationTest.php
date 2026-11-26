<?php

namespace QUI\REST\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QUI\Config;
use QUI\Projects\Manager as ProjectManager;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\REST\OpenApiPublication;
use QUI\REST\Response;
use QUI\REST\Settings;
use QUI\REST\Tests\Fixtures\DocumentationTestProvider;
use QUI\REST\Tests\Fixtures\RoutingTestServer;
use RuntimeException;

class ServerDocumentationTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->temporaryFiles = [];
    }

    public function testDocumentationListReturnsProviderLinksAsJson(): void
    {
        $definitionFile = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Documented API',
                'version' => '1.0.0'
            ],
            'paths' => []
        ]);
        $Server = new RoutingTestServer([
            new DocumentationTestProvider(
                'Documented',
                'Documented API',
                $definitionFile
            ),
            new DocumentationTestProvider(
                'Undocumented',
                'Undocumented API',
                false
            )
        ]);

        $Response = $Server->onGetDocsList(
            new ServerRequest('GET', '/api/list/unsupported'),
            new Response(),
            ['format' => 'unsupported']
        );
        $entries = json_decode(
            (string)$Response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('application/json', $Response->getHeaderLine('Content-Type'));
        self::assertSame('Documented API', $entries[0]['title']);
        self::assertSame(
            'https://example.test/api/docs/Documented/html',
            $entries[0]['docsHtml']
        );
        self::assertSame(
            'https://example.test/api/docs/Documented/json',
            $entries[0]['docsJson']
        );
        self::assertFalse($entries[1]['docsHtml']);
        self::assertFalse($entries[1]['docsJson']);
    }

    public function testUnknownDocumentationProviderReturnsMessage(): void
    {
        $Server = new RoutingTestServer([]);

        $Response = $Server->onGetDocsApi(
            new ServerRequest('GET', '/api/docs/Unknown/json'),
            new Response(),
            [
                'api_name' => 'Unknown',
                'format' => 'json'
            ]
        );

        self::assertSame(
            'No OpenApi docs available for API "Unknown".',
            (string)$Response->getBody()
        );
    }

    public function testDocumentationListCanBeRenderedAsHtml(): void
    {
        $Server = new RoutingTestServer([
            new DocumentationTestProvider(
                'Documented',
                'Documented API',
                __FILE__
            )
        ]);

        $Response = $Server->onGetDocsList(
            new ServerRequest('GET', '/api/list/html'),
            new Response(),
            ['format' => 'html']
        );

        self::assertSame('text/html', $Response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Documented API', (string)$Response->getBody());
        self::assertStringContainsString(
            'https://example.test/api/docs/Documented/html',
            (string)$Response->getBody()
        );
    }

    public function testProviderWithoutDefinitionReturnsMessage(): void
    {
        $Server = new RoutingTestServer([
            new DocumentationTestProvider(
                'Undocumented',
                'Undocumented API',
                false
            )
        ]);

        $Response = $Server->onGetDocsApi(
            new ServerRequest('GET', '/api/docs/Undocumented/json'),
            new Response(),
            [
                'api_name' => 'Undocumented',
                'format' => 'json'
            ]
        );

        self::assertSame(
            'No OpenApi docs available for API "Undocumented".',
            (string)$Response->getBody()
        );
    }

    public function testSupportedOpenApiDefinitionIsExtendedAndReturnedAsJson(): void
    {
        $definitionFile = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Documented API',
                'version' => '1.0.0'
            ],
            'paths' => [
                '/items' => [
                    'get' => [
                        'responses' => []
                    ],
                    'post' => [
                        'parameters' => [
                            [
                                'in' => 'query',
                                'name' => 'source',
                                'schema' => [
                                    'type' => 'string'
                                ]
                            ]
                        ],
                        'responses' => []
                    ]
                ]
            ]
        ]);
        $Server = new RoutingTestServer([
            new DocumentationTestProvider(
                'Documented',
                'Documented API',
                $definitionFile
            )
        ]);

        $Response = $Server->onGetDocsApi(
            new ServerRequest('GET', '/api/docs/Documented/unsupported'),
            new Response(),
            [
                'api_name' => 'Documented',
                'format' => 'unsupported'
            ]
        );
        $specification = json_decode(
            (string)$Response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('application/json', $Response->getHeaderLine('Content-Type'));
        self::assertSame(
            [['url' => '/api/']],
            $specification['servers']
        );
        self::assertContains(
            [
                'in' => 'header',
                'name' => 'Accept-Language',
                'description' => 'Language to use for the response in RFC 5646 format. QUIQQER may ignore subtags.',
                'schema' => [
                    'type' => 'string',
                    'example' => 'de-DE'
                ]
            ],
            $specification['paths']['/items']['get']['parameters']
        );
        self::assertCount(
            2,
            $specification['paths']['/items']['post']['parameters']
        );
    }

    public function testSupportedOpenApiDefinitionCanBeRenderedAsHtml(): void
    {
        $definitionFile = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Documented API',
                'version' => '1.0.0'
            ],
            'paths' => []
        ]);
        $Server = new RoutingTestServer([
            new DocumentationTestProvider(
                'Documented',
                'Documented API',
                $definitionFile
            )
        ]);

        $Response = $Server->onGetDocsApi(
            new ServerRequest('GET', '/api/docs/Documented/html'),
            new Response(),
            [
                'api_name' => 'Documented',
                'format' => 'html'
            ]
        );

        self::assertSame('text/html', $Response->getHeaderLine('Content-Type'));
        self::assertStringContainsString(
            '<title>Documented API</title>',
            (string)$Response->getBody()
        );
        self::assertStringContainsString(
            'url: "/api/docs/Documented/json"',
            (string)$Response->getBody()
        );
        self::assertStringContainsString(
            'oauth2RedirectUrl: window.location.origin + "',
            (string)$Response->getBody()
        );
        self::assertStringNotContainsString(
            'https://example.test',
            (string)$Response->getBody()
        );
    }

    public function testInvalidOpenApiJsonReturnsInternalServerError(): void
    {
        $definitionFile = $this->createRawDefinitionFile('{invalid JSON');
        $Server = new RoutingTestServer([
            new DocumentationTestProvider(
                'Invalid',
                'Invalid API',
                $definitionFile
            )
        ]);
        $Server->registerBasePaths();

        $Response = $Server->getSlim()->handle(
            new ServerRequest('GET', '/api/docs/Invalid/json')
        );

        self::assertSame(500, $Response->getStatusCode());
        self::assertSame('', (string)$Response->getBody());
    }

    public function testYamlOpenApiDefinitionsAreExtendedAndReturnedAsJson(): void
    {
        foreach (['yaml', 'yml'] as $extension) {
            $definitionFile = $this->createRawDefinitionFile(
                <<<'YAML'
openapi: 3.0.3
info:
  title: YAML API
  version: 1.0.0
paths:
  /items:
    get:
      responses: {}
YAML,
                $extension
            );
            $Server = new RoutingTestServer([
                new DocumentationTestProvider(
                    'Yaml',
                    'YAML API',
                    $definitionFile
                )
            ]);

            $Response = $Server->onGetDocsApi(
                new ServerRequest('GET', '/api/docs/Yaml/json'),
                new Response(),
                [
                    'api_name' => 'Yaml',
                    'format' => 'json'
                ]
            );
            $specification = json_decode(
                (string)$Response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame('application/json', $Response->getHeaderLine('Content-Type'));
            self::assertSame('YAML API', $specification['info']['title']);
            self::assertSame([['url' => '/api/']], $specification['servers']);
            self::assertSame(
                'Accept-Language',
                $specification['paths']['/items']['get']['parameters'][0]['name']
            );
        }
    }

    public function testInvalidOpenApiYamlReturnsInternalServerError(): void
    {
        $definitionFile = $this->createRawDefinitionFile(
            'openapi: [unterminated',
            'yaml'
        );
        $Server = new RoutingTestServer([
            new DocumentationTestProvider(
                'Invalid',
                'Invalid API',
                $definitionFile
            )
        ]);
        $Server->registerBasePaths();

        $Response = $Server->getSlim()->handle(
            new ServerRequest('GET', '/api/docs/Invalid/json')
        );

        self::assertSame(500, $Response->getStatusCode());
        self::assertSame('', (string)$Response->getBody());
    }

    public function testPublicationCombinesExplicitEndpointsWithAnEntireProvider(): void
    {
        $firstDefinition = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'info' => ['title' => 'First API', 'version' => '1.0.0'],
            'security' => [['bearer' => []]],
            'paths' => [
                '/validate' => [
                    'post' => [
                        'operationId' => 'validate',
                        'responses' => [
                            200 => [
                                'description' => 'Result',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Result']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                '/private' => [
                    'get' => [
                        'responses' => [200 => ['$ref' => '#/components/responses/Private']]
                    ]
                ]
            ],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => [
                    'Result' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['$ref' => '#/components/schemas/Result'],
                            'valid' => ['type' => 'boolean']
                        ]
                    ]
                ],
                'responses' => ['Private' => ['description' => 'Private']]
            ]
        ]);
        $secondDefinition = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Second API', 'version' => '1.0.0'],
            'paths' => [
                '/lookup' => [
                    'get' => [
                        'operationId' => 'validate',
                        'security' => [],
                        'responses' => [
                            200 => [
                                'description' => 'Result',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Result']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'components' => ['schemas' => ['Result' => ['type' => 'string']]]
        ]);
        $Server = new RoutingTestServer([
            new DocumentationTestProvider('First', 'First API', $firstDefinition),
            new DocumentationTestProvider('Second', 'Second API', $secondDefinition),
            new DocumentationTestProvider('Undocumented', 'Undocumented API', false)
        ]);

        $options = $Server->getOpenApiPublicationOptions();
        self::assertSame(['POST /validate', 'GET /private'], $options['First']['operations']);
        self::assertArrayNotHasKey('Undocumented', $options);

        $selection = OpenApiPublication::parseSelection('{"First":["POST /validate"],"Second":true}');
        $contact = [
            'name' => 'API support',
            'url' => 'https://example.test/contact'
        ];
        $specification = $Server->getPublishedOpenApi($selection, $contact);
        $firstOperation = $specification['paths']['/validate']['post'];
        $secondOperation = $specification['paths']['/lookup']['get'];

        self::assertSame([['url' => 'https://example.test/api/']], $specification['servers']);
        self::assertSame($contact, $specification['info']['contact']);
        self::assertSame(['/validate', '/lookup'], array_keys($specification['paths']));
        self::assertSame(['api1_bearer' => []], (array)$firstOperation['security'][0]);
        self::assertSame([], $secondOperation['security']);
        self::assertNotSame($firstOperation['operationId'], $secondOperation['operationId']);
        self::assertSame(
            '#/components/schemas/api1_Result',
            $specification['components']['schemas']['api1_Result']['properties']['value']['$ref']
        );
        self::assertSame('string', $specification['components']['schemas']['api2_Result']['type']);
        self::assertArrayNotHasKey('responses', $specification['components']);
    }

    public function testPublicationRejectsConflictingPathTemplates(): void
    {
        $firstDefinition = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'paths' => ['/items/{id}' => ['get' => ['responses' => [200 => ['description' => 'Result']]]]]
        ]);
        $secondDefinition = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'paths' => ['/items/{key}' => ['post' => ['responses' => [200 => ['description' => 'Result']]]]]
        ]);
        $Server = new RoutingTestServer([
            new DocumentationTestProvider('First', 'First API', $firstDefinition),
            new DocumentationTestProvider('Second', 'Second API', $secondDefinition)
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting OpenAPI endpoint: POST /items/{key}');
        $Server->getPublishedOpenApi(['First' => true, 'Second' => true]);
    }

    public function testPublicationUsesNoDefaultProvider(): void
    {
        $Server = new RoutingTestServer([]);

        self::assertNull($Server->getPublishedOpenApi(OpenApiPublication::parseSelection('')));
        self::assertNull($Server->getPublishedOpenApi(OpenApiPublication::parseSelection('{}')));
    }

    public function testPublicationUsesProviderContactUntilAContactIsConfigured(): void
    {
        $providerContact = [
            'name' => 'Provider support',
            'email' => 'provider@example.test'
        ];
        $definitionFile = $this->createDefinitionFile([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Documented API',
                'version' => '1.0.0',
                'contact' => $providerContact
            ],
            'paths' => [
                '/items' => [
                    'get' => [
                        'responses' => [200 => ['description' => 'Result']]
                    ]
                ]
            ]
        ]);
        $Server = new RoutingTestServer([
            new DocumentationTestProvider('Documented', 'Documented API', $definitionFile)
        ]);

        $selection = ['Documented' => true];
        $specification = $Server->getPublishedOpenApi($selection);
        self::assertSame($providerContact, $specification['info']['contact']);

        $configuredContact = ['name' => 'Site support'];
        $specification = $Server->getPublishedOpenApi($selection, $configuredContact);
        self::assertSame($configuredContact, $specification['info']['contact']);
    }

    public function testOpenApiContactSettingsOnlyReturnUsableValues(): void
    {
        $Config = new Config();
        self::assertSame([], Settings::getOpenApiContact($Config));

        $Config->setValue('openapi', 'contactName', ' API support ');
        $Config->setValue('openapi', 'contactSite', 'not-a-url');
        $Config->setValue('openapi', 'contactEmail', 'invalid');
        self::assertSame(['name' => 'API support'], Settings::getOpenApiContact($Config));

        $url = 'https://support.example.test/contact?lang=en';
        $Config->setValue('openapi', 'contactSite', $url);
        $Config->setValue('openapi', 'contactEmail', ' support@example.test ');
        self::assertSame(
            [
                'name' => 'API support',
                'url' => $url,
                'email' => 'support@example.test'
            ],
            Settings::getOpenApiContact($Config)
        );
    }

    public function testPublicationUsesTheRewrittenUrlForASelectedContactPage(): void
    {
        $Site = $this->createMock(Site::class);
        $Site->expects(self::once())->method('getUrlRewrittenWithHost')->willReturn('https://example.test/Contact');
        $Project = $this->createMock(Project::class);
        $Project->expects(self::once())->method('get')->with(24)->willReturn($Site);
        $projects = ProjectManager::$projects;
        ProjectManager::$projects['openapi-contact-test'] = ['en' => $Project];

        try {
            $Config = new Config();
            $Config->setValue('openapi', 'contactSite', 'index.php?id=24&project=openapi-contact-test&lang=en');

            self::assertSame(['url' => 'https://example.test/Contact'], Settings::getOpenApiContact($Config));
        } finally {
            ProjectManager::$projects = $projects;
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function createDefinitionFile(array $definition): string
    {
        return $this->createRawDefinitionFile(
            json_encode($definition, JSON_THROW_ON_ERROR)
        );
    }

    private function createRawDefinitionFile(
        string $contents,
        string $extension = ''
    ): string {
        $extension = ltrim($extension, '.');
        $file = tempnam(sys_get_temp_dir(), 'quiqqer-rest-openapi-');

        if ($file === false) {
            throw new RuntimeException('Could not create temporary OpenAPI definition file.');
        }

        if ($extension !== '') {
            $definitionFile = $file . '.' . $extension;

            if (!rename($file, $definitionFile)) {
                unlink($file);
                throw new RuntimeException('Could not add an extension to the OpenAPI definition file.');
            }

            $file = $definitionFile;
        }

        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
