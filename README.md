# FurLLORG Dynadot Domain Module

ZJMF-CBAP（魔方业务系统）的 `server` 模块插件，对接 Dynadot RESTful API v2，提供域名搜索、注册、域名资料查看和部分域名管理能力。

- 开发者：FurLLORG
- 版本：1.0.0
- 许可证：MIT
- 适用系统：ZJMF-CBAP 业务系统（需支持 server 模块插件）
- 仓库：`v10-furll-dynadot-domain`

> Dynadot API 当前为 Beta。生产启用前请以[官方 API 文档](https://www.dynadot.com/zh/domain/api-document?api-version=2.0.0)为准，并先在沙盒完成验证。

## 功能范围

### 已提供

- Dynadot 沙盒/生产环境配置。
- API 连接测试（Bearer API Key、HMAC-SHA256 `X-Signature`）。
- 购物车域名搜索、可用性判断和注册价格展示。
- 结算后保存域名、年限、隐私和注册联系人配置。
- 支付成功后的域名注册任务，包含已注册成功和“已属于当前账户”场景的幂等处理。
- 前台域名详情、注册局信息、Nameserver 查询/更新和域名 Push。
- 联系人信息模板的新增、读取、修改和删除，并按接口与客户隔离。
- PC 购物车、移动端购物车、PC 会员中心和后台配置页面。
- 中文简体、中文繁体和英文语言包。

### 当前未支持

以下 ZJMF-CBAP 生命周期入口会明确返回“未实现”，不会伪装成成功：

- 暂停域名（`suspendAccount`）
- 解除暂停（`unsuspendAccount`）
- 删除/销毁域名（`terminateAccount`）
- 续费（`renew`）

请勿把以上操作配置为自动化生产流程。实现前需要结合 Dynadot 当前端点、异步订单状态和 ZJMF-CBAP 任务契约补充测试。

## 安装教程

### 1. 准备条件

1. 已安装并运行 ZJMF-CBAP 业务系统。
2. PHP、cURL、OpenSSL 和数据库扩展由宿主面板提供。
3. 拥有 Dynadot API Key 与 API Secret。建议先创建沙盒密钥。
4. 服务器能够访问以下地址：
   - 生产：`https://api.dynadot.com`
   - 沙盒：`https://api-sandbox.dynadot.com`

### 2. 安装插件

将本目录完整复制到 ZJMF-CBAP 的插件目录：

```text
public/plugins/server/furll_dynadot_domain/
```

确认入口文件存在：

```text
public/plugins/server/furll_dynadot_domain/FurllDynadotDomain.php
```

然后在管理员后台重新扫描/安装 server 模块，并创建或编辑一个使用 `furll_dynadot_domain` 模块的接口。首次创建接口时，插件会创建配置表和联系人模板表。

> 从已经安装过旧版本升级时，请覆盖插件文件后在后台重新安装/刷新模块，使新增路由、模板和语言文件生效。不要删除数据库表。

### 3. 配置接口

1. 在后台创建 Dynadot 接口或接口分组。
2. 为接口选择本模块，并打开接口配置页。
3. 开发阶段选择“沙盒”，生产业务选择“生产”。
4. 填写 API Key 和 Secret，点击“测试连接”。
5. 测试通过后保存配置。

API Secret 只在保存时提交，不会由后台读取接口返回。配置页显示“已配置”时，Secret 留空保存会保留数据库中的原值；需要更换密钥时直接输入新值。

### 4. 创建域名商品

在 ZJMF-CBAP 商品中关联 Dynadot 接口或接口分组，并启用本模块的购物车配置。用户需要在购物车选择域名、注册年限、隐私选项和已保存的联系人模板。

## 沙盒注意事项

- 沙盒注册接口实测不接受 `currency` 参数，插件在沙盒注册时会自动跳过该参数。
- 沙盒搜索价格统一按 USD 返回，`currency` 参数不一定生效。
- 沙盒搜索的 `available` 是模拟数据，不代表生产域名真实可注册状态。
- 不要用沙盒结果判断真实扣款、续费或转移结果。

## 支持范围与定价限制

- 当前域名校验接受 ASCII 域名格式，并明确拒绝 `.cn` 域名。
- 注册价格按 Dynadot 返回的一年注册价乘以年限计算，未覆盖阶梯价、Premium、隐私附加费、税费等复杂定价。
- Dynadot 返回 `202 Accepted` 时，插件会按已接受处理保存当前结果；生产环境应结合订单查询或 Webhook 做最终状态闭环。
- Dynadot API 为 Beta，端点、字段和错误信息可能变化。
- 插件一次只处理一个域名，不提供批量注册、批量更新或批量删除。

## 数据表

插件首次使用时创建：

- `idcsmart_module_furll_dynadot_domain_server`：按 ZJMF-CBAP 接口保存运行模式、API Key 和 AES 加密后的 API Secret。
- `idcsmart_module_furll_dynadot_domain_info_template`：保存按接口/客户隔离的联系人信息模板。

删除模块最后一个接口时，生命周期钩子会删除上述表。卸载前请先备份并确认不再需要这些数据。

## 目录说明

```text
FurllDynadotDomain.php       模块入口和 ZJMF-CBAP 生命周期方法
route.php                    前台/后台路由
hooks.php                    删除接口、删除商品等面板钩子
dynadot/                     Dynadot HTTP 客户端、适配器和工厂
controller/                  前台域名接口、联系人模板接口、后台配置接口
model/                       配置和联系人模板模型
validate/                    后台配置校验
lang/                        PHP 后端语言包
template/                    后台、购物车、会员中心模板和前端语言包
```

## 安全说明

- 严禁把 API Key、API Secret、JWT、数据库密码、站点 `config.php` 或运行时缓存提交到仓库。
- API Secret 在数据库中 AES 加密保存，后台读取接口只返回是否已配置，不返回密钥明文。
- 本插件不再根据任意请求 `Origin` 开启带凭据跨域；请通过 ZJMF-CBAP 同源页面或由站点统一配置可信跨域策略。
- 联系人资料包含姓名、邮箱、电话和地址等个人信息，请按当地法律和站点隐私政策处理。
- 交易类 Dynadot 请求必须使用 HTTPS、请求 ID 和签名；不要在日志中输出完整请求头、签名或联系人资料。

## 测试与排障

推荐使用项目自带 PHP 7.3 CLI（需包含 `pdo_mysql`、`curl` 和 `openssl`）执行语法检查：

```bash
find . -name '*.php' -print0 | xargs -0 -n1 /xp/server/php/php-7.3/bin/php -l
```

常见问题：

- **连接测试失败**：检查模式、API Key/Secret、服务器网络和 Dynadot 沙盒是否已激活。
- **订单一直开通中**：确认 ZJMF-CBAP `cron/task.php` 任务消费者由 supervisor 或 cron 常驻运行。
- **价格不可用**：检查域名是否可用、接口配置是否正确，以及 Dynadot API 返回的价格字段。
- **Secret 被清空**：后台保存时 Secret 留空代表保留原值；只有提交新 Secret 才会替换。
- **接口返回格式错误**：模块方法统一使用 `status=200` 或 `status=400`，真实上游错误会放在 `msg` 中。

## 升级和卸载

升级时覆盖插件目录并重新安装/刷新模块；升级前备份数据库和插件文件。卸载前停止相关开通任务、确认没有待处理订单，并按 ZJMF-CBAP 模块生命周期清理配置。删除插件目录不会自动恢复已经注册的域名，也不会撤销 Dynadot 账户中的交易。

## 开源协议

本项目以 MIT License 发布，详见仓库根目录 [`LICENSE`](./LICENSE)。
