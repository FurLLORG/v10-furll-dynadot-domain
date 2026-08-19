<?php
namespace server\furll_dynadot_domain\validate;

use think\Validate;

/**
 * @title Dynadot 接口配置验证器
 * @use server\furll_dynadot_domain\validate\ServerConfigValidate
 */
class ServerConfigValidate extends Validate
{
    protected $rule = [
        'server_id'  => 'require|number|gt:0',
        'mode'       => 'require|in:production,sandbox',
        'api_key'    => 'require|max:255',
        'api_secret' => 'max:255',
    ];

    protected $message = [
        'server_id.require'  => 'furll_domain_server_id_require',
        'server_id.number'   => 'param_error',
        'server_id.gt'       => 'param_error',
        'mode.require'       => 'furll_domain_mode_require',
        'mode.in'            => 'param_error',
        'api_key.require'    => 'furll_domain_api_key_require',
        'api_key.max'        => 'param_error',
        'api_secret.max'     => 'param_error',
    ];

    protected $scene = [
        'save' => ['server_id', 'mode', 'api_key', 'api_secret'],
    ];
}
