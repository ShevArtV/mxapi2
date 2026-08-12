<?php

namespace MxApi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Паритет ядра между линиями mxapi2 и mxapi3.
 *
 * Каталог src/Core в обеих линиях обязан быть побайтово одинаковым: платформа
 * различается только адаптерами в src/Platform. Договорённость эта до сих пор
 * держалась на внимательности — правку вносили в одну линию, во вторую
 * переносили руками, и расхождение обнаруживалось бы у интегратора, который
 * пишет провайдера один раз на обе версии MODX.
 *
 * Манифест хэшей лежит в обеих линиях одним и тем же файлом, поэтому забытый
 * перенос роняет тест в той линии, куда правку не донесли.
 *
 * Обновлять — только вместе с переносом правки во вторую линию:
 *   MXAPI_UPDATE_CONTRACT=1 vendor/bin/phpunit --filter CoreParityTest
 * и скопировать обновлённый tests/fixtures/core-manifest.txt в соседнюю линию.
 */
class CoreParityTest extends TestCase
{
    const FIXTURE = __DIR__ . '/fixtures/core-manifest.txt';

    const CORE_DIR = __DIR__ . '/../core/components/mxapi/src/Core';

    public function testCoreMatchesManifest()
    {
        $manifest = $this->buildManifest();

        if (getenv('MXAPI_UPDATE_CONTRACT')) {
            if (!is_dir(dirname(self::FIXTURE))) {
                mkdir(dirname(self::FIXTURE), 0775, true);
            }
            file_put_contents(self::FIXTURE, $manifest);
        }

        $this->assertFileExists(self::FIXTURE, 'Манифест ядра не создан: MXAPI_UPDATE_CONTRACT=1 vendor/bin/phpunit');

        $expected = $this->parse(file_get_contents(self::FIXTURE));
        $actual = $this->parse($manifest);

        $this->assertSame(
            array_keys($expected),
            array_keys($actual),
            'Состав файлов ядра разошёлся с манифестом: перенесите правку во вторую линию и обновите манифест.'
        );

        $changed = [];
        foreach ($expected as $file => $hash) {
            if ($actual[$file] !== $hash) {
                $changed[] = $file;
            }
        }

        $this->assertSame(
            [],
            $changed,
            "Файлы ядра отличаются от манифеста — либо правка не перенесена во вторую линию,"
                . " либо манифест не обновлён:\n" . implode("\n", $changed)
        );
    }

    /**
     * @return string
     */
    private function buildManifest()
    {
        $root = realpath(self::CORE_DIR);
        $directory = new \RecursiveDirectoryIterator($root);
        $lines = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Путь только относительный и через прямой слэш: манифест ездит
            // между линиями и между Linux и Windows.
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname()));
            $lines[$relative] = $relative . "\t" . sha1_file($file->getPathname());
        }

        $this->assertNotEmpty($lines, 'Каталог ядра не найден');
        ksort($lines);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string $manifest
     * @return array Путь => хэш.
     */
    private function parse($manifest)
    {
        $result = [];

        foreach (explode("\n", trim((string)$manifest)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode("\t", $line);
            $result[$parts[0]] = isset($parts[1]) ? $parts[1] : '';
        }

        return $result;
    }
}
