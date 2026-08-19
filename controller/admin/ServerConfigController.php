<?php
namespace server\furll_dynadot_domain\controller\admin;

use app\common\model\ServerModel;
use server\furll_dynadot_domain\model\ServerConfigModel;
use server\furll_dynadot_domain\validate\ServerConfigValidate;

/**
 * @title Dynadot 接口配置
 * @desc Dynadot 域名模块后台接口配置（沙盒/生产模式、API Key、Secret Key）
 * @use server\furll_dynadot_domain\controller\admin\ServerConfigController
 */
class ServerConfigController
{
    /**
     * 时间 2026-01-01
     * @title 获取接口配置
     * @desc 获取接口配置
     * @url /admin/v1/furll_dynadot_domain/config
     * @method GET
     * @author FurLLORG
     * @version v1
     * @param int server_id - desc:接口ID require
     * @return int status - 状态(200=成功,400=失败)
     * @return string msg - 信息
     * @return string data.mode - 运行模式(production=生产,sandbox=沙盒)
     * @return string data.api_key - API访问标识
     * @return bool data.api_secret_configured - 是否已配置 API 密钥（不会返回密钥明文）
     * @return int data.server_id - 接口ID
     * @return string data.server_name - 接口名称
     */
    public function index()
    {
        $param = request()->param();
        $serverId = (int)($param['server_id'] ?? 0);

        $server = $this->checkServer($serverId);
        if (empty($server)) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_server_is_not_exist')]);
        }

        $config = (new ServerConfigModel())->getByServerId($serverId);

        return json([
            'status' => 200,
            'msg'    => lang_plugins('success_message'),
            'data'   => [
                'server_id'   => $serverId,
                'server_name' => $server['name'] ?? '',
                'mode'        => $config['mode'],
                'api_key'                => $config['api_key'],
                'api_secret_configured' => $config['api_secret'] !== '',
            ],
        ]);
    }

    /**
     * 时间 2026-01-01
     * @title 保存接口配置
     * @desc 保存接口配置
     * @url /admin/v1/furll_dynadot_domain/config
     * @method PUT
     * @author FurLLORG
     * @version v1
     * @param int server_id - desc:接口ID require
     * @param string mode - desc:运行模式(production=生产,sandbox=沙盒) require
     * @param string api_key - desc:API访问标识 require
     * @param string api_secret - desc:API密钥(留空保留原值) optional
     * @return int status - 状态(200=成功,400=失败)
     * @return string msg - 信息
     */
    public function save()
    {
        $param = request()->param();

        $validate = new ServerConfigValidate();
        if (!$validate->scene('save')->check($param)) {
            return json(['status' => 400, 'msg' => lang_plugins($validate->getError())]);
        }

        $serverId = (int)$param['server_id'];
        if (empty($this->checkServer($serverId))) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_server_is_not_exist')]);
        }

        $result = (new ServerConfigModel())->saveConfig(
            $serverId,
            (string)$param['mode'],
            (string)$param['api_key'],
            (string)($param['api_secret'] ?? '')
        );

        return json($result);
    }

    /**
     * 校验接口存在且模块为 furll_dynadot_domain
     *
     * @return array|false 接口信息，不存在或模块不符返回 false
     */
    private function checkServer(int $serverId)
    {
        if ($serverId <= 0) {
            return false;
        }
        $server = ServerModel::find($serverId);
        if (empty($server)) {
            return false;
        }
        if (($server['module'] ?? '') !== 'furll_dynadot_domain') {
            return false;
        }
        return $server;
    }
}
