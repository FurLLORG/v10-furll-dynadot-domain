<?php
namespace server\furll_dynadot_domain;

use app\common\model\ServerModel;
use server\furll_dynadot_domain\dynadot\DynadotClientFactory;
use server\furll_dynadot_domain\model\ServerConfigModel;
use server\furll_dynadot_domain\model\InfoTemplateModel;

/**
 * 域名注册模块（对接 Dynadot RESTful API v2）
 *
 * 开发者：FurLLORG
 * 依赖：Dynadot API 沙盒/生产环境，需在后台接口配置中填写 API Key / Secret
 */
class FurllDynadotDomain
{
    /**
     * 基础信息
     *
     * @return string display_name - 模块名称
     * @return string version - 版本号
     */
    public function metaData()
    {
        return ['display_name' => 'FurLLORG 域名注册', 'version' => '1.0.0'];
    }

    /**
     * 测试连接
     */
    public function testConnect($param)
    {
        $Dynadot = DynadotClientFactory::makeAdapter($param['server']);
        $res = $Dynadot->testConnect();
        if ($res['status'] == 200) {
            $res['msg'] = lang_plugins('furll_domain_link_success');
        }
        return $res;
    }

    /**
     * 模块开通（结算支付后自动注册域名）
     *
     * 面板流程：下单结算(afterSettle 只保存配置) -> 支付成功 -> host_create 任务 -> createAccount
     * 此处按 Dynadot v2 REGISTER 文档构造请求体：
     *   domain.duration / contacts / name_server_list / privacy 均在 domain 对象内，
     *   currency / register_premium / coupon_code 在顶层。
     */
    public function createAccount($param)
    {
        $host = $param['host'] ?? [];
        $server = $param['server'] ?? [];
        $config = $this->getHostConfig($host);
        $domain = strtolower(trim((string)($config['domain'] ?? $host['name'] ?? '')));
        $duration = (int)($config['duration'] ?? 1);

        if (empty($server) || empty($server['id'])) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_server_is_not_exist')];
        }
        // 硬守卫：未付款（Unpaid）一律不注册。面板支付成功后才把 host 置为 Pending 并排开通任务
        if ((string)($host['status'] ?? '') === 'Unpaid') {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_not_paid')];
        }
        if (!$this->isValidDomain($domain) || $duration < 1) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_register_config_invalid')];
        }
        if ($this->isCnDomain($domain)) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_cn_not_supported')];
        }

        // 已注册成功过则直接返回成功，避免重复扣费注册
        if (!empty($config['registered'])) {
            return [
                'status' => 200,
                'msg' => lang_plugins('furll_domain_register_success'),
                'data' => [
                    'domain_name' => $domain,
                    'expiration_date' => $config['expiration_date'] ?? '',
                ],
            ];
        }

        if (!empty($config['registrant_contact']) && is_array($config['registrant_contact'])) {
            try {
                $this->normalizeRegistrantContact($config['registrant_contact']);
            } catch (\InvalidArgumentException $e) {
                return ['status' => 400, 'msg' => $e->getMessage()];
            }
        }

        $register = $this->buildRegisterPayload($config, $domain, $duration, $this->isSandboxServer($server));
        $adapter = DynadotClientFactory::makeAdapter($server);
        $result = $adapter->registerDomain(
            array_merge(['domain_name' => $domain], $register)
        );
        $status = (int)($result['status'] ?? 400);
        $msg = (string)($result['msg'] ?? '');

        // 409 且提示域名已属于本账户：视为重复开通，幂等处理为成功
        $alreadyOwned = $status === 409 && stripos($msg, 'already owned') !== false;
        if (!in_array($status, [200, 201, 202], true) && !$alreadyOwned) {
            // 面板模块契约只认 200/400，Dynadot 真实错误码（409/429/500...）并入 msg 返回
            return [
                'status' => 400,
                'msg' => $msg !== '' ? $msg : lang_plugins('furll_domain_register_failed'),
                'data' => $result['data'] ?? [],
            ];
        }

        // 保存注册结果，供前台展示与重复开通判断
        $hostId = (int)($host['id'] ?? 0);
        if ($hostId > 0) {
            $config['registered'] = true;
            $config['register_time'] = time();
            if (!empty($result['data']['expiration_date'])) {
                $config['expiration_date'] = $result['data']['expiration_date'];
            }
            \app\common\model\HostModel::where('id', $hostId)->update([
                'base_config_options' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        return [
            'status' => 200,
            'msg' => lang_plugins('furll_domain_register_success'),
            'data' => $result['data'] ?? [],
        ];
    }

    /**
     * 模块暂停
     */
    public function suspendAccount($param)
    {
        return ['status' => 400, 'msg' => lang_plugins('furll_domain_not_implemented')];
    }

    /**
     * 模块解除暂停
     */
    public function unsuspendAccount($param)
    {
        return ['status' => 400, 'msg' => lang_plugins('furll_domain_not_implemented')];
    }

    /**
     * 模块删除（域名销毁）
     */
    public function terminateAccount($param)
    {
        return ['status' => 400, 'msg' => lang_plugins('furll_domain_not_implemented')];
    }

    /**
     * 续费后调用
     */
    public function renew($param)
    {
        return ['status' => 400, 'msg' => lang_plugins('furll_domain_not_implemented')];
    }

    /**
     * 购物车价格计算
     */
    public function cartCalculatePrice($param)
    {
        $product = $param['product'] ?? [];
        $custom = $param['custom'] ?? [];
        $domain = strtolower(trim((string)($custom['domain'] ?? '')));
        $duration = max(1, (int)($custom['duration'] ?? $custom['year'] ?? 1));

        if (!$this->isValidDomain($domain)) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_register_config_invalid')];
        }
        if ($this->isCnDomain($domain)) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_cn_not_supported')];
        }
        if ($duration > 10) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_duration_invalid')];
        }

        $server = $this->resolveProductServer($product);
        if (empty($server)) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_server_is_not_exist')];
        }
        $currency = strtolower((string)configuration('currency_code'));
        $search = DynadotClientFactory::makeAdapter($server)->searchDomain($domain, $currency);
        if (($search['status'] ?? 400) != 200) {
            return $search;
        }
        if (!$this->normalizeAvailable($search['data']['available'] ?? null)) {
            return ['status' => 409, 'msg' => lang_plugins('furll_domain_not_available')];
        }

        $priceRow = $search['data']['price_list'][0] ?? [];
        $registrationPrice = $priceRow['registration_price'] ?? null;
        if (!is_numeric($registrationPrice)) {
            return ['status' => 400, 'msg' => lang_plugins('furll_domain_price_unavailable')];
        }

        // Dynadot returns a one-year registration price. The panel sells the selected term.
        $price = round((float)$registrationPrice * $duration, 2);
        if (($product['pay_type'] ?? '') === 'free') {
            $price = 0;
        }
        $billingCycle = ($product['pay_type'] ?? '') === 'onetime'
            ? lang_plugins('furll_domain_billing_onetime')
            : ($duration . lang_plugins('furll_domain_billing_year_unit'));
        $renewPrice = is_numeric($priceRow['renewal_price'] ?? null)
            ? round((float)$priceRow['renewal_price'] * $duration, 2)
            : $price;
        $privacy = in_array(($custom['privacy'] ?? 'off'), ['off', 'partial', 'full'], true)
            ? $custom['privacy']
            : 'off';
        $privacyLabel = in_array($privacy, ['full', 'partial'], true)
            ? lang_plugins('furll_domain_privacy_on')
            : lang_plugins('furll_domain_privacy_off');

        return [
            'status' => 200,
            'msg' => lang_plugins('success_message'),
            'data' => [
                'price' => $price,
                'renew_price' => $renewPrice,
                'base_price' => $price,
                'base_renew_price' => $renewPrice,
                'billing_cycle' => $billingCycle,
                'duration' => $duration * 31536000,
                'description' => $domain . ' (' . $duration . ' year' . ($duration > 1 ? 's' : '') . ')',
                'discount' => 0,
                'discount_order_price' => $price,
                'preview' => [
                    ['name' => lang_plugins('furll_domain_preview_domain'), 'value' => $domain, 'price' => number_format($price, 2, '.', '')],
                    ['name' => lang_plugins('furll_domain_preview_duration'), 'value' => $duration . lang_plugins('furll_domain_billing_year_unit'), 'price' => '0.00'],
                    ['name' => lang_plugins('furll_domain_preview_privacy'), 'value' => $privacyLabel, 'price' => '0.00'],
                ],
            ],
        ];
    }

    /**
     * 结算后调用，保存下单的配置项
     */
    public function afterSettle($param)
    {
        (new InfoTemplateModel())->createTable();
        $hostId = (int)($param['host_id'] ?? 0);
        $custom = $param['custom'] ?? [];
        $domain = strtolower(trim((string)($custom['domain'] ?? '')));
        $duration = max(1, (int)($custom['duration'] ?? $custom['year'] ?? 1));
        if ($hostId <= 0 || !$this->isValidDomain($domain)) {
            throw new \InvalidArgumentException(lang_plugins('furll_domain_register_config_invalid'));
        }
        if ($this->isCnDomain($domain)) {
            throw new \InvalidArgumentException(lang_plugins('furll_domain_cn_not_supported'));
        }

        $templateId = (int)($custom['info_template_id'] ?? 0);
        $hostRecord = \app\common\model\HostModel::find($hostId);
        $hostServerId = $hostRecord ? (int)$hostRecord['server_id'] : 0;
        $template = $templateId > 0 ? (new InfoTemplateModel())->owned($templateId, (int)($hostRecord['client_id'] ?? get_client_id()), $hostServerId) : null;
        if (!$template || (int)$template['server_id'] !== $hostServerId || (int)$template['dynadot_contact_id'] <= 0) {
            throw new \InvalidArgumentException(lang_plugins('furll_domain_info_template_required'));
        }
        $contact = InfoTemplateModel::decodeContact($template['contact_data']);
        $contact = $this->normalizeRegistrantContact($contact);
        \app\common\model\HostModel::where('id', $hostId)->update([
            'name' => $domain,
            'base_config_options' => json_encode([
                'domain' => $domain,
                'duration' => $duration,
                'privacy' => in_array(($custom['privacy'] ?? 'off'), ['off', 'partial', 'full'], true)
                    ? $custom['privacy']
                    : 'off',
                'currency' => strtolower((string)($custom['currency'] ?? configuration('currency_code') ?: 'usd')),
                'register_premium' => !empty($custom['register_premium']),
                'registrant_contact' => $contact,
                'info_template_id' => $templateId,
                'info_template_server_id' => (int)$template['server_id'],
                'registrant_contact_id' => (int)$template['dynadot_contact_id'],
                'admin_contact_id' => $custom['admin_contact_id'] ?? '',
                'tech_contact_id' => $custom['tech_contact_id'] ?? '',
                'billing_contact_id' => $custom['billing_contact_id'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return ['status' => 200, 'msg' => lang_plugins('success_message')];
    }

    /**
     * 校验并规整购买页提交的注册联系人（字段名与 Dynadot v2 contact 对象对齐）
     */
    private function normalizeRegistrantContact($contact): array
    {
        if (!is_array($contact)) {
            $contact = [];
        }
        $contact = array_map(function ($v) {
            return is_string($v) ? trim($v) : $v;
        }, $contact);

        $required = ['first_name', 'last_name', 'email', 'phone', 'address1', 'city', 'state', 'zip', 'country'];
        foreach ($required as $field) {
            if (empty($contact[$field])) {
                throw new \InvalidArgumentException(lang_plugins('furll_domain_contact_invalid'));
            }
        }
        return $contact;
    }

    /**
     * 构造 Dynadot v2 REGISTER 请求体
     *
     * 文档要求：duration/contacts/name_server_list/privacy 等都在 domain 对象内，
     * 顶层仅 currency / register_premium / coupon_code。
     * 实测：沙盒 REGISTER 不接受 currency 参数（usd/cny 均返回 400），仅生产环境传货币。
     */
    private function buildRegisterPayload(array $config, string $domain, int $duration, bool $isSandbox = false): array
    {
        $privacy = $config['privacy'] ?? 'off';
        $domainBody = [
            'duration' => $duration,
            'privacy' => in_array($privacy, ['off', 'partial', 'full'], true) ? $privacy : 'off',
        ];
        if (!empty($config['auth_code'])) {
            $domainBody['auth_code'] = (string)$config['auth_code'];
        }
        foreach (['registrant_contact_id', 'admin_contact_id', 'tech_contact_id', 'billing_contact_id', 'customer_id'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '' && is_numeric($config[$key])) {
                $domainBody[$key] = (int)$config[$key];
            }
        }
        if (empty($domainBody['registrant_contact_id']) && !empty($config['registrant_contact']) && is_array($config['registrant_contact'])) {
            $domainBody['registrant_contact'] = $this->buildDynadotContact($config['registrant_contact']);
        }
        foreach (['admin_contact', 'tech_contact', 'billing_contact'] as $key) {
            if (!empty($config[$key]) && is_array($config[$key])) {
                $domainBody[$key] = $this->buildDynadotContact($config[$key]);
            }
        }
        if (!empty($config['name_server_list']) && is_array($config['name_server_list'])) {
            $domainBody['name_server_list'] = array_values($config['name_server_list']);
        }

        $register = [
            'domain' => $domainBody,
            'register_premium' => !empty($config['register_premium']),
        ];
        if (!$isSandbox) {
            $register['currency'] = strtolower((string)($config['currency'] ?? configuration('currency_code') ?: 'usd'));
        }
        if (!empty($config['coupon_code'])) {
            $register['coupon_code'] = (string)$config['coupon_code'];
        }
        return $register;
    }

    /**
     * 判断接口是否为沙盒模式
     */
    private function isSandboxServer($server): bool
    {
        $serverId = (int)(is_array($server) ? ($server['id'] ?? 0) : ($server->id ?? 0));
        $config = (new ServerConfigModel())->getByServerId($serverId);
        return ($config['mode'] ?? 'production') === 'sandbox';
    }

    /**
     * 将购物车联系人字段映射为 Dynadot v2 contact 对象字段
     *
     * 购物车: first_name/last_name/org/phone(+cc.number)/email/address1/city/state/zip/country
     * Dynadot: organization/name/phone_number/phone_cc/email/address1/address2/city/state/zip/country
     */
    private function buildDynadotContact(array $contact): array
    {
        $firstName = trim((string)($contact['first_name'] ?? ''));
        $lastName = trim((string)($contact['last_name'] ?? ''));
        $name = trim($firstName . ' ' . $lastName);
        if ($name === '' && !empty($contact['name'])) {
            $name = trim((string)$contact['name']);
        }

        $phone = trim((string)($contact['phone'] ?? ($contact['phone_number'] ?? '')));
        $phoneCc = trim((string)($contact['phone_cc'] ?? ''));
        $phoneNumber = $phone;
        if ($phoneCc === '' && preg_match('/^\+?\s*(\d{1,4})[\s.\-](.+)$/', $phone, $m)) {
            $phoneCc = $m[1];
            $phoneNumber = trim($m[2]);
        }

        $dynadot = [
            'organization' => trim((string)($contact['org'] ?? ($contact['organization'] ?? ''))),
            'name' => $name,
            'email' => trim((string)($contact['email'] ?? '')),
            'phone_number' => $phoneNumber,
            'phone_cc' => $phoneCc,
            'address1' => trim((string)($contact['address1'] ?? '')),
            'address2' => trim((string)($contact['address2'] ?? '')),
            'city' => trim((string)($contact['city'] ?? '')),
            'state' => trim((string)($contact['state'] ?? '')),
            'zip' => trim((string)($contact['zip'] ?? '')),
            'country' => strtoupper(trim((string)($contact['country'] ?? ''))),
        ];
        foreach (['fax_number', 'fax_cc'] as $key) {
            if (isset($contact[$key]) && trim((string)$contact[$key]) !== '') {
                $dynadot[$key] = trim((string)$contact[$key]);
            }
        }
        if (!empty($contact['contact_extension']) && is_array($contact['contact_extension'])) {
            $dynadot['contact_extension'] = $contact['contact_extension'];
        }

        return array_filter($dynadot, function ($v) {
            return $v !== '' && $v !== null;
        });
    }

    private function getHostConfig($host): array
    {
        $raw = is_array($host) ? ($host['base_config_options'] ?? '') : ($host->base_config_options ?? '');
        if (is_array($raw)) {
            return $raw;
        }
        $config = json_decode((string)$raw, true);
        return is_array($config) ? $config : [];
    }

    private function resolveProductServer($product)
    {
        $type = is_array($product) ? ($product['type'] ?? '') : ($product->type ?? '');
        $relId = (int)(is_array($product) ? ($product['rel_id'] ?? 0) : ($product->rel_id ?? 0));
        if ($relId <= 0) {
            return null;
        }
        if ($type === 'server') {
            return ServerModel::where('id', $relId)
                ->where('module', 'furll_dynadot_domain')
                ->where('status', 1)
                ->find();
        }
        return ServerModel::where('server_group_id', $relId)
            ->where('module', 'furll_dynadot_domain')
            ->where('status', 1)
            ->order('id', 'asc')
            ->find();
    }

    private function isCnDomain(string $domain): bool
    {
        return (bool)preg_match('/\.cn$/i', strtolower(trim($domain)));
    }

    private function isValidDomain(string $domain): bool
    {
        return (bool)preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/',
            $domain
        );
    }

    private function normalizeAvailable($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'y', 'available'], true);
    }

    /**
     * 后台接口配置页面输出
     *
     * 根据商品关联的接口（type=server）或接口分组（type=server_group）解析出 server_id，
     * 传入模板供前端读取/保存 Dynadot 连接配置。
     */
    public function serverConfigOption($param)
    {
        $serverId = 0;

        $product = $param['product'] ?? null;
        if (!empty($product)) {
            $type  = $product['type'] ?? '';
            $relId = (int)($product['rel_id'] ?? 0);

            if ($type === 'server') {
                $serverId = $relId;
            } elseif ($type === 'server_group') {
                // 接口分组：取分组内第一个接口作为配置入口
                $server = ServerModel::where('server_group_id', $relId)
                    ->order('id', 'asc')
                    ->find();
                if (!empty($server)) {
                    $serverId = (int)$server['id'];
                }
            }
        }

        return [
            'template' => 'template/admin/server_config.html',
            'vars'     => [
                'server_id' => $serverId,
            ],
        ];
    }

    /**
     * 前台商品购买页面输出。
     */
    public function clientProductConfigOption($param)
    {
        return (new \app\common\logic\ModuleLogic())->moduleDefaultView([
            'module'       => 'furll_dynadot_domain',
            'template_dir' => 'cart',
            'file'         => 'goods.html',
        ]);
    }

    /**
     * 前台产品内页输出（域名管理页）
     */
    public function clientArea()
    {
        return (new \app\common\logic\ModuleLogic())->moduleDefaultView([
            'module'       => 'furll_dynadot_domain',
            'template_dir' => 'clientarea',
            'file'         => 'domain_detail.html',
        ]);
    }

    /**
     * 模块第一次被使用接口时创建配置表
     */
    public function afterCreateFirstServer()
    {
        (new ServerConfigModel())->createTable();
        (new InfoTemplateModel())->createTable();
        return true;
    }

    /**
     * 模块最后一个接口被删除时删除配置表
     */
    public function afterDeleteLastServer()
    {
        (new InfoTemplateModel())->dropTable();
        (new ServerConfigModel())->dropTable();
        return true;
    }
}
