<?php
namespace server\furll_dynadot_domain\controller\home;

use app\common\model\ClientModel;
use app\common\model\HostModel;
use app\common\model\ProductModel;
use app\common\model\ServerModel;
use server\furll_dynadot_domain\dynadot\DynadotClientFactory;
use server\furll_dynadot_domain\model\ServerConfigModel;
use server\furll_dynadot_domain\model\InfoTemplateModel;

/**
 * Dynadot 域名前台接口。
 */
class DomainController
{
    /**
     * 查询单个域名的可用性与价格。
     */
    public function search()
    {
        $param = request()->param();
        $domain = strtolower(trim((string)($param['domain'] ?? '')));
        $currency = strtolower((string)configuration('currency_code'));

        if (!$this->isValidDomain($domain)) {
            return json(['status' => 400, 'msg' => '请输入有效的域名', 'data' => []]);
        }
        if (!in_array($currency, [
            'usd', 'gbp', 'eur', 'inr', 'pln', 'zar', 'ltl', 'cny', 'cad', 'jpy',
            'nzd', 'rub', 'aud', 'mxn', 'brl', 'idr', 'ars', 'cop', 'dkk', 'rsd',
            'hkd', 'chf', 'aed', 'myr', 'ngn', 'kes', 'czk', 'btc', 'nok', 'thb',
            'php', 'krw',
        ], true)) {
            return json(['status' => 400, 'msg' => '不支持的货币类型', 'data' => []]);
        }

        $productId = (int)($param['product_id'] ?? $param['id'] ?? 0);
        $product = $productId > 0 ? ProductModel::find($productId) : null;
        if (empty($product) || $product->getModule() !== 'furll_dynadot_domain') {
            return json(['status' => 400, 'msg' => '商品不存在或模块不匹配', 'data' => []]);
        }

        $server = $this->resolveServer($product);
        if (empty($server)) {
            return json(['status' => 400, 'msg' => '域名接口未配置', 'data' => []]);
        }

        $result = DynadotClientFactory::makeAdapter($server)->searchDomain($domain, $currency);
        if (is_array($result['data'] ?? null)) {
            // 文档中 available 类型描述不一致(Boolean/String)，统一归一化为布尔，前端按布尔渲染
            $result['data']['available'] = $this->normalizeAvailable($result['data']['available'] ?? null);
            // premium 统一归一化为 'yes'/'no'，前端据此展示精品标识并在加购时带上 register_premium
            $result['data']['premium'] = $this->normalizePremium($result['data']['premium'] ?? null);
            // 返回运行模式，前端据此提示沙盒模拟数据
            $config = (new ServerConfigModel())->getByServerId((int)$server['id']);
            $result['data']['mode'] = $config['mode'] ?? 'production';
            $result['data']['server_id'] = (int)$server['id'];
        }
        return json($result);
    }

    /**
     * 查询当前用户某个域名产品的详情（面板主机信息 + Dynadot 注册局信息）。
     *
     * @url /console/v1/host/:id/furll_dynadot_domain/domain_info
     * @method GET
     */
    public function domainInfo()
    {
        $hostId = (int)(request()->param('id') ?? 0);
        $resolved = $this->resolveOwnedDomainHost($hostId);
        if (isset($resolved['error'])) {
            return json($resolved['error']);
        }
        $host   = $resolved['host'];
        $server = $resolved['server'];
        $domain = $resolved['domain'];

        if ((string)$host['status'] === 'Pending') {
            $config = (new ServerConfigModel())->getByServerId((int)$server['id']);
            return json([
                'status' => 200,
                'msg' => lang_plugins('furll_domain_provisioning_notice'),
                'data' => [
                    'host_id' => $hostId,
                    'domain' => $domain,
                    'mode' => $config['mode'] ?? 'production',
                    'host' => [
                        'name' => (string)$host['name'],
                        'server_id' => (int)$host['server_id'],
                        'status' => 'Pending',
                        'active_time' => '',
                        'due_time' => $host['due_time'] ? date('Y-m-d H:i:s', (int)$host['due_time']) : '',
                        'billing_cycle_name' => (string)$host['billing_cycle_name'],
                        'renew_amount' => (string)$host['renew_amount'],
                        'currency' => strtoupper((string)configuration('currency_code')),
                        'product_name' => (string)ProductModel::where('id', (int)$host['product_id'])->value('name'),
                        'notes' => (string)$host['notes'],
                    ],
                    'info' => [],
                    'registrant_contact' => $this->getRegistrantContact($host),
                ],
            ]);
        }

        $result = DynadotClientFactory::makeAdapter($server)->getDomainInfo($domain);
        if (($result['status'] ?? 400) != 200) {
            // 面板前端契约统一 200/400，注册局真实错误码并入 msg
            $msg = (string)($result['msg'] ?? '');
            return json([
                'status' => 400,
                'msg'    => $msg !== '' ? $msg : lang_plugins('furll_domain_info_failed'),
                'data'   => $result['data'] ?? [],
            ]);
        }

        $info = $result['data']['domain_info'] ?? [];

        // 域名服务器列表（去重）
        $nameservers = [];
        foreach (($info['glue_info']['nameserver_list'] ?? []) as $ns) {
            $name = trim((string)($ns['server_name'] ?? ''));
            if ($name !== '') {
                $nameservers[] = $name;
            }
        }
        $nameservers = array_values(array_unique($nameservers));
        // Dynadot DNS 托管（glue_type=DNS）时注册局不返回 nameserver_list，
        // 实际生效的是 Dynadot 自带域名服务器，兜底展示默认 NS
        if (strtoupper((string)($info['glue_info']['glue_type'] ?? '')) === 'DNS' && count($nameservers) === 0) {
            $nameservers = $this->dynadotDnsNameservers();
        }

        $config = (new ServerConfigModel())->getByServerId((int)$server['id']);

        return json([
            'status' => 200,
            'msg'    => lang_plugins('success_message'),
            'data'   => [
                'host_id' => $hostId,
                'domain'  => $domain,
                'mode'    => $config['mode'] ?? 'production',
                'host'    => [
                    'name'              => (string)$host['name'],
                    'server_id'         => (int)$host['server_id'],
                    'status'            => (string)$host['status'],
                    'active_time'       => $host['active_time'] ? date('Y-m-d H:i:s', (int)$host['active_time']) : '',
                    'due_time'          => $host['due_time'] ? date('Y-m-d H:i:s', (int)$host['due_time']) : '',
                    'billing_cycle_name'=> (string)$host['billing_cycle_name'],
                    'renew_amount'      => (string)$host['renew_amount'],
                    'currency'          => strtoupper((string)configuration('currency_code')),
                    'product_name'      => (string)ProductModel::where('id', (int)$host['product_id'])->value('name'),
                    'notes'             => (string)$host['notes'],
                ],
                'info' => [
                    'status'                => (string)($info['status'] ?? ''),
                    'registration_date'     => $this->normalizeTime($info['registration_date'] ?? null),
                    'expiration_date'       => $this->normalizeTime($info['expiration_date'] ?? null),
                    'nameservers'           => $nameservers,
                    'locked'                => $this->normalizeYesNo($info['locked'] ?? null),
                    'disabled'              => $this->normalizeYesNo($info['disabled'] ?? null),
                    'udrp_locked'           => $this->normalizeYesNo($info['udrp_locked'] ?? null),
                    'registrant_unverified' => $this->normalizeYesNo($info['registrant_unverified'] ?? null),
                    'hold'                  => $this->normalizeYesNo($info['hold'] ?? null),
                    'privacy'               => $this->normalizePrivacy($info['privacy'] ?? ''),
                    'renew_option'          => (string)($info['renew_option'] ?? ''),
                    'auto_renew'            => stripos((string)($info['renew_option'] ?? ''), 'auto') !== false,
                    'note'                  => (string)($info['note'] ?? ''),
                    'folder_name'           => (string)($info['folder_name'] ?? ''),
                    'transfer_lock_end_date'=> $this->normalizeTime($info['transfer_lock_end_date'] ?? null),
                    'registrant_contact_id' => isset($info['registrant_contact_id']) ? (int)$info['registrant_contact_id'] : null,
                    'admin_contact_id'      => isset($info['admin_contact_id']) ? (int)$info['admin_contact_id'] : null,
                    'technical_contact_id'  => isset($info['technical_contact_id']) ? (int)$info['technical_contact_id'] : null,
                    'billing_contact_id'    => isset($info['billing_contact_id']) ? (int)$info['billing_contact_id'] : null,
                ],
                'registrant_contact' => $this->getRegistrantContact($host),
            ],
        ]);
    }

    /**
     * 获取当前用户某个域名产品的 nameserver 列表。
     *
     * @url /console/v1/host/:id/furll_dynadot_domain/nameservers
     * @method GET
     */
    public function getNameservers()
    {
        $hostId = (int)(request()->param('id') ?? 0);
        $resolved = $this->resolveOwnedDomainHost($hostId);
        if (isset($resolved['error'])) {
            return json($resolved['error']);
        }

        $result = DynadotClientFactory::makeAdapter($resolved['server'])->getNameservers($resolved['domain']);
        if (($result['status'] ?? 400) != 200) {
            $msg = (string)($result['msg'] ?? '');
            return json([
                'status' => 400,
                'msg'    => $msg !== '' ? $msg : lang_plugins('furll_domain_nameserver_get_failed'),
                'data'   => $result['data'] ?? [],
            ]);
        }

        $data = $result['data'] ?? [];
        $nameservers = [];
        foreach (($data['nameserver_list'] ?? []) as $ns) {
            $name = strtolower(trim((string)(is_array($ns) ? ($ns['server_name'] ?? '') : $ns)));
            if ($name !== '') {
                $nameservers[] = $name;
            }
        }
        $nameservers = array_values(array_unique($nameservers));
        // Dynadot DNS 托管（glue_type=DNS）时注册局不返回 nameserver_list，
        // 实际生效的是 Dynadot 自带域名服务器，兜底展示默认 NS
        if (strtoupper((string)($data['glue_type'] ?? '')) === 'DNS' && count($nameservers) === 0) {
            $nameservers = $this->dynadotDnsNameservers();
        }

        return json([
            'status' => 200,
            'msg'    => lang_plugins('success_message'),
            'data'   => [
                'host_id'         => $hostId,
                'domain'          => $resolved['domain'],
                'glue_type'       => (string)($data['glue_type'] ?? ''),
                'nameserver_list' => $nameservers,
            ],
        ]);
    }

    /**
     * 设置当前用户某个域名产品的 nameserver 列表。
     *
     * @url /console/v1/host/:id/furll_dynadot_domain/nameservers
     * @method PUT
     * @body {"nameserver_list": ["ns1.example.com", ...]}
     */
    public function setNameservers()
    {
        $hostId = (int)(request()->param('id') ?? 0);
        $resolved = $this->resolveOwnedDomainHost($hostId);
        if (isset($resolved['error'])) {
            return json($resolved['error']);
        }

        $nameservers = request()->param('nameserver_list');
        if (!is_array($nameservers) || count($nameservers) === 0) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_nameserver_list_invalid'), 'data' => []]);
        }
        if (count($nameservers) > 13) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_nameserver_exceed_max'), 'data' => []]);
        }

        $list = [];
        foreach ($nameservers as $ns) {
            $name = strtolower(trim((string)$ns));
            if ($name === '' || !$this->isValidNameserver($name)) {
                return json(['status' => 400, 'msg' => lang_plugins('furll_domain_nameserver_invalid'), 'data' => []]);
            }
            $list[] = $name;
        }
        if (count($list) !== count(array_unique($list))) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_nameserver_duplicate'), 'data' => []]);
        }

        $result = DynadotClientFactory::makeAdapter($resolved['server'])->setNameservers($resolved['domain'], $list);
        if (($result['status'] ?? 400) != 200) {
            $msg = (string)($result['msg'] ?? '');
            return json([
                'status' => 400,
                'msg'    => $msg !== '' ? $msg : lang_plugins('furll_domain_nameserver_set_failed'),
                'data'   => $result['data'] ?? [],
            ]);
        }

        return json([
            'status' => 200,
            'msg'    => lang_plugins('furll_domain_nameserver_set_success'),
            'data'   => ['nameserver_list' => $list],
        ]);
    }

    /**
     * 站内推送域名到其他面板账户（可选同步更新 Dynadot 注册联系人 WHOIS）。
     *
     * 校验目标账户（账户ID + 站内邮箱或手机号）；用户勾选修改所属人信息时，
     * 先在 Dynadot 创建新联系人并设为域名的注册联系人，再把面板 host 过户给目标账户。
     *
     * @url /console/v1/host/:id/furll_dynadot_domain/push
     * @method POST
     * @body {
     *   "target_client_id": 2,
     *   "target_account": "test@test.com",
     *   "modify_contact": true,
     *   "registrant_contact": {"name":"","org":"","email":"","phone":"","address1":"","city":"","state":"","zip":"","country":""}
     * }
     */
    public function push()
    {
        $hostId = (int)(request()->param('id') ?? 0);
        $resolved = $this->resolveOwnedDomainHost($hostId);
        if (isset($resolved['error'])) {
            return json($resolved['error']);
        }
        $host   = $resolved['host'];
        $server = $resolved['server'];
        $domain = $resolved['domain'];

        if ((string)$host['status'] !== 'Active') {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_push_status_invalid'), 'data' => []]);
        }

        $post = request()->param();
        $targetClientId = (int)($post['target_client_id'] ?? 0);
        $targetAccount  = strtolower(trim((string)($post['target_account'] ?? '')));
        $modifyContact  = !empty($post['modify_contact']);

        if ($targetClientId <= 0 || $targetAccount === '') {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_push_param_invalid'), 'data' => []]);
        }
        if ($targetClientId === (int)get_client_id()) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_push_target_self'), 'data' => []]);
        }

        $target = ClientModel::where('id', $targetClientId)->where('status', 1)->find();
        if (empty($target)) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_push_target_not_found'), 'data' => []]);
        }
        if (!$this->accountMatches($target, $targetAccount)) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_push_target_mismatch'), 'data' => []]);
        }

        $newContactId = null;
        $contact = [];
        if ($modifyContact) {
            (new InfoTemplateModel())->createTable();
            $templateId = (int)($post['info_template_id'] ?? 0);
            $template = $templateId > 0 ? (new InfoTemplateModel())->owned($templateId, (int)get_client_id(), (int)$server['id']) : null;
            if (!$template || (int)$template['dynadot_contact_id'] <= 0) {
                return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_required'), 'data' => []]);
            }
            $contactData = InfoTemplateModel::decodeContact($template['contact_data']);
            $contact = $this->templateContactForPush($contactData);
            $newContactId = (int)$template['dynadot_contact_id'];

            $adapter = DynadotClientFactory::makeAdapter($server);

            // 保留域名的管理/技术/账单联系人，缺失时兜底复用模板联系人
            $infoRes = $adapter->getDomainInfo($domain);
            $info = ($infoRes['status'] ?? 400) == 200 ? ($infoRes['data']['domain_info'] ?? []) : [];
            $fallback = $newContactId;
            $set = $adapter->setDomainContacts($domain, [
                'registrant_contact_id' => $newContactId,
                'admin_contact_id'      => (int)($info['admin_contact_id'] ?? 0) ?: $fallback,
                'technical_contact_id'  => (int)($info['technical_contact_id'] ?? 0) ?: $fallback,
                'billing_contact_id'    => (int)($info['billing_contact_id'] ?? 0) ?: $fallback,
            ]);
            if (($set['status'] ?? 400) != 200) {
                $msg = (string)($set['msg'] ?? '');
                return json([
                    'status' => 400,
                    'msg'    => $msg !== '' ? $msg : lang_plugins('furll_domain_push_contact_failed'),
                    'data'   => $set['data'] ?? [],
                ]);
            }
        }

        // 面板 host 过户给目标账户
        $update = [
            'client_id'     => $targetClientId,
            'transfer_time' => time(),
        ];
        if ($modifyContact && $newContactId !== null) {
            $config = $this->getHostConfig($host);
            $config['registrant_contact'] = $contact;
            $config['info_template_id'] = (int)($post['info_template_id'] ?? 0);
            $config['info_template_server_id'] = (int)$server['id'];
            $config['registrant_contact_id'] = $newContactId;
            $update['base_config_options'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        HostModel::where('id', $hostId)->update($update);

        active_log(
            lang_plugins('furll_domain_push_log', ['{domain}' => $domain, '{client_id}' => $targetClientId]),
            'host',
            $hostId,
            (int)get_client_id()
        );

        return json([
            'status' => 200,
            'msg'    => lang_plugins('furll_domain_push_success'),
            'data'   => [
                'host_id'          => $hostId,
                'domain'           => $domain,
                'target_client_id' => $targetClientId,
                'target_username'  => (string)$target['username'],
                'target_email'     => (string)$target['email'],
            ],
        ]);
    }

    /**
     * 解析并校验当前登录用户拥有的域名主机（host/server/domain）。
     *
     * @return array ['host'=>HostModel,'server'=>ServerModel,'domain'=>string] 或 ['error'=>响应数组]
     */
    private function resolveOwnedDomainHost(int $hostId): array
    {
        $host = $hostId > 0 ? HostModel::find($hostId) : null;
        if (empty($host) || (int)$host['client_id'] !== (int)get_client_id() || $host['is_delete']) {
            return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_host_not_exist'), 'data' => []]];
        }

        $server = ServerModel::find((int)$host['server_id']);
        if (empty($server) || $server['module'] !== 'furll_dynadot_domain') {
            return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_server_is_not_exist'), 'data' => []]];
        }

        $domain = strtolower(trim((string)($host['name'] ?? '')));
        if (!$this->isValidDomain($domain)) {
            return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_register_config_invalid'), 'data' => []]];
        }

        return ['host' => $host, 'server' => $server, 'domain' => $domain];
    }

    private function isValidNameserver(string $nameserver): bool
    {
        return (bool)preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            $nameserver
        );
    }

    /**
     * Dynadot DNS 托管模式下的默认域名服务器。
     *
     * glue_type=DNS 时 Dynadot 注册局接口不返回 nameserver_list，
     * 域名实际由 Dynadot 自带 NS 解析，这里返回默认列表供前端展示与编辑。
     */
    private function dynadotDnsNameservers(): array
    {
        return [
            'ns1.dynadot.com',
            'ns2.dynadot.com',
            'ns3.dynadot.com',
            'ns4.dynadot.com',
        ];
    }

    /**
     * 读取下单时保存的注册联系人（base_config_options），供前台展示。
     */
    private function getRegistrantContact($host): array
    {
        $raw = $host['base_config_options'] ?? '';
        $config = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($config)) {
            return [];
        }
        $contact = $config['registrant_contact'] ?? [];
        return is_array($contact) ? $contact : [];
    }

    /**
     * 归一化 Dynadot 时间戳（毫秒/秒）为 Y-m-d H:i:s。
     */
    private function normalizeTime($ts): string
    {
        if ($ts === null || $ts === '') {
            return '';
        }
        $ts = (int)$ts;
        if ($ts <= 0) {
            return '';
        }
        if ($ts > 100000000000) {
            $ts = (int)($ts / 1000); // 毫秒转秒
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * 归一化 Yes/No 为布尔。
     */
    private function normalizeYesNo($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'y', 'true'], true);
    }

    /**
     * 归一化隐私保护：full / partial / off。
     */
    private function normalizePrivacy($value): string
    {
        $value = strtolower(trim((string)$value));
        if (strpos($value, 'full') !== false) {
            return 'full';
        }
        if (strpos($value, 'partial') !== false) {
            return 'partial';
        }
        if ($value === '' || in_array($value, ['off', 'no', 'none', 'n'], true)) {
            return 'off';
        }
        return $value;
    }

    private function isValidDomain(string $domain): bool
    {
        return (bool)preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
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

    private function normalizePremium($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0 ? 'yes' : 'no';
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'y', 'premium'], true) ? 'yes' : 'no';
    }

    /**
     * 校验目标账户：账户ID + 站内邮箱（或手机号）必须匹配。
     */
    private function accountMatches($client, string $account): bool
    {
        if ($account === '') {
            return false;
        }
        if (strcasecmp((string)$client['email'], $account) === 0) {
            return true;
        }
        $input = preg_replace('/\D+/', '', $account);
        if ($input === '') {
            return false;
        }
        $phone = preg_replace('/\D+/', '', (string)($client['phone'] ?? ''));
        if ($phone !== '' && $input === $phone) {
            return true;
        }
        $full = preg_replace('/\D+/', '', (string)($client['phone_code'] ?? '') . $phone);
        return $full !== '' && $input === $full;
    }

    /**
     * 校验并规整 push 弹窗提交的注册联系人信息。
     *
     * 返回 ['error'=>响应数组] 或规整后的联系人数组（含 name/first_name/last_name/phone_cc/phone_number）。
     */
    private function templateContactForPush(array $contact): array
    {
        $name = trim((string)($contact['first_name'] ?? '') . ' ' . (string)($contact['last_name'] ?? ''));
        return $this->normalizePushContact([
            'name' => $name,
            'org' => $contact['org'] ?? '',
            'email' => $contact['email'] ?? '',
            'phone' => $contact['phone'] ?? '',
            'address1' => $contact['address1'] ?? '',
            'city' => $contact['city'] ?? '',
            'state' => $contact['state'] ?? '',
            'zip' => $contact['zip'] ?? '',
            'country' => $contact['country'] ?? '',
        ]);
    }

    private function normalizePushContact($raw): array
    {
        $contact = [];
        foreach (['name', 'org', 'email', 'phone', 'address1', 'city', 'state', 'zip', 'country'] as $key) {
            $contact[$key] = trim((string)($raw[$key] ?? ''));
        }
        foreach (['name', 'email', 'phone', 'address1', 'city', 'state', 'zip', 'country'] as $key) {
            if ($contact[$key] === '') {
                return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_push_contact_invalid'), 'data' => []]];
            }
        }
        if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_push_contact_invalid'), 'data' => []]];
        }
        if (!preg_match('/^[A-Za-z]{2}$/', $contact['country'])) {
            return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_push_contact_invalid'), 'data' => []]];
        }
        $contact['country'] = strtoupper($contact['country']);

        // 姓名拆分为 first/last（面板旧字段兼容），phone 拆出国家码
        $nameParts = preg_split('/\s+/', $contact['name'], 2);
        $contact['first_name'] = $nameParts[0] ?? '';
        $contact['last_name']  = $nameParts[1] ?? '';

        $phoneCc = '';
        $phoneNumber = $contact['phone'];
        if (preg_match('/^\+?\s*(\d{1,4})[\s.\-](.+)$/', $contact['phone'], $m)) {
            $phoneCc = $m[1];
            $phoneNumber = trim($m[2]);
        }
        $contact['phone_cc'] = $phoneCc;
        $contact['phone_number'] = $phoneNumber;

        return $contact;
    }

    /**
     * 将规整后的联系人映射为 Dynadot v2 contact 对象字段。
     */
    private function buildDynadotContact(array $contact): array
    {
        $dynadot = [
            'organization'  => $contact['org'] ?? '',
            'name'          => $contact['name'],
            'email'         => $contact['email'],
            'phone_number'  => $contact['phone_number'] ?? $contact['phone'],
            'address1'      => $contact['address1'],
            'city'          => $contact['city'],
            'state'         => $contact['state'],
            'zip'           => $contact['zip'],
            'country'       => $contact['country'],
        ];
        if (!empty($contact['phone_cc'])) {
            $dynadot['phone_cc'] = $contact['phone_cc'];
        }

        return array_filter($dynadot, function ($v) {
            return $v !== '' && $v !== null;
        });
    }

    /**
     * 读取 host 的 base_config_options 配置。
     */
    private function getHostConfig($host): array
    {
        $raw = is_array($host) ? ($host['base_config_options'] ?? '') : ($host->base_config_options ?? '');
        if (is_array($raw)) {
            return $raw;
        }
        $config = json_decode((string)$raw, true);
        return is_array($config) ? $config : [];
    }

    private function resolveServer(ProductModel $product)
    {
        if ($product['type'] === 'server') {
            return ServerModel::where('id', (int)$product['rel_id'])
                ->where('module', 'furll_dynadot_domain')
                ->where('status', 1)
                ->find();
        }

        return ServerModel::where('server_group_id', (int)$product['rel_id'])
            ->where('module', 'furll_dynadot_domain')
            ->where('status', 1)
            ->order('id', 'asc')
            ->find();
    }
}
