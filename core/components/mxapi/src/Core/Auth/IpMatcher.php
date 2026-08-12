<?php

namespace MxApi\Core\Auth;

/**
 * Сверка IP со списком разрешённых адресов и подсетей CIDR.
 *
 * Логика перенесена из msOrderTranslator (проверена в бою на связке stage→prod)
 * и обобщена: поддерживаются IPv4 и IPv6.
 *
 * @internal
 */
class IpMatcher
{
    /**
     * @param string $ip
     * @param array $allowed Адреса и/или подсети вида 10.0.0.0/8.
     * @return bool
     */
    public static function matches($ip, array $allowed)
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($allowed as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            if ($item === $ip || self::cidrContains($item, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $cidr
     * @param string $ip
     * @return bool
     */
    private static function cidrContains($cidr, $ip)
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        $parts = explode('/', $cidr, 2);
        $network = $parts[0];
        $mask = $parts[1];
        if (!ctype_digit($mask)) {
            return false;
        }

        $networkBinary = @inet_pton($network);
        $ipBinary = @inet_pton($ip);
        if ($networkBinary === false || $ipBinary === false || strlen($networkBinary) !== strlen($ipBinary)) {
            return false;
        }

        $mask = (int)$mask;
        $maxBits = strlen($ipBinary) * 8;
        if ($mask < 0 || $mask > $maxBits) {
            return false;
        }

        $bytes = (int)floor($mask / 8);
        $bits = $mask % 8;

        if ($bytes > 0 && substr($networkBinary, 0, $bytes) !== substr($ipBinary, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $maskByte = chr((0xff << (8 - $bits)) & 0xff);

        return (ord($networkBinary[$bytes]) & ord($maskByte)) === (ord($ipBinary[$bytes]) & ord($maskByte));
    }
}
