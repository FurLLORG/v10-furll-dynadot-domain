<?php
namespace server\furll_dynadot_domain\controller\home;

use app\common\model\ServerModel;
use app\common\model\HostModel;
use app\common\model\ProductModel;
use server\furll_dynadot_domain\dynadot\DynadotClientFactory;
use server\furll_dynadot_domain\model\InfoTemplateModel;

class InfoTemplateController
{
    public function index()
    {
        (new InfoTemplateModel())->createTable();
        $clientId = (int)get_client_id();
        $serverId = (int)(request()->param('server_id') ?? 0);
        if ($serverId > 0 && !$this->validServer($serverId)) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_server_invalid'), 'data' => []]);
        }
        $list = [];
        foreach ((new InfoTemplateModel())->forClient($clientId, $serverId) as $item) {
            $list[] = $this->format($item);
        }
        return json(['status' => 200, 'msg' => '', 'data' => ['list' => $list]]);
    }

    public function read()
    {
        $item = $this->findOwned((int)request()->param('id'));
        if (!$item) return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_not_found'), 'data' => []]);
        return json(['status' => 200, 'msg' => '', 'data' => $this->format($item)]);
    }

    public function save()
    {
        (new InfoTemplateModel())->createTable();
        $post = request()->param();
        $clientId = (int)get_client_id();
        $serverId = (int)($post['server_id'] ?? 0);
        if (!$this->validServer($serverId)) {
            return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_server_invalid'), 'data' => []]);
        }
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_name_required'), 'data' => []]);
        $contact = $this->normalizeContact($post['contact'] ?? $post['registrant_contact'] ?? []);
        if (isset($contact['error'])) return json($contact['error']);

        $model = new InfoTemplateModel();
        $hash = InfoTemplateModel::contactHash($contact);
        $id = (int)($post['id'] ?? 0);
        $item = $id > 0 ? $model->owned($id, $clientId) : null;
        if ($id > 0 && (!$item || (int)$item['server_id'] !== $serverId)) return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_not_found'), 'data' => []]);
        $duplicate = $model->where('client_id', $clientId)->where('server_id', $serverId)
            ->where('name', $name)->where('id', '<>', $id)->find();
        if ($duplicate) return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_name_exists'), 'data' => []]);

        $same = $model->findByContactHash($clientId, $serverId, $hash);
        $contactId = $same ? (int)$same['dynadot_contact_id'] : 0;
        if ($contactId <= 0) {
            try {
                $dynadotContact = $this->toDynadotContact($contact);
            } catch (\InvalidArgumentException $e) {
                return json(['status' => 400, 'msg' => $e->getMessage(), 'data' => []]);
            }
            $created = DynadotClientFactory::makeAdapter(ServerModel::find($serverId))->createContact($dynadotContact);
            if (!in_array((int)($created['status'] ?? 400), [200, 201, 202], true) || empty($created['data']['contact_id'])) {
                return json(['status' => 400, 'msg' => (string)($created['msg'] ?? lang_plugins('furll_domain_info_template_contact_failed')), 'data' => $created['data'] ?? []]);
            }
            $contactId = (int)$created['data']['contact_id'];
        }

        $now = time();
        $data = [
            'client_id' => $clientId,
            'server_id' => $serverId,
            'name' => $name,
            'contact_data' => json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'contact_hash' => $hash,
            'dynadot_contact_id' => $contactId,
            'status' => 1,
            'update_time' => $now,
        ];
        if ($item) {
            $model->where('id', (int)$item['id'])->update($data);
            $item = $model->find((int)$item['id']);
        } else {
            $data['create_time'] = $now;
            $model->create($data);
            $item = $model->where('client_id', $clientId)->where('server_id', $serverId)->where('name', $name)->find();
        }
        return json(['status' => 200, 'msg' => lang_plugins('success_message'), 'data' => $this->format($item)]);
    }

    public function delete()
    {
        $item = $this->findOwned((int)request()->param('id'));
        if (!$item) return json(['status' => 400, 'msg' => lang_plugins('furll_domain_info_template_not_found'), 'data' => []]);
        (new InfoTemplateModel())->where('id', (int)$item['id'])->update(['status' => 0, 'update_time' => time()]);
        return json(['status' => 200, 'msg' => lang_plugins('success_message'), 'data' => []]);
    }

    private function findOwned(int $id)
    {
        return (new InfoTemplateModel())->owned($id, (int)get_client_id());
    }

    private function validServer(int $serverId): bool
    {
        $server = $serverId > 0 ? ServerModel::where('id', $serverId)->where('module', 'furll_dynadot_domain')->where('status', 1)->find() : null;
        if (empty($server)) return false;
        $clientId = (int)get_client_id();
        $host = HostModel::where('client_id', $clientId)->where('server_id', $serverId)->where('is_delete', 0)->find();
        if (!empty($host)) return true;
        $product = ProductModel::where('rel_id', $serverId)->where('type', 'server')->find();
        return !empty($product) && (int)$product['status'] === 1;
    }

    private function normalizeContact($contact)
    {
        if (!is_array($contact)) $contact = [];
        $contact = array_map(function ($value) { return is_string($value) ? trim($value) : $value; }, $contact);
        foreach (['first_name', 'last_name', 'email', 'phone', 'address1', 'city', 'state', 'zip', 'country'] as $field) {
            if (empty($contact[$field])) return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_contact_invalid'), 'data' => []]];
        }
        $contact['country'] = $this->normalizeCountryCode($contact['country']);
        if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL) || !preg_match('/^[A-Za-z]{2}$/', $contact['country'])) {
            return ['error' => ['status' => 400, 'msg' => lang_plugins('furll_domain_contact_invalid'), 'data' => []]];
        }
        $allowed = ['first_name','last_name','org','email','phone','phone_cc','phone_number','address1','address2','city','state','zip','country'];
        $contact = array_intersect_key($contact, array_flip($allowed));
        $contact['country'] = strtoupper($contact['country']);
        ksort($contact);
        return $contact;
    }

    private function normalizeCountryCode($value): string
    {
        $value = trim((string)$value);
        $map = [
            '中国' => 'CN', '中国大陆' => 'CN', '中华人民共和国' => 'CN', 'CHINA' => 'CN',
            '美国' => 'US', '美国合众国' => 'US', 'UNITED STATES' => 'US', 'USA' => 'US',
            '英国' => 'GB', '英国/英格兰' => 'GB', 'UNITED KINGDOM' => 'GB', 'UK' => 'GB',
            '香港' => 'HK', '中国香港' => 'HK', 'HONG KONG' => 'HK',
            '台湾' => 'TW', '中国台湾' => 'TW', 'TAIWAN' => 'TW',
            '澳门' => 'MO', '中国澳门' => 'MO', 'MACAO' => 'MO',
            '日本' => 'JP', 'JAPAN' => 'JP', '韩国' => 'KR', 'KOREA' => 'KR',
            '新加坡' => 'SG', 'SINGAPORE' => 'SG', '加拿大' => 'CA', 'CANADA' => 'CA',
            '澳大利亚' => 'AU', 'AUSTRALIA' => 'AU', '德国' => 'DE', 'GERMANY' => 'DE',
            '法国' => 'FR', 'FRANCE' => 'FR', '印度' => 'IN', 'INDIA' => 'IN',
        ];
        $upper = strtoupper($value);
        return $map[$value] ?? ($map[$upper] ?? $upper);
    }

    private function toDynadotContact(array $contact): array
    {
        $phone = preg_replace('/\s+/', '', trim((string)($contact['phone'] ?? '')));
        $phoneCc = preg_replace('/\D+/', '', (string)($contact['phone_cc'] ?? ''));
        $phoneNumber = preg_replace('/\D+/', '', (string)($contact['phone_number'] ?? ''));
        if ($phoneNumber === '') $phoneNumber = preg_replace('/\D+/', '', $phone);
        if ($phoneCc === '' && preg_match('/^\+?(\d{1,4})[.\-](\d{6,})$/', $phone, $match)) {
            $phoneCc = $match[1];
            $phoneNumber = $match[2];
        }
        $countryPhoneCodes = ['CN' => '86', 'US' => '1', 'CA' => '1', 'GB' => '44', 'HK' => '852', 'TW' => '886', 'MO' => '853', 'JP' => '81', 'KR' => '82', 'SG' => '65', 'AU' => '61', 'DE' => '49', 'FR' => '33', 'IN' => '91'];
        if ($phoneCc === '') {
            $countryCode = $countryPhoneCodes[strtoupper((string)($contact['country'] ?? ''))] ?? '';
            if ($countryCode !== '' && preg_match('/^\+?' . preg_quote($countryCode, '/') . '(\d{6,})$/', $phone, $match)) {
                $phoneCc = $countryCode;
                $phoneNumber = $match[1];
            } elseif ($countryCode !== '' && preg_match('/^\d{6,}$/', $phoneNumber)) {
                $phoneCc = $countryCode;
            }
        }
        if ($phoneCc === '' || $phoneNumber === '') {
            throw new \InvalidArgumentException(lang_plugins('furll_domain_contact_invalid'));
        }
        return array_filter([
            'organization' => $contact['org'] ?? '',
            'name' => trim($contact['first_name'] . ' ' . $contact['last_name']),
            'email' => $contact['email'],
            'phone_number' => $phoneNumber,
            'phone_cc' => $phoneCc,
            'address1' => $contact['address1'],
            'address2' => $contact['address2'] ?? '',
            'city' => $contact['city'],
            'state' => $contact['state'],
            'zip' => $contact['zip'],
            'country' => $contact['country'],
        ], function ($value) { return $value !== ''; });
    }

    private function format($item): array
    {
        $data = is_array($item) ? $item : $item->toArray();
        return [
            'id' => (int)$data['id'], 'name' => $data['name'], 'server_id' => (int)$data['server_id'],
            'contact' => InfoTemplateModel::decodeContact($data['contact_data']),
            'dynadot_contact_id' => (int)$data['dynadot_contact_id'],
        ];
    }
}
