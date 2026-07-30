<?php

namespace MxApi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Ядро обязано оставаться независимым от MODX.
 *
 * Это не стилистика: тот же каталог src/Core копируется в mxapi3 без правок,
 * и любое обращение к modX/xPDO из ядра сломает порт. Проверяем код, а не
 * комментарии — в них MODX упоминать можно и нужно.
 */
class CoreIsPlatformAgnosticTest extends TestCase
{
    public function testCoreHasNoModxReferences()
    {
        $violations = [];

        foreach ($this->coreFiles() as $file) {
            foreach ($this->codeTokens($file) as $line => $code) {
                if (preg_match('/\b(modX|xPDO[A-Za-z]*|modProcessor[A-Za-z]*|modUser|modNamespace)\b/', $code, $matches)) {
                    $violations[] = basename($file) . ':' . $line . ' → ' . $matches[1];
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "MxApi\\Core обращается к MODX — порт на MODX 3 сломается:\n" . implode("\n", $violations)
        );
    }

    /**
     * @return string[]
     */
    private function coreFiles()
    {
        $directory = new \RecursiveDirectoryIterator(__DIR__ . '/../core/components/mxapi/src/Core');
        $files = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'Каталог ядра не найден');

        return $files;
    }

    /**
     * Код без комментариев и строковых литералов: строка => содержимое.
     *
     * @param string $file
     * @return array
     */
    private function codeTokens($file)
    {
        $lines = [];

        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (!is_array($token)) {
                continue;
            }

            list($id, $text, $line) = $token;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            $lines[$line] = isset($lines[$line]) ? $lines[$line] . ' ' . $text : $text;
        }

        return $lines;
    }
}
