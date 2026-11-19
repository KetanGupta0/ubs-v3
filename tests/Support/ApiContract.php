<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/api/openapi.yaml, as the tests hold the server to it (Step I).
 *
 * Every answer a test receives is validated against the schema the document
 * gives for that operation and status. The tests are stricter than the
 * published document: an object here may carry only the properties it lists,
 * unless it says otherwise, so a field the server adds without documenting it
 * fails a test rather than surprising an app developer.
 */
class ApiContract
{
    protected const BASE = 'https://contract.test/';

    /** @var array<string, mixed>|null */
    protected static ?array $spec = null;

    protected static ?Validator $validator = null;

    /** @return array<string, mixed> */
    public static function spec(): array
    {
        return self::$spec ??= Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    }

    /**
     * Every documented operation, as "METHOD /path" relative to /api/v1.
     *
     * @return array<int, string>
     */
    public static function operations(): array
    {
        $operations = [];

        foreach (self::spec()['paths'] as $path => $item) {
            foreach (array_keys($item) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $operations[] = strtoupper($method).' '.$path;
                }
            }
        }

        sort($operations);

        return $operations;
    }

    /** Assert the answer is one this operation documents, in the shape documented. */
    public static function assertMatches(TestResponse $response, string $method, string $path): void
    {
        $operation = self::spec()['paths'][$path][strtolower($method)] ?? null;
        Assert::assertNotNull($operation, "{$method} {$path} is not in the API document.");

        $status = (string) $response->getStatusCode();
        $documented = $operation['responses'][$status] ?? null;
        Assert::assertNotNull($documented, "{$method} {$path} answered {$status}, which the document does not list. Body: ".substr((string) $response->getContent(), 0, 300));

        $documented = self::resolve($documented);
        $schema = $documented['content']['application/json']['schema'] ?? null;

        if ($schema === null) {
            // A file, or an empty 304: nothing to hold to a JSON shape.
            return;
        }

        $result = self::validator()->validate(
            json_decode((string) $response->getContent(), false),
            self::toObject(self::strict(self::rewriteRefs($schema))),
        );

        if (! $result->isValid()) {
            Assert::fail("{$method} {$path} ({$status}) does not match the document:\n"
                .json_encode((new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                ."\nBody: ".substr((string) $response->getContent(), 0, 1500));
        }

        Assert::assertTrue(true);
    }

    protected static function validator(): Validator
    {
        if (self::$validator) {
            return self::$validator;
        }

        $validator = new Validator;
        $validator->setMaxErrors(5);

        foreach (self::spec()['components']['schemas'] as $name => $schema) {
            $validator->resolver()->registerRaw(
                self::toObject(['$schema' => 'https://json-schema.org/draft/2020-12/schema', ...self::strict(self::rewriteRefs($schema))]),
                self::BASE.'schemas/'.$name,
            );
        }

        return self::$validator = $validator;
    }

    /** A response given by reference to components/responses. */
    protected static function resolve(array $item): array
    {
        if (isset($item['$ref'])) {
            $name = basename($item['$ref']);

            return self::spec()['components']['responses'][$name];
        }

        return $item;
    }

    /** "#/components/schemas/Project" becomes an address the validator was given. */
    protected static function rewriteRefs(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        $out = [];

        foreach ($schema as $key => $value) {
            $out[$key] = $key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')
                ? self::BASE.'schemas/'.substr($value, strlen('#/components/schemas/'))
                : self::rewriteRefs($value);
        }

        return $out;
    }

    /** No property that is not listed, wherever a schema lists properties and says nothing else. */
    protected static function strict(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        $out = [];

        foreach ($schema as $key => $value) {
            $out[$key] = in_array($key, ['properties', 'items', 'oneOf', 'anyOf', 'allOf', 'additionalProperties'], true) || is_int($key) || ($key !== 'required' && is_array($value))
                ? self::strict($value)
                : $value;
        }

        if (isset($out['properties']) && ! array_key_exists('additionalProperties', $out)) {
            $out['additionalProperties'] = false;
        }

        return $out;
    }

    /** JSON Schema wants objects as objects, which a decoded YAML array is not. */
    protected static function toObject(array $schema): object
    {
        return json_decode(json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), false);
    }
}
