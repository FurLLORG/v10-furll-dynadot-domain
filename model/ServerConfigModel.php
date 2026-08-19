<?php
namespace server\furll_dynadot_domain\model;

use think\Model;
use think\facade\Db;

/**
 * @title Dynadot 接口配置模型
 * @use server\furll_dynadot_domain\model\ServerConfigModel
 *
 * 存储每个面板接口（server_id）独立的 Dynadot 连接配置：
 *   - mode      运行模式：production=生产（默认） / sandbox=沙盒
 *   - api_key   API 访问标识（API Production Key）
 *   - api_secret API 密钥（Secret Key，AES 加密存储，读取时解密）
 *
 * 数据表（含系统前缀）：idcsmart_module_furll_dynadot_domain_server
 */
class ServerConfigModel extends Model
{
    protected $name = 'module_furll_dynadot_domain_server';

    protected $schema = [
        'id'          => 'int',
        'server_id'   => 'int',
        'mode'        => 'string',
        'api_key'     => 'string',
        'api_secret'  => 'string',
        'create_time' => 'int',
        'update_time' => 'int',
    ];

    /**
     * 建表 SQL（IF NOT EXISTS，可重复执行）
     */
    private const CREATE_TABLE_SQL = <<<SQL
CREATE TABLE IF NOT EXISTS `idcsmart_module_furll_dynadot_domain_server` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL DEFAULT '0' COMMENT '面板接口ID',
  `mode` varchar(20) NOT NULL DEFAULT 'production' COMMENT '运行模式 production=生产 sandbox=沙盒',
  `api_key` varchar(255) NOT NULL DEFAULT '' COMMENT 'API访问标识',
  `api_secret` text NOT NULL COMMENT 'API密钥(AES加密存储)',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='FurLLORG Dynadot域名模块-接口配置';
SQL;

    /**
     * 创建配置表（模块第一次被使用接口时调用）
     */
    public function createTable()
    {
        Db::execute(self::CREATE_TABLE_SQL);
        return true;
    }

    /**
     * 删除配置表（模块最后一个接口被删除时调用）
     */
    public function dropTable()
    {
        Db::execute('DROP TABLE IF EXISTS `idcsmart_module_furll_dynadot_domain_server`');
        return true;
    }

    /**
     * 获取接口配置（无记录时返回默认值：生产模式）
     *
     * @param  int  $serverId 面板接口ID
     * @return array ['mode'=>string, 'api_key'=>string, 'api_secret'=>string]
     */
    public function getByServerId(int $serverId): array
    {
        $default = [
            'mode'       => 'production',
            'api_key'    => '',
            'api_secret' => '',
        ];
        if ($serverId <= 0) {
            return $default;
        }
        try {
            $config = $this->where('server_id', $serverId)->find();
        } catch (\Throwable $e) {
            // 表尚未创建时按默认配置处理，不阻塞模块调用
            return $default;
        }
        if (empty($config)) {
            return $default;
        }
        return [
            'mode'       => in_array($config['mode'], ['production', 'sandbox']) ? $config['mode'] : 'production',
            'api_key'    => $config['api_key'] ?: '',
            'api_secret' => $config['api_secret'] ? aes_password_decode($config['api_secret']) : '',
        ];
    }

    /**
     * 保存接口配置（api_secret 留空时保留原值）
     *
     * @param  int    $serverId   面板接口ID
     * @param  string $mode       运行模式 production|sandbox
     * @param  string $apiKey     API访问标识
     * @param  string $apiSecret  API密钥（明文传入，内部加密存储）
     * @return array ['status'=>200|400, 'msg'=>string]
     */
    public function saveConfig(int $serverId, string $mode, string $apiKey, string $apiSecret): array
    {
        // 表不存在时自动创建，保证兼容“已有接口但未建表”的历史数据
        $this->createTable();

        $now = time();
        $config = $this->where('server_id', $serverId)->find();
        if (empty($config)) {
            $this->create([
                'server_id'   => $serverId,
                'mode'        => $mode,
                'api_key'     => $apiKey,
                'api_secret'  => $apiSecret === '' ? '' : aes_password_encode($apiSecret),
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } else {
            $update = [
                'mode'        => $mode,
                'api_key'     => $apiKey,
                'update_time' => $now,
            ];
            if ($apiSecret !== '') {
                $update['api_secret'] = aes_password_encode($apiSecret);
            }
            $this->where('server_id', $serverId)->update($update);
        }

        return ['status' => 200, 'msg' => lang_plugins('update_success')];
    }

    /**
     * 删除接口配置（接口被删除时调用）
     */
    public function deleteByServerId(int $serverId)
    {
        if ($serverId > 0) {
            $this->where('server_id', $serverId)->delete();
        }
        return true;
    }
}
