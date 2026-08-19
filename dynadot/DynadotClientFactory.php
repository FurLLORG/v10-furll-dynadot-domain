<?php
namespace server\furll_dynadot_domain\dynadot;

use server\furll_dynadot_domain\model\ServerConfigModel;

/**
 * Dynadot 客户端工厂
 * 根据接口配置（含沙盒开关）创建对应的客户端实例
 */
class DynadotClientFactory
{
    /**
     * 根据接口配置创建客户端
     *
     * 优先读取插件配置表（按 server_id 关联）中的 模式/API Key/Secret，
     * 同时兼容调用方直接传入 sandbox/api_key/api_secret 的写法。
     *
     * @param  mixed $server 接口配置（面板 ServerModel 或数组，含 id）
     * @return DynadotAdapter
     */
    public static function makeAdapter($server): DynadotAdapter
    {
        $config = (new ServerConfigModel())->getByServerId((int)($server['id'] ?? 0));

        $apiKey    = $server['api_key']    ?? $config['api_key'];
        $apiSecret = $server['api_secret'] ?? $config['api_secret'];
        $mode      = $server['mode']       ?? $config['mode'];

        $baseUrl = $mode === 'sandbox'
            ? 'https://api-sandbox.dynadot.com'
            : 'https://api.dynadot.com';

        $client = new DynadotClient(
            $baseUrl,
            $apiKey,
            $apiSecret
        );

        return new DynadotAdapter($client);
    }
}
