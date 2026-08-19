<?php

use app\common\model\HostModel;
use server\furll_dynadot_domain\model\ServerConfigModel;
use server\furll_dynadot_domain\model\InfoTemplateModel;

// 商品删除后
add_hook('after_product_delete', function ($param) {
	if (!isset($param['module']) || $param['module'] != 'furll_dynadot_domain') {
		return false;
	}
	return true;
});

// 接口删除后，清理该接口的 Dynadot 连接配置
add_hook('after_server_delete', function ($param) {
	$serverId = (int)($param['id'] ?? 0);
	if ($serverId > 0) {
            (new ServerConfigModel())->deleteByServerId($serverId);
            (new InfoTemplateModel())->deleteByServerId($serverId);

	}
	return true;
});
