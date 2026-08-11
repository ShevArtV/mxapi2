<?php

namespace MxApi\Tests\Fake;

/**
 * Хранилище строк в памяти вместо таблиц xPDO.
 *
 * Нужно ровно для проверок уборки: репозитории токенов и журнала — код
 * платформы, а не ядра, и без такой заглушки они проверяются только на живом
 * MODX. Поэтому уборка и дожила до прода непокрытой.
 *
 * Заглушка намеренно повторяет поведение настоящего xPDO в одном месте:
 * removeCollection() принимает УСЛОВИЯ, а не объект запроса, и на объекте
 * падает. Тест без этого отличал бы массив от xPDOQuery только по форме вызова.
 */
class FakeStorage
{
    /** @var array className => список строк */
    public $rows = [];

    /** @var array Вызовы removeCollection(): [[className, criteria], ...] */
    public $removeCalls = [];

    /** @var array Записи, ушедшие в modX::log() */
    public $logs = [];

    public function seed($className, array $rows)
    {
        $this->rows[$className] = array_values($rows);
    }

    /**
     * @return array Оставшиеся строки коллекции.
     */
    public function remaining($className)
    {
        return isset($this->rows[$className]) ? array_values($this->rows[$className]) : [];
    }

    /**
     * xPDO::getCount() объект запроса принимает — в этом и была ловушка:
     * подсчёт работал, а удаление тем же аргументом падало.
     */
    public function getCount($className, $criteria = null)
    {
        return count($this->select($className, $this->conditions($criteria)));
    }

    public function removeCollection($className, $criteria)
    {
        if (!is_array($criteria)) {
            // Дословно то, чем падал прод: removeCollection() строит запрос сам
            // и кладёт второй аргумент внутрь where() как ЗНАЧЕНИЕ условия, а
            // при сборке SQL значение приводится к строке.
            throw new \Error('Object of class xPDOQuery_mysql could not be converted to string');
        }

        $this->removeCalls[] = [$className, $criteria];

        $matched = $this->select($className, $criteria);
        if (!$matched) {
            return 0;
        }

        $kept = [];
        foreach ($this->rows[$className] as $index => $row) {
            if (!in_array($index, $matched, true)) {
                $kept[] = $row;
            }
        }
        $this->rows[$className] = $kept;

        return count($matched);
    }

    /**
     * @return array Индексы подходящих строк.
     */
    private function select($className, array $conditions)
    {
        $matched = [];
        if (empty($this->rows[$className])) {
            return $matched;
        }

        foreach ($this->rows[$className] as $index => $row) {
            if ($this->matches($row, $conditions)) {
                $matched[] = $index;
            }
        }

        return $matched;
    }

    private function conditions($criteria)
    {
        if (is_array($criteria)) {
            return $criteria;
        }
        if (is_object($criteria) && isset($criteria->conditions)) {
            return $criteria->conditions;
        }

        return [];
    }

    private function matches(array $row, array $conditions)
    {
        foreach ($conditions as $key => $value) {
            $parts = explode(':', $key);
            $field = $parts[0];
            $operator = isset($parts[1]) ? $parts[1] : '=';
            $actual = isset($row[$field]) ? $row[$field] : null;

            switch ($operator) {
                case '<':
                    $ok = $actual < $value;
                    break;
                case '<=':
                    $ok = $actual <= $value;
                    break;
                case '>':
                    $ok = $actual > $value;
                    break;
                case '>=':
                    $ok = $actual >= $value;
                    break;
                case '!=':
                    $ok = $actual != $value;
                    break;
                default:
                    $ok = $actual == $value;
            }

            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
