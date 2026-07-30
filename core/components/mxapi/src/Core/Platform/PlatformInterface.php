<?php

namespace MxApi\Core\Platform;

/**
 * Граница между ядром mxApi и конкретной версией MODX.
 *
 * Всё, что знает про modX, xPDO, процессоры и политики доступа, живёт в
 * реализации этого интерфейса. Ядро (MxApi\Core\*) не должно упоминать modX
 * ни в одной строке — именно это делает порт на MODX 3 заменой одного класса,
 * а не переписыванием пакета.
 */
interface PlatformInterface
{
    /**
     * Значение системной настройки платформы.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getOption($key, $default = null);

    /**
     * Текущее время платформы (unix timestamp).
     *
     * Единый источник времени: сравнение срока жизни токена и его выдача
     * должны идти по одним часам, иначе токен «протухает» из-за таймзон.
     *
     * @return int
     */
    public function now();

    /**
     * @param string $level debug|info|warning|error
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = []);

    /**
     * @param string $username
     * @return PlatformUser|null
     */
    public function findUserByUsername($username);

    /**
     * @param int $id
     * @return PlatformUser|null
     */
    public function findUserById($id);

    /**
     * Проверка пароля пользователя штатным механизмом платформы.
     *
     * @param PlatformUser $user
     * @param string $password
     * @return bool
     */
    public function verifyPassword(PlatformUser $user, $password);

    /**
     * Делает пользователя текущим на время обработки запроса: под ним будут
     * выполняться процессоры и работать проверки прав.
     *
     * @param PlatformUser $user
     * @return void
     */
    public function setRuntimeUser(PlatformUser $user);

    /**
     * @return string Ключ контекста, в котором платформа работает сейчас.
     */
    public function getContextKey();

    /**
     * Переводит платформу в указанный контекст.
     *
     * Права процессоров проверяются политикой контекста, поэтому эндпоинт,
     * объявивший контекст, обязан и проверяться, и выполняться именно в нём.
     * Реализация отвечает за то, чтобы текущий пользователь запроса остался
     * текущим после переключения.
     *
     * @param string $key
     * @return bool false — контекст не существует или недоступен.
     */
    public function useContext($key);

    /**
     * Проверка права пользователя в namespace mxapi.
     *
     * @param PlatformUser $user
     * @param string $permission
     * @return bool
     */
    public function checkPermission(PlatformUser $user, $permission);

    /**
     * Запуск процессора платформы.
     *
     * @param string $processor
     * @param array $properties
     * @param array $options
     * @return ProcessorResult
     */
    public function runProcessor($processor, array $properties = [], array $options = []);

    /**
     * Вызов события платформы (точка расширения для пакетов).
     *
     * @param string $event
     * @param array $params
     * @return array Собранные результаты обработчиков.
     */
    public function invokeEvent($event, array $params = []);

    /**
     * @param string $key
     * @param array $options
     * @return mixed|null
     */
    public function cacheGet($key, array $options = []);

    /**
     * @param string $key
     * @param mixed $value
     * @param int $lifetime Секунды; 0 — без ограничения.
     * @param array $options
     * @return bool
     */
    public function cacheSet($key, $value, $lifetime = 0, array $options = []);

    /**
     * @return \MxApi\Core\Auth\TokenRepositoryInterface
     */
    public function getTokenRepository();

    /**
     * @return \MxApi\Core\Auth\ClientRepositoryInterface
     */
    public function getClientRepository();

    /**
     * @return \MxApi\Core\Log\LogRepositoryInterface
     */
    public function getLogRepository();
}
