<?php
namespace server\furll_dynadot_domain\dynadot;

/**
 * Dynadot 统一适配器
 *
 * 封装 Dynadot RESTful API v2 的域名能力，对外提供统一返回：
 * ['status'=>200, 'msg'=>'', 'data'=>[]]
 *
 * 文档：docs/dynadot-api.md
 * URL 格式：/restful/v2/{resource}/{identify}/{action}
 */
class DynadotAdapter
{
    /** @var DynadotClient */
    private $client;

    public function __construct(DynadotClient $client)
    {
        $this->client = $client;
    }

    /**
     * 测试连接
     */
    public function testConnect(): array
    {
        return $this->client->get('/restful/v2/accounts/info');
    }

    /**
     * 域名查询
     */
    public function searchDomain(string $domainName, string $currency = 'cny'): array
    {
        $query = http_build_query([
            'show_price' => 'true',
            'currency'   => strtolower($currency),
        ]);

        return $this->client->get('/restful/v2/domains/' . rawurlencode($domainName) . '/search?' . $query);
    }

    /**
     * 域名注册
     */
    public function registerDomain(array $params): array
    {
        $domainName = strtolower(trim((string)($params['domain_name'] ?? '')));
        unset($params['domain_name']);

        return $this->client->post(
            '/restful/v2/domains/' . rawurlencode($domainName) . '/register',
            $params
        );
    }

    /**
     * 域名续费
     */
    public function renewDomain(string $domainName, int $years): array
    {
        return $this->client->post('/restful/v2/domains/' . $domainName . '/renew', [
            'domainName' => $domainName,
            'years'      => $years,
        ]);
    }

    /**
     * 域名转移
     */
    public function transferDomain(array $params): array
    {
        return $this->client->post('/restful/v2/domains/' . ($params['domainName'] ?? '') . '/transfer', $params);
    }

    /**
     * 域名信息
     */
    public function getDomainInfo(string $domainName): array
    {
        return $this->client->get('/restful/v2/domains/' . $domainName);
    }

    /**
     * 获取域名服务器（nameserver）列表
     *
     * @url GET /restful/v2/domains/{domain_name}/nameservers
     */
    public function getNameservers(string $domainName): array
    {
        return $this->client->get('/restful/v2/domains/' . rawurlencode($domainName) . '/nameservers');
    }

    /**
     * 设置域名服务器（nameserver）列表
     *
     * @url PUT /restful/v2/domains/{domain_name}/nameservers
     * @body {"nameserver_list": ["ns1.example.com", ...]}
     */
    public function setNameservers(string $domainName, array $nameserverList): array
    {
        return $this->client->put(
            '/restful/v2/domains/' . rawurlencode($domainName) . '/nameservers',
            ['nameserver_list' => array_values($nameserverList)]
        );
    }

    /**
     * 创建联系人
     *
     * @url POST /restful/v2/contacts
     * @body {"contact": {"organization":"","name":"","email":"","phone_number":"","phone_cc":"","address1":"","city":"","state":"","zip":"","country":""}}
     * @return data.contact_id int - 新联系人 ID
     */
    public function createContact(array $contact): array
    {
        return $this->client->post('/restful/v2/contacts', ['contact' => $contact]);
    }

    /**
     * 设置域名四类联系人（注册/管理/技术/账单）
     *
     * @url PUT /restful/v2/domains/{domain_name}/contacts
     * @body {"registrant_contact_id":1,"admin_contact_id":1,"technical_contact_id":1,"billing_contact_id":1}
     */
    public function setDomainContacts(string $domainName, array $contactIds): array
    {
        return $this->client->put(
            '/restful/v2/domains/' . rawurlencode($domainName) . '/contacts',
            $contactIds
        );
    }
}
