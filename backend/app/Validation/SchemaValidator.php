<?php

declare(strict_types=1);

namespace App\Validation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class SchemaValidator
{
    public function present(array $schema): object
    {
        return $this->schemaObject($schema);
    }

    public function assertSchema(array $schema, string $label): void
    {
        if (($schema['type'] ?? null) !== 'object') {
            throw new RuntimeException($label . '顶层 type 必须是 object');
        }
        try {
            (new Validator())->validate((object) [], $this->schemaObject($schema));
        } catch (\Throwable $exception) {
            throw new RuntimeException($label . '不是有效 JSON Schema：' . $exception->getMessage());
        }
    }

    public function assertMatches(mixed $data, array $schema): void
    {
        $result = (new Validator())->validate($this->dataForSchema($data, $schema), $this->schemaObject($schema));
        if ($result->isValid()) {
            return;
        }
        $errors = (new ErrorFormatter())->format($result->error(), false);
        throw new RuntimeException('Schema 校验失败：' . json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function schemaObject(array $schema): object
    {
        $object = new \stdClass();
        foreach ($schema as $keyword => $value) {
            if (in_array($keyword, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true) && is_array($value)) {
                $map = new \stdClass();
                foreach ($value as $name => $child) {
                    $map->{$name} = is_array($child) ? $this->schemaObject($child) : $child;
                }
                $object->{$keyword} = $map;
            } elseif (in_array($keyword, ['items', 'contains', 'not', 'if', 'then', 'else', 'propertyNames'], true) && is_array($value)) {
                $object->{$keyword} = $this->schemaObject($value);
            } elseif (in_array($keyword, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true) && is_array($value)) {
                $object->{$keyword} = array_map(fn (mixed $child): mixed => is_array($child) ? $this->schemaObject($child) : $child, $value);
            } elseif (in_array($keyword, ['additionalProperties', 'unevaluatedProperties'], true) && is_array($value)) {
                $object->{$keyword} = $this->schemaObject($value);
            } else {
                $object->{$keyword} = $value;
            }
        }
        return $object;
    }

    private function dataForSchema(mixed $data, array $schema): mixed
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object' && is_array($data)) {
            $object = new \stdClass();
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($data as $key => $value) {
                $childSchema = is_array($properties[$key] ?? null) ? $properties[$key] : [];
                $object->{$key} = $this->dataForSchema($value, $childSchema);
            }
            return $object;
        }
        if ($type === 'array' && is_array($data)) {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
            return array_map(fn (mixed $value): mixed => $this->dataForSchema($value, $items), $data);
        }
        if (is_array($data) && ! array_is_list($data)) {
            return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        }
        return $data;
    }
}
