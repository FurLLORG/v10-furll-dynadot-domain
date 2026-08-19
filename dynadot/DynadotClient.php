<?php
namespace server\furll_dynadot_domain\dynadot;

/**
 * Dynadot RESTful API v2 客户端
 *
 * 文档：docs/dynadot-api.md（离线） / https://www.dynadot.com/zh/domain/api-document?api-version=2.0.0
 *
 * Base URL（由沙盒/生产决定）：
 *   - Production: https://api.dynadot.com
 *   - Sandbox:    https://api-sandbox.dynadot.com
 *
 * 认证：
 *   - Authorization: Bearer {api_key}
 *   - X-Request-ID: UUID（可选）
 *   - X-Signature: HMAC-SHA256(api_secret) over
 *       apiKey + "\n" + fullPathAndQuery + "\n" + (xRequestId or "") + "\n" + (requestBody or "")
 *     输出标准 Base64。
 */
class DynadotClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $apiSecret;

    public function __construct(string $baseUrl, string $apiKey, string $apiSecret)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * 生成 HMAC-SHA256 签名（标准 Base64）
     */
    public function createSignature(string $fullPathAndQuery, string $xRequestId = '', string $requestBody = ''): string
    {
        $stringToSign = $this->apiKey . "\n"
            . $fullPathAndQuery . "\n"
            . ($xRequestId ?: '') . "\n"
            . ($requestBody ?: '');

        $digest = hash_hmac('sha256', $stringToSign, $this->apiSecret, true);

        return base64_encode($digest);
    }

    /**
     * 发送 GET 请求
     */
    public function get(string $pathAndQuery, string $xRequestId = ''): array
    {
        return $this->request('GET', $pathAndQuery, '', $xRequestId);
    }

    /**
     * 发送 POST 请求
     */
    public function post(string $pathAndQuery, array $body = [], string $xRequestId = ''): array
    {
        return $this->request('POST', $pathAndQuery, json_encode($body), $xRequestId);
    }

    /**
     * 发送 PUT 请求
     */
    public function put(string $pathAndQuery, array $body = [], string $xRequestId = ''): array
    {
        return $this->request('PUT', $pathAndQuery, json_encode($body), $xRequestId);
    }

    /**
     * 发送 DELETE 请求（可携带 JSON 请求体，如 remove_dns 需按记录匹配删除）
     */
    public function delete(string $pathAndQuery, array $body = [], string $xRequestId = ''): array
    {
        return $this->request('DELETE', $pathAndQuery, $body ? json_encode($body) : '', $xRequestId);
    }

    /**
     * 核心请求方法
     *
     * @return array ['status'=>int(HTTP), 'msg'=>string, 'data'=>mixed]
     */
    private function request(string $method, string $pathAndQuery, string $requestBody, string $xRequestId): array
    {
        if ($xRequestId === '') {
            $xRequestId = $this->uuid4();
        }

        $signature = $this->createSignature($pathAndQuery, $xRequestId, $requestBody);

        $url = $this->baseUrl . '/' . ltrim($pathAndQuery, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => array_filter([
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'X-Request-ID: ' . $xRequestId,
                'X-Signature: ' . $signature,
                $requestBody === '' ? null : 'Content-Length: ' . strlen($requestBody),
            ]),
            CURLOPT_POSTFIELDS => $requestBody === '' ? null : $requestBody,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 400, 'msg' => 'CURL_ERROR: ' . $error, 'data' => []];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['status' => $httpCode, 'msg' => $response, 'data' => []];
        }

        // 兼容小写(实际返回)与大写(旧文档)两种信封键名
        $status = $data['code'] ?? $data['Code'] ?? $httpCode;
        // 优先取 error.description（如 "Invalid value for parameter 'currency'..."），
        // 比通用 message（如 "Bad Request"）更能定位问题
        $msg = '';
        if (isset($data['error']['description']) && $data['error']['description'] !== '') {
            $msg = $data['error']['description'];
        } elseif (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
            $msg = $data['error'];
        } else {
            $msg = $data['message'] ?? $data['Message'] ?? '';
        }

        return [
            'status' => $status,
            'msg'    => $msg,
            'data'   => $data['data'] ?? $data['Data'] ?? [],
        ];
    }

    /**
     * 生成 UUID v4
     */
    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
