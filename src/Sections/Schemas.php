<?php

namespace G4T\Swagger\Sections;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait Schemas
{
    public function getSchemas(array $validations, string $name, string $method = 'POST'): array
    {
        if ($method === 'PUT|PATCH' || $method === 'PUT') {
            $schema = $this->generateSchemaNested($validations, $name, $method);
        } else {
            $schema = $this->generateGenericSchema($validations, $name, $method);
        }

        return $schema;
    }


    public function defaultSchemaFormat(string $name): array
    {
        return [
            "required" => [],
            "type" => "object",
            "properties" => [],
            "xml" => [
                "name" => Str::lower($name)
            ]
        ];
    }


    protected function generateGenericSchema(array $validations, string $name, string $method): array
    {
        $schemas = $this->defaultSchemaFormat($name);
        if (is_null($validations)) {
            return $schemas;
        }
        [$validation_content, $required] = $this->generateGenericRequiredAndRules($validations, $method);
        $schemas["required"] = $required;
        foreach ($validation_content as $column_name => $validation_value) {
            if (!str_contains($column_name, "[merge_input]")) {
                $name = $this->getInputName($column_name);
                if (str_contains($validation_value, "array")) {
                    $schemas['properties'][$name . "[]"] = $this->getSwaggerInputSchema($validation_value);
                } else {
                    $schemas['properties'][$name] = $this->getSwaggerInputSchema($validation_value);
                }
            }
        }
        return $schemas;
    }


    protected function generateSchemaNested($validations, $name, $method)
    {
        $schemas = $this->defaultSchemaFormat($name);
        if (is_null($validations)) {
            return $schemas;
        }

        [$validation_content, $required] = $this->generateGenericRequiredAndRules($validations, $method);
        $schemas["required"] = $required;
        foreach ($validation_content as $column_name => $validation_value) {
            if (strpos($column_name, '.') !== false && !str_contains($column_name, ".*")) {
                $this->addNestedProperty($schemas["properties"], $column_name, $validation_value);
            } else {
                if (!str_contains($column_name, "[merge_input]")) {
                    $name = $this->getInputName($column_name);
                    if (str_contains($validation_value, "array")) {
                        $schemas['properties'][$name . "[]"] = $this->getSwaggerInputSchema($validation_value);
                    } else {
                        $schemas['properties'][$name] = $this->getSwaggerInputSchema($validation_value);
                    }
                }
            }
        }
        return $schemas;
    }


    public function convertValidationToOneLine(array $validation): string
    {
        $formated_validation = '';
        $count = count($validation);
        foreach ($validation as $key => $format_validation) {
            if (is_string($format_validation)) {
                if ($count - 1 == $key) {
                    $formated_validation .= $format_validation;
                } else {
                    $formated_validation .= $format_validation . "|";
                }
            }
        }
        return $formated_validation;
    }

    public function generateGenericRequiredAndRules(array $validations, string $method): array
    {
        $rules = [];
        $required = [];

        foreach ($validations as $key => $validation) {
            $rule_key = $key;
            if (is_array($validation)) {
                $rules[$rule_key] = $this->convertValidationToOneLine($validation);
            } else {
                if (str_contains($validation, 'image') || str_contains($validation, 'file') || str_contains($validation, 'mimes')) {
                    $rule_key = $this->getInputName($key);
                }
                $rules[$rule_key] = $validation;
            }
            if (str_contains($rule_key, "[merge_input]")) {
                $new_key_for_merge = str_replace('[merge_input]', '', $rule_key);
                foreach ($validations as $merge_key => $merge_validation) {
                    $merge_name = $this->getInputName($merge_key);
                    if ($merge_name == $new_key_for_merge) {
                        $response = implode('|', array_unique(array_filter(explode('|', $merge_validation . '|' . $validation))));
                        $rules[$merge_name] = $response;
                    }
                }
            }


            if (str_contains($rules[$rule_key], "required")) {
                if (str_contains($rules[$rule_key], "array")) {
                    $required[] = $rule_key . "[]";
                } else {
                    if (!str_contains($rule_key, "merge_input")) {
                        $required[] = $rule_key;
                    }
                }
            }
   
        }
        $string_rules = json_encode($rules);
        if ($method == 'PUT' && (str_contains($string_rules, 'image') || str_contains($string_rules, 'file') || str_contains($string_rules, 'mimes'))) {
            $method_rule = ["_method" => "required|in:PUT"];
            $rules = array_merge($rules, $method_rule);
        }
        return [$rules, $required];
    }




    public function getValidationDefaultType($key_name)
    {
        $type = 'object';
        if (substr_count($key_name, '.') === 0) {
            $type = 'string';
        }
        return $type;
    }


    public function convertValidationsFromArrayToString($validation)
    {
        $formated_validation = '';
        if (is_array($validation)) {
            $count = count($validation);
            foreach ($validation as $key => $format_validation) {
                if ($count - 1 == $key) {
                    $formated_validation .= $format_validation;
                } else {
                    $formated_validation .= $format_validation . "|";
                }
            }
        } else {
            $formated_validation = $validation;
        }
        return $formated_validation;
    }


    protected function addNestedProperty(&$properties, $key, $validation)
    {
        $keys = explode('.', $key);
        $current = &$properties;

        foreach ($keys as $index => $nestedKey) {
            if (!isset($current[$nestedKey])) {
                $type = 'object';

                if ($index === count($keys) - 1) {
                    $type = 'string';
                }

                $current[$nestedKey] = [
                    'type' => $type,
                    'properties' => []
                ];
            }

            $current = &$current[$nestedKey]['properties'];
        }

        $current = $this->getSwaggerInputSchema($validation);
    }

    public function setColumnAttributes(string $validation_value, string $condition, string $key, string $value): array
    {
        $schema = [];
        if (str_contains($validation_value, $condition)) {
            $schema[$key] = $value;
        }
        return $schema;
    }

    public function setCustomColumnAttributes(string $validation_value, string $condition, string $key): array
    {
        $schema = [];
        preg_match('/' . $condition . '\:([^|]+)/', $validation_value, $matches);
        if (isset($matches[1])) {
            $schema[$key] = $matches[1];
        }
        return $schema;
    }

    public function setEnumColumnAttributes(string $validation_value, string $condition, string $key): array
    {
        $schema = [];
        if (str_contains($validation_value, "min")) {
            return $schema;
        }
        $data = $this->setCustomColumnAttributes($validation_value, $condition, $key);
        if (isset($data[$key])) {
            $schema[$key] = explode(',', $data[$key]);
        }
        return $schema;
    }


    public function setExistsColumnAttributes(string $validation_value, string $condition, string $key): array
    {
        $schema = [];
        $data = $this->setCustomColumnAttributes($validation_value, $condition, $key);
        if (isset($data[$key])) {
            [$table, $column] = explode(',', $data[$key]);
            $schema[$key] = $this->getExistsData($table, $column);
        }
        return $schema;
    }

    public function getExistsData(string $table, string $column): array
    {
        try {
            return DB::table($table)->pluck($column)->toArray();
        } catch (\Throwable $th) {
            return [
                "Error: Check Database connection"
            ];
        }
    }

    public function setArrayOfInputsAttributes()
    {
        $schema['type'] = 'array';
        $schema['items'] = [
            "type" => "string",
            "format" => "binary"
        ];
        return $schema;
    }


    public function getSwaggerInputSchema(string $validation_value, $type = 'string')
    {
        $schema = [];
        $image_format = [];
        $file_format = [];
        $mimes_format = [];
        $array_of_inputs = [];
        $nullable = [];
        $required = $this->setColumnAttributes($validation_value, 'required', 'required', 'true');
        if (!$required) {
            $nullable = $this->setColumnAttributes($validation_value, 'nullable', 'nullable', 'true');
        }
        $string = $this->setColumnAttributes($validation_value, 'string', 'type', 'string');
        $integer = $this->setColumnAttributes($validation_value, 'integer', 'type', 'number');
        $numeric = $this->setColumnAttributes($validation_value, 'numeric', 'type', 'number');
        $uuid_type = $this->setColumnAttributes($validation_value, 'uuid', 'type', 'string');
        $uuid_format = $this->setColumnAttributes($validation_value, 'uuid', 'format', 'uuid');
        $boolean = $this->setColumnAttributes($validation_value, 'boolean', 'type', 'boolean');
        $date_type = $this->setColumnAttributes($validation_value, 'date', 'type', 'string');
        $date_format = $this->setColumnAttributes($validation_value, 'date', 'format', 'date');
        $array = $this->setColumnAttributes($validation_value, 'array', 'type', 'array');
        $email = $this->setColumnAttributes($validation_value, 'email', 'format', 'email');
        $image_type = $this->setColumnAttributes($validation_value, 'image', 'type', 'string');
        $minimum = $this->setCustomColumnAttributes($validation_value, 'min', 'minimum');
        $maximum = $this->setCustomColumnAttributes($validation_value, 'max', 'maximum');
        $type = $this->setColumnAttributes($validation_value, 'type', 'type', 'string');
        $file_type = $this->setColumnAttributes($validation_value, 'file', 'type', 'string');
        $mimes_type = $this->setColumnAttributes($validation_value, 'mimes', 'type', 'string');
        $mimes_description = $this->setCustomColumnAttributes($validation_value, 'mimes', 'description');
        $unique = $this->setColumnAttributes($validation_value, 'unique', 'unique', 'true');
        $enum = $this->setEnumColumnAttributes($validation_value, 'in', 'enum');
        $exists = $this->setExistsColumnAttributes($validation_value, 'exists', 'enum');
        if ($array && ($file_type || $mimes_type || $image_type)) {
            $array_of_inputs = $this->setArrayOfInputsAttributes();
        } else {
            $image_format = $this->setColumnAttributes($validation_value, 'image', 'format', 'binary');
            $file_format = $this->setColumnAttributes($validation_value, 'file', 'format', 'binary');
            $mimes_format = $this->setColumnAttributes($validation_value, 'mimes', 'format', 'binary');
        }
        $schema = array_merge(
            $required,
            $nullable,
            $string,
            $integer,
            $numeric,
            $uuid_type,
            $uuid_format,
            $boolean,
            $date_type,
            $date_format,
            $array,
            $email,
            $image_type,
            $image_format,
            $minimum,
            $maximum,
            $type,
            $file_type,
            $file_format,
            $mimes_type,
            $mimes_format,
            $mimes_description,
            $unique,
            $enum,
            $exists,
            $array_of_inputs,
            $schema,
        );
        if(!isset($schema['type'])) {
            $schema['type'] = 'string';
        }
        return $schema;
    }



    public function isRequiredRule($rule)
    {
        $required = false;
        if (str_contains($rule, "required")) {
            $required = true;
        }
        return $required;
    }
}
