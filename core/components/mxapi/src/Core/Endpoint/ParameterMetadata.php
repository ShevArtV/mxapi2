<?php

namespace MxApi\Core\Endpoint;

use MxApi\Core\Http\ApiException;

/**
 * Описание одного параметра эндпоинта.
 *
 * Одна декларация обслуживает три задачи: валидацию входа, отображение в
 * каталоге CMP и генерацию OpenAPI. Дублировать правила в трёх местах нельзя —
 * они разъедутся.
 */
class ParameterMetadata
{
    const IN_QUERY = 'query';
    const IN_PATH = 'path';
    const IN_BODY = 'body';

    const TYPE_STRING = 'string';
    const TYPE_INTEGER = 'integer';
    const TYPE_NUMBER = 'number';
    const TYPE_BOOLEAN = 'boolean';
    const TYPE_ARRAY = 'array';
    const TYPE_OBJECT = 'object';
    const TYPE_DATE = 'date';

    /** @var array */
    private $spec;

    /**
     * @param array $spec name, in, type, required, default, enum, min, max, description, example
     */
    public function __construct(array $spec)
    {
        $this->spec = array_merge(array(
            'name' => '',
            'in' => self::IN_QUERY,
            'type' => self::TYPE_STRING,
            'required' => false,
            'default' => null,
            'enum' => array(),
            'min' => null,
            'max' => null,
            'description' => '',
            'example' => null,
        ), $spec);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->spec['name'];
    }

    /**
     * @return string
     */
    public function getIn()
    {
        return $this->spec['in'];
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->spec['type'];
    }

    /**
     * @return bool
     */
    public function isRequired()
    {
        return (bool)$this->spec['required'];
    }

    /**
     * @return mixed
     */
    public function getDefault()
    {
        return $this->spec['default'];
    }

    /**
     * Проверяет и приводит значение к объявленному типу.
     *
     * @param array $params Все параметры запроса.
     * @return mixed Значение по умолчанию, если параметр не передан.
     * @throws ApiException
     */
    public function extract(array $params)
    {
        $name = $this->getName();
        $present = array_key_exists($name, $params) && $params[$name] !== '';

        if (!$present) {
            if ($this->isRequired()) {
                throw ApiException::missingParameter($name);
            }

            return $this->getDefault();
        }

        $value = $this->cast($params[$name]);

        $enum = $this->spec['enum'];
        if (!empty($enum) && !in_array($value, $enum, true)) {
            throw ApiException::invalidParameter($name, 'допустимые значения: ' . implode(', ', $enum));
        }

        if ($this->spec['min'] !== null && is_numeric($value) && $value < $this->spec['min']) {
            throw ApiException::invalidParameter($name, 'минимум ' . $this->spec['min']);
        }

        if ($this->spec['max'] !== null && is_numeric($value) && $value > $this->spec['max']) {
            throw ApiException::invalidParameter($name, 'максимум ' . $this->spec['max']);
        }

        return $value;
    }

    /**
     * @return array Представление для CMP и OpenAPI.
     */
    public function toArray()
    {
        return $this->spec;
    }

    /**
     * @param mixed $value
     * @return mixed
     * @throws ApiException
     */
    private function cast($value)
    {
        switch ($this->getType()) {
            case self::TYPE_INTEGER:
                if (!is_numeric($value)) {
                    throw ApiException::invalidParameter($this->getName(), 'ожидается целое число');
                }

                return (int)$value;

            case self::TYPE_NUMBER:
                if (!is_numeric($value)) {
                    throw ApiException::invalidParameter($this->getName(), 'ожидается число');
                }

                return (float)$value;

            case self::TYPE_BOOLEAN:
                if (is_bool($value)) {
                    return $value;
                }
                $normalized = strtolower((string)$value);

                return in_array($normalized, array('1', 'true', 'yes', 'on'), true);

            case self::TYPE_ARRAY:
                if (is_array($value)) {
                    return $value;
                }
                $decoded = json_decode((string)$value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }

                return array_values(array_filter(array_map('trim', explode(',', (string)$value)), function ($item) {
                    return $item !== '';
                }));

            case self::TYPE_OBJECT:
                if (is_array($value)) {
                    return $value;
                }
                $decoded = json_decode((string)$value, true);
                if (!is_array($decoded)) {
                    throw ApiException::invalidParameter($this->getName(), 'ожидается объект');
                }

                return $decoded;

            case self::TYPE_DATE:
                $timestamp = strtotime((string)$value);
                if ($timestamp === false) {
                    throw ApiException::invalidParameter($this->getName(), 'ожидается дата');
                }

                return (string)$value;

            default:
                return is_scalar($value) ? (string)$value : $value;
        }
    }
}
