<?php

use think\facade\Route;

# 前台接口（域名商品购买页）
Route::group('console/v1', function () {
    Route::get('product/:id/furll_dynadot_domain/search', "\\server\\furll_dynadot_domain\\controller\\home\\DomainController@search");
    Route::get('host/:id/furll_dynadot_domain/domain_info', "\\server\\furll_dynadot_domain\\controller\\home\\DomainController@domainInfo");
    Route::get('host/:id/furll_dynadot_domain/nameservers', "\\server\\furll_dynadot_domain\\controller\\home\\DomainController@getNameservers");
    Route::put('host/:id/furll_dynadot_domain/nameservers', "\\server\\furll_dynadot_domain\\controller\\home\\DomainController@setNameservers");
    Route::post('host/:id/furll_dynadot_domain/push', "\\server\\furll_dynadot_domain\\controller\\home\\DomainController@push");
    Route::get('furll_dynadot_domain/info_templates', "\\server\\furll_dynadot_domain\\controller\\home\\InfoTemplateController@index");
    Route::get('furll_dynadot_domain/info_templates/:id', "\\server\\furll_dynadot_domain\\controller\\home\\InfoTemplateController@read");
    Route::post('furll_dynadot_domain/info_templates', "\\server\\furll_dynadot_domain\\controller\\home\\InfoTemplateController@save");
    Route::put('furll_dynadot_domain/info_templates/:id', "\\server\\furll_dynadot_domain\\controller\\home\\InfoTemplateController@save");
    Route::delete('furll_dynadot_domain/info_templates/:id', "\\server\\furll_dynadot_domain\\controller\\home\\InfoTemplateController@delete");

})->middleware(\app\http\middleware\Check::class);

# 后台接口
Route::group(DIR_ADMIN . '/v1', function () {

	// 接口配置（沙盒/生产模式、API Production Key、Secret Key）
	Route::get('furll_dynadot_domain/config', "\\server\\furll_dynadot_domain\\controller\\admin\\ServerConfigController@index");
	Route::put('furll_dynadot_domain/config', "\\server\\furll_dynadot_domain\\controller\\admin\\ServerConfigController@save");

})->middleware(\app\http\middleware\ParamFilter::class)
  ->middleware(\app\http\middleware\CheckAdmin::class);
