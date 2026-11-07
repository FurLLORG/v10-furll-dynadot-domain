# v10-furll-dynadot-domain

ZJMF-CBAP的server模块插件，对接Dynadot RESTful API v2，提供域名搜索、注册、续费、资料查看与部分管理能力。

## 已支持

- 沙盒与生产环境配置、连接测试
- 购物车域名搜索、可用性判断与注册价格
- 注册、续费、域名详情、注册局信息、Nameserver查询与更新
- 联系人模板增删改查
- 简中、繁中、英文语言包

## 未支持

`suspendAccount`、`unsuspendAccount`、`terminateAccount`会返回未实现。

## 安装

把目录完整复制到：

```
public/plugins/server/furll_dynadot_domain/
```

在后台重新扫描或安装server模块，创建或编辑使用`furll_dynadot_domain`模块的接口。

## 配置

1. 在后台创建Dynadot接口或接口分组。
2. 选择本模块并打开配置页。
3. 选择沙盒或生产环境。
4. 填写API Key与Secret，测试连接后保存。

Secret留空保存会保留原值。

## 说明

- 仅接受ASCII域名，拒绝`.cn`
- 注册价格按一年价乘以年限计算，未覆盖阶梯价、Premium、隐私费与税费
- 一次只处理一个域名，不支持批量
- Dynadot API当前为Beta，端点与字段可能变化

## 数据表

`idcsmart_module_furll_dynadot_domain_server`保存接口配置与加密后的Secret；`idcsmart_module_furll_dynadot_domain_info_template`保存联系人模板。

## License

MIT，见`LICENSE`。
