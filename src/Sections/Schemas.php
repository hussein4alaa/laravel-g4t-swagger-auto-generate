<?php

namespace G4T\Swagger\Sections;

use Illuminate\Support\Str;

trait Schemas
{

    public function getSchemas($validations, $name)
    {
        $rules = [];
        $requireds = [];
        $schemas = [
            "required" => [],
            "type" => "object",
            "propertes" => $validations,
            "xml" => [
                "name" => Str::lower($name)
            ]
        ];

        if (is_null($validations)) {
            return $schemas;
        }

        foreach ($validations as $key => $validation) {
            if (is_array($validation)) {
                $rules[$key][] = $validation;
            } else {
                $rules[$key][] = explode('|', $validation);
            }

            // get required
            foreach ($rules[$key] as $rule) {
                if (in_array("required", $rule)) {
                    $requireds[] = $key;
                }
            }
        }

        $schemas = [
            "required" => $requireds,
            "type" => "object",
            "propertes" => [],
            "xml" => [
                "name" => Str::lower($name)
            ]
        ];

        foreach ($rules as $key => $rule_list) {
            foreach ($rule_list as $rule) {
                $schemas['properties'][$key] = $this->getSwaggerInputSchema($rule);
            }
        }

        return $schemas;
    }


    public function handleCustomRule($rule, $schema)
    {
        return ['type' => 'string'];
    }

    public function getSwaggerInputSchema($rules)
    {

        $schema = [];

        try {
            foreach ($rules as $rule) {
                $colonIndex = strpos($rule, ':');
                if ($colonIndex !== false) {
                    $name = substr($rule, 0, $colonIndex);
                    $parameters = substr($rule, $colonIndex + 1);
    
                    switch ($name) {
                        case 'string':
                            $schema['type'] = 'string';
                            $this->handleMinMax($parameters, $schema);
                            break;
                        case 'numeric':
                            $schema['type'] = 'number';
                            $this->handleMinMax($parameters, $schema);
                            break;
                        case 'in':
                            $schema['enum'] = explode(',', $parameters);
                            break;
                        case 'exists':
                            $parameters = explode(',', $parameters);
                            $schema['exists'] = "must be exists in '$parameters[0]'" ;
                            break;
                        case 'unique':
                            $schema['unique'] = true;
                            break;
                        case 'digits':
                            $schema['type'] = 'integer';
                            $schema['minimum'] = pow(10, $parameters - 1);
                            $schema['maximum'] = pow(10, $parameters) - 1;
                            break;
                    }
                }
    
                switch ($rule) {
                    case 'required':
                        $schema['required'] = true;
                        break;
                    case 'nullable':
                        $schema['nullable'] = true;
                        break;
                    case 'string':
                        $schema['type'] = 'string';
                        break;
                    case 'integer':
                        $schema['type'] = 'integer';
                        break;
                    case 'numeric':
                        $schema['type'] = 'number';
                        break;
                    case 'uuid':
                        $schema['type'] = 'string';
                        $schema['format'] = 'uuid';
                        break;
                    case 'boolean':
                        $schema['type'] = 'boolean';
                        break;
                    case 'date':
                        $schema['type'] = 'string';
                        $schema['format'] = 'date';
                        break;
                    case 'array':
                        $schema['type'] = 'array';
                        break;
                    case preg_match('/^min:(\d+)$/', $rule, $matches) ? true : false:
                        $schema['minimum'] = (int) $matches[1];
                        break;
                    case preg_match('/^max:(\d+)$/', $rule, $matches) ? true : false:
                        $schema['maximum'] = (int) $matches[1];
                        break;
                    case 'email':
                        $schema['format'] = 'email';
                        break;
                    case 'file':
                    case 'image':
                        $schema['type'] = 'string';
                        $schema['format'] = 'binary';
                        break;
                }
                
                if(!array_key_exists('type', $schema)) {
                    $schema['type'] = 'string';
                }
                
            }
        } catch (\Throwable $th) {
            $schema['type'] = 'string';
        }

        return $schema;
    }

    
    public function handleMinMax($parameters, &$schema)
    {
        if (strpos($parameters, 'max') !== false) {
            $max = substr($parameters, strpos($parameters, 'max:') + 4);
            if (is_numeric($max)) {
                $schema['maxLength'] = $max;
            }
        }
        if (strpos($parameters, 'min') !== false) {
            $min = substr($parameters, strpos($parameters, 'min:') + 4);
            if (is_numeric($min)) {
                $schema['minLength'] = $min;
            }
        }
    }


    public function isRequiredRule($rule)
    {
        return in_array("required", $rule);
    }

}
