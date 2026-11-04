<?php

namespace DevForge\ApiDev\Http\Controllers;

use DevForge\ApiDev\Credentials\CredentialResolver;
use DevForge\ApiDev\Credentials\ProviderCredentials;
use DevForge\ApiDev\Http\Requests\GenerateIntegrationRequest;
use DevForge\ApiDev\Http\Requests\ImportSpecRequest;
use DevForge\ApiDev\Http\Requests\InspectIntegrationRequest;
use DevForge\ApiDev\Http\Requests\IntegrationDefinitionRequest;
use DevForge\ApiDev\Http\Requests\TestConnectionRequest;
use DevForge\ApiDev\Services\CodeGenerator;
use DevForge\ApiDev\Services\EndpointTester;
use DevForge\ApiDev\Services\IntegrationClient;
use DevForge\ApiDev\Services\IntegrationFileWriter;
use DevForge\ApiDev\Services\SpecImporter;
use DevForge\ApiDev\Services\ValidationRuleBuilder;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IntegrationBuilderController extends Controller
{
    /**
     * Where the prebuilt builder UI lives.
     */
    protected const string DIST_PATH = __DIR__.'/../../../dist';

    /**
     * Content types of the prebuilt UI's files.
     *
     * @var array<string, string>
     */
    protected const array ASSET_TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'svg' => 'image/svg+xml',
    ];

    /**
     * Show the API integration builder.
     *
     * The prebuilt page works in any project. Projects that published their
     * own Inertia page can use it by setting `integration-builder.ui`.
     */
    public function index(CredentialResolver $credentials): Responsable|View
    {
        $props = [
            'providers' => array_map(fn (ProviderCredentials $provider): array => [
                'id' => $provider->id,
                'name' => $provider->name,
                'url' => $provider->get('url'),
                'base_url' => $provider->get('base_url'),
                'auth_url' => $provider->get('auth_url'),
                'is_live' => $provider->isLive,
                'default_base_url_column' => $provider->defaultBaseUrlKey(),
                'credentials' => $provider->availability(),
            ], $credentials->all()),
            'credentialSource' => $credentials->label(),
            'authTypes' => IntegrationClient::AUTH_TYPES,
            'fieldTypes' => array_keys(ValidationRuleBuilder::TYPE_RULES),
            'credentialColumns' => ProviderCredentials::CREDENTIAL_KEYS,
            'urlColumns' => ProviderCredentials::URL_KEYS,
            'canWriteFiles' => (bool) config('integration-builder.allow_file_writes'),
        ];

        if (config('integration-builder.ui') === 'inertia' && class_exists(Inertia::class)) {
            return Inertia::render('integrations/Builder', $props);
        }

        $urls = [];

        foreach (['import', 'inspect', 'preview', 'test-auth', 'test-endpoint', 'generate'] as $name) {
            $urls[$name] = route("integration-builder.{$name}", [], false);
        }

        return view('integration-builder::builder', [
            'assets' => [
                'css' => $this->assetUrl('builder.css'),
                'js' => $this->assetUrl('builder.js'),
            ],
            'boot' => [
                'props' => [...$props, 'defaultFrontend' => 'blade'],
                'csrfToken' => csrf_token(),
                'urls' => $urls,
            ],
        ]);
    }

    /**
     * Serve a file of the prebuilt builder UI.
     */
    public function asset(string $file): BinaryFileResponse
    {
        $path = self::DIST_PATH.'/'.basename($file);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::ASSET_TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Get an asset's URL, versioned so a new release is not served from cache.
     */
    protected function assetUrl(string $file): string
    {
        return route('integration-builder.asset', ['file' => $file, 'v' => (int) @filemtime(self::DIST_PATH.'/'.$file)], false);
    }

    /**
     * Detect endpoints from sample JSON or an OpenAPI document.
     */
    public function import(ImportSpecRequest $request, SpecImporter $importer): JsonResponse
    {
        try {
            $result = $request->input('source') === 'openapi'
                ? $importer->fromOpenApi($request->string('content')->toString())
                : $importer->fromSampleJson(
                    $request->string('content')->toString(),
                    $request->string('method')->toString(),
                    $request->string('path')->toString(),
                    $request->input('name'),
                    $request->input('target'),
                    $request->boolean('mark_required', true),
                );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['content' => $exception->getMessage()]);
        }

        return response()->json($result);
    }

    /**
     * Show which generated files and methods already exist for an integration.
     */
    public function inspect(InspectIntegrationRequest $request, CodeGenerator $generator): JsonResponse
    {
        return response()->json($generator->inspect($request->string('name')->toString()));
    }

    /**
     * Check the provider's authentication settings.
     */
    public function testAuth(TestConnectionRequest $request, EndpointTester $tester): JsonResponse
    {
        return response()->json($tester->testAuthentication($this->client($request)));
    }

    /**
     * Send a live test request to an endpoint.
     */
    public function testEndpoint(TestConnectionRequest $request, EndpointTester $tester): JsonResponse
    {
        return response()->json($tester->testEndpoint(
            $this->client($request),
            (array) $request->validated('endpoint'),
            (array) $request->validated('input', []),
        ));
    }

    /**
     * Preview the code that would be generated.
     */
    public function preview(IntegrationDefinitionRequest $request, CodeGenerator $generator): JsonResponse
    {
        return response()->json([
            'files' => $generator->plan($request->validated(), $request->provider()),
            'can_write' => (bool) config('integration-builder.allow_file_writes'),
        ]);
    }

    /**
     * Write the approved generated files.
     */
    public function generate(GenerateIntegrationRequest $request, CodeGenerator $generator, IntegrationFileWriter $writer): JsonResponse
    {
        abort_unless(
            config('integration-builder.allow_file_writes'),
            403,
            'File generation is disabled in this environment. Set INTEGRATION_BUILDER_WRITES=true to enable it.',
        );

        $results = $writer->write(
            $generator->plan($request->validated(), $request->provider()),
            $request->validated('approved'),
        );

        Log::info('Integration builder generated files', [
            'user_id' => $request->user()?->id,
            'integration' => $request->validated('name'),
            'files' => collect($results)->where('written', true)->pluck('path')->all(),
        ]);

        return response()->json(['results' => $results]);
    }

    /**
     * Create a client for the provider and auth settings in the request.
     */
    protected function client(TestConnectionRequest $request): IntegrationClient
    {
        return new IntegrationClient(
            $request->provider(),
            IntegrationClient::normalizeAuth((array) $request->validated('auth')),
            $request->validated('base_url_column'),
            (int) config('integration-builder.test_timeout'),
        );
    }
}
