<?php

namespace G4T\Swagger\Sections;

use Illuminate\Support\Str;

trait Schemas
{

    public function getSchemas($validations, $name, $method = 'POST')
    {
        if ($method == 'PUT|PATCH' OR $method == 'PUT') {
            return $this->generateSchemaNested($validations);
        } else {
            return $this->generateGenericSchema($validations, $name);
        }
    }


    protected function generateGenericSchema($validations, $name)
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
            $rule_key = $this->getInputName($key);
            if (is_array($validation)) {
                $rules[$rule_key][] = $validation;
            } else {
                $rules[$rule_key][] = explode('|', $validation);
            }

            // get required
            foreach ($rules[$rule_key] as $rule) {
                if (in_array("required", $rule)) {
                    $requireds[] = $this->getInputName($key);
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
                $schemas['properties'][$this->getInputName($key)] = $this->getSwaggerInputSchema($rule);
            }
        }

        return $schemas;

    }
    
    protected function generateSchemaNested($validations)
    {
        $schema = [
            "type" => "object",
            "properties" => [],
        ];
        $requireds = [];
    
        foreach ($validations as $key => $validation) {
            if (strpos($key, '.') !== false) {
                // If the validation key contains a dot, create a nested property
                $this->addNestedProperty($schema["properties"], $key, $validation);
            } else {
                // Otherwise, create a non-nested property
                $rule_key = $this->getInputName($key);
                
                $type = 'object';
                // Determine whether it's the last nested key (no more dots)
                if (substr_count($key, '.') === 0) {
                    $type = 'string';
                }

                if(!is_array($validation)) {
                    $validation = explode('|', $validation);
                }
                
                $schema["properties"][$rule_key] = [
                    'type' => $type,
                    'properties' => $this->getSwaggerInputSchema($validation)
                ];
    
                // Get required
                if ($this->isRequiredRule($validation)) {
                    $requireds[] = $rule_key;
                }
            }
        }
    
        if (!empty($requireds)) {
            $schema["required"] = $requireds;
        }
    
        return $schema;
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
        
        if(!is_array($validation)) {
            $validation = explode('|', $validation);
        }

            $current = $this->getSwaggerInputSchema($validation);

    }
    



    public function handleCustomRule($rule, $schema)
    {
        return ['type' => 'string'];
    }

    public function getSwaggerInputSchema($rules, $type = 'string')
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
        if($type == 'object') {
            $schema['type'] = 'object';
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
