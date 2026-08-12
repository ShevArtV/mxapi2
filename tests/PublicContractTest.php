<?php

namespace MxApi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Граница публичного контракта.
 *
 * Провайдер — сторонний код: он наследует AbstractEndpoint, реализует
 * EndpointInterface и зовёт Response, Request, Config. Любая правка их
 * сигнатур ломает установленные пакеты молча — при обновлении mxApi, а не при
 * сборке. Поэтому контракт зафиксирован слепком: правка сигнатуры @api-класса
 * обязана быть осознанной, с обновлением фикстуры, версии и changelog.
 *
 * Что считать контрактом, решает тег в docblock класса: @api — обещание
 * стороннему коду, @internal — внутренняя механика, переписывается свободно.
 * Класс без тега — упущение, а не «внутренний по умолчанию»: тест на этом
 * падает.
 *
 * Обновить слепок после согласованной правки:
 *   MXAPI_UPDATE_CONTRACT=1 vendor/bin/phpunit --filter PublicContractTest
 * и посмотреть diff фикстуры глазами — он и есть список того, что вы обещали
 * поменять у интеграторов.
 */
class PublicContractTest extends TestCase
{
    const FIXTURE = __DIR__ . '/fixtures/public-contract.txt';

    const CORE_DIR = __DIR__ . '/../core/components/mxapi/src/Core';

    public function testEveryCoreClassDeclaresItsBoundary()
    {
        $untagged = [];

        foreach ($this->coreClasses() as $class => $file) {
            $doc = (new \ReflectionClass($class))->getDocComment();
            $api = $doc !== false && strpos($doc, '@api') !== false;
            $internal = $doc !== false && strpos($doc, '@internal') !== false;

            if ($api === $internal) {
                $untagged[] = $class . ' (' . basename($file) . ')';
            }
        }

        $this->assertSame(
            [],
            $untagged,
            "Класс ядра обязан объявить, публичный он или внутренний (@api либо @internal):\n"
                . implode("\n", $untagged)
        );
    }

    public function testPublicContractMatchesFixture()
    {
        $snapshot = $this->buildSnapshot();

        if (getenv('MXAPI_UPDATE_CONTRACT')) {
            if (!is_dir(dirname(self::FIXTURE))) {
                mkdir(dirname(self::FIXTURE), 0775, true);
            }
            file_put_contents(self::FIXTURE, $snapshot);
        }

        $this->assertFileExists(self::FIXTURE, 'Слепок контракта не создан: MXAPI_UPDATE_CONTRACT=1 vendor/bin/phpunit');
        $this->assertSame(
            file_get_contents(self::FIXTURE),
            $snapshot,
            'Публичный контракт изменился. Если это осознанно — обновите слепок'
                . ' (MXAPI_UPDATE_CONTRACT=1), changelog и версию пакета; если нет — верните сигнатуру.'
        );
    }

    /**
     * Слепок: классы с @api, их константы и все методы, доступные наследнику.
     *
     * protected тоже часть контракта: readParams(), readCursor() и describe()
     * — то, ради чего AbstractEndpoint наследуют.
     *
     * @return string
     */
    private function buildSnapshot()
    {
        $lines = [];

        foreach ($this->coreClasses() as $class => $file) {
            $reflection = new \ReflectionClass($class);
            $doc = $reflection->getDocComment();
            if ($doc === false || strpos($doc, '@api') === false) {
                continue;
            }

            $lines[] = $reflection->isInterface() ? 'interface ' . $class : 'class ' . $class;

            foreach ($reflection->getConstants() as $name => $value) {
                $lines[] = '  const ' . $name . ' = ' . $this->describeValue($value);
            }

            $methods = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
                // Унаследованное описано у своего класса — здесь оно только
                // задваивало бы слепок.
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $methods[] = '  ' . $this->describeMethod($method);
            }

            sort($methods);
            $lines = array_merge($lines, $methods);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param \ReflectionMethod $method
     * @return string
     */
    private function describeMethod(\ReflectionMethod $method)
    {
        $modifiers = [$method->isPublic() ? 'public' : 'protected'];
        if ($method->isStatic()) {
            $modifiers[] = 'static';
        }
        if ($method->isAbstract()) {
            $modifiers[] = 'abstract';
        }

        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[] = $this->describeParameter($parameter);
        }

        return implode(' ', $modifiers) . ' ' . $method->getName() . '(' . implode(', ', $parameters) . ')';
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string
     */
    private function describeParameter(\ReflectionParameter $parameter)
    {
        // Приведение к строке само даёт «?Тип» для допускающих null — добавлять
        // знак вопроса отдельно значит получить его дважды.
        $type = $parameter->getType();
        $signature = ($type === null ? '' : (string)$type . ' ') . '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $signature .= ' = ' . $this->describeValue($parameter->getDefaultValue());
        }

        return $signature;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function describeValue($value)
    {
        if (is_array($value)) {
            return $value === [] ? '[]' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return "'" . $value . "'";
        }

        return (string)$value;
    }

    /**
     * Классы ядра: полное имя => файл.
     *
     * @return array
     */
    private function coreClasses()
    {
        $directory = new \RecursiveDirectoryIterator(realpath(self::CORE_DIR));
        $classes = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(realpath(self::CORE_DIR) . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $class = 'MxApi\\Core\\' . str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

            $this->assertTrue(class_exists($class) || interface_exists($class), 'Класс не загружается: ' . $class);
            $classes[$class] = $file->getPathname();
        }

        ksort($classes);
        $this->assertNotEmpty($classes, 'Каталог ядра не найден');

        return $classes;
    }
}
