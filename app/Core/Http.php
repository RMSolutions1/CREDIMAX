<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cliente HTTP mínimo para integraciones salientes (Mercado Pago).
 * Usa cURL cuando está disponible y cae a stream context en hostings sin ext/curl.
 */
final class Http
{
    public const DEFAULT_TIMEOUT = 20;

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,json:?array,error:?string,duration_ms:int}
     */
    public static function request(
        string $method,
        string $url,
        ?array $json = null,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT
    ): array {
        $method = strtoupper($method);
        $payload = $json === null ? null : json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return self::fail('No se pudo serializar el cuerpo de la petición.');
        }

        if ($payload !== null && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';

        $started = microtime(true);
        $result = function_exists('curl_init')
            ? self::viaCurl($method, $url, $payload, $headers, $timeout)
            : self::viaStream($method, $url, $payload, $headers, $timeout);
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        $result['json'] = null;
        if ($result['body'] !== '') {
            $decoded = json_decode($result['body'], true);
            if (is_array($decoded)) {
                $result['json'] = $decoded;
            }
        }

        return $result;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,json:?array,error:?string}
     */
    private static function viaCurl(string $method, string $url, ?string $payload, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return self::fail('No se pudo inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => self::flatten($headers),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Credimax/' . (defined('CREDIMAX_VERSION') ? CREDIMAX_VERSION : '1.0'),
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'json' => null,
            'error' => $error,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,json:?array,error:?string}
     */
    private static function viaStream(string $method, string $url, ?string $payload, array $headers, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", self::flatten($headers)),
                'content' => $payload ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'json' => null,
            'error' => $body === false ? 'Fallo la petición HTTP (stream).' : null,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private static function flatten(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[] = $name . ': ' . $value;
        }
        return $out;
    }

    /** @return array{status:int,body:string,json:?array,error:string,duration_ms:int} */
    private static function fail(string $message): array
    {
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => $message, 'duration_ms' => 0];
    }
}
