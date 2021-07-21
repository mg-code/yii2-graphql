<?php

namespace mgcode\graphql;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use mgcode\helpers\ArrayHelper;
use yii\base\BaseObject;
use yii\web\ForbiddenHttpException;

abstract class GraphQLType extends BaseObject
{
    /** @var bool */
    public $inputObject = false;

    /**
     * @return string Name must be unique across all system.
     */
    abstract public function name(): string;

    /**
     * @phpstan-return array<string, Type|class-string|array{type: Type, defaultValue?: mixed}>
     * @return array
     */
    abstract public function fields(): array;

    public function description(): ?string
    {
        return null;
    }

    /**
     * Convert instance to an array.
     * @return array
     */
    public function toArray(): array
    {
        $attributes = [
            'name' => $this->name(),
            'fields' => function () {
                return $this->getFields();
            }
        ];
        if (($description = $this->description()) !== null) {
            $attributes['description'] = $description;
        }
        return $attributes;
    }

    protected function getFields(): array
    {
        $authorizeField = null;
        if (method_exists($this, 'authorizeField')) {
            $authorizeField = [$this, 'authorizeField'];
        }

        $fields = $this->fields();
        $allFields = [];
        foreach ($fields as $name => $field) {
            if (is_string($field)) {
                /** @var GraphQLField $field */
                $field = new $field;
                $field->name = $name;
                $field = $field->toArray();
            } else {
                if ($field instanceof Type) {
                    $field = [
                        'type' => $field
                    ];
                }
                $field['resolve'] = $this->getFieldResolver($name, $field);
            }

            // Check if columns is visible
            if ($authorizeField !== null) {
                $resolver = $field['resolve'];
                $field['resolve'] = function () use ($name, $authorizeField, $resolver) {
                    $arguments = func_get_args();
                    $authorizeArgs = array_merge([$name], $arguments);
                    if (call_user_func_array($authorizeField, $authorizeArgs) !== true) {
                        throw new ForbiddenHttpException('You are not allowed to perform this action.');
                    }
                    return call_user_func_array($resolver, $arguments);
                };
            }

            $allFields[$name] = $field;
        }
        return $allFields;
    }

    protected function getFieldResolver($name, $field)
    {
        if (isset($field['resolve'])) {
            return $field['resolve'];
        }
        $resolveMethod = 'resolve'.ucfirst($name);
        if (method_exists($this, $resolveMethod)) {
            $resolver = [$this, $resolveMethod];
            return function () use ($resolver) {
                $args = func_get_args();
                return call_user_func_array($resolver, $args);
            };
        }
        $camelCaseResolverMethod = $this->getCamelCaseResolverName($name);
        if (method_exists($this, $camelCaseResolverMethod)) {
            $resolver = [$this, $camelCaseResolverMethod];
            return function () use ($resolver) {
                $args = func_get_args();
                return call_user_func_array($resolver, $args);
            };
        }

        return function () use ($name) {
            $arguments = func_get_args();
            $root = $arguments[0];
            return ArrayHelper::getValue($root, $name);
        };
    }

    /**
     * @return ObjectType
     */
    public static function type(): Type
    {
        static $type;
        if ($type === null) {
            $object = new static();
            $config = $object->toArray();
            if ($object->inputObject) {
                $type = new InputObjectType($config);
            } else {
                $type = new ObjectType($config);
            }
        }
        return $type;
    }

    private function getCamelCaseResolverName($name): string
    {
        $parts = explode('_', $name);
        $converted = array_map('ucfirst', $parts);
        $result = implode('', $converted);
        return "resolve{$result}Field";
    }
}
