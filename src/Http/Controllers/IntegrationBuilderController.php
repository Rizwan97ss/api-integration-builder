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
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class IntegrationBuilderController extends Controller
{
    /**
     * Show the API integration builder.
     */
    public function index(CredentialResolver $credentials): Response
    {
        return Inertia::render('integrations/Builder', [
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
        ]);
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
