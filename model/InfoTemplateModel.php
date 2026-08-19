<?php
namespace server\furll_dynadot_domain\model;

use think\Model;
use think\facade\Db;

class InfoTemplateModel extends Model
{
    protected $name = 'module_furll_dynadot_domain_info_template';

    private const TABLE = 'idcsmart_module_furll_dynadot_domain_info_template';

    private const CREATE_TABLE_SQL = <<<SQL
CREATE TABLE IF NOT EXISTS `idcsmart_module_furll_dynadot_domain_info_template` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL DEFAULT '0',
  `server_id` int(11) NOT NULL DEFAULT '0',
  `name` varchar(100) NOT NULL DEFAULT '',
  `contact_data` text NOT NULL,
  `contact_hash` char(64) NOT NULL DEFAULT '',
  `dynadot_contact_id` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `client_server` (`client_id`,`server_id`),
  UNIQUE KEY `client_server_name` (`client_id`,`server_id`,`name`),
  KEY `contact_hash` (`client_id`,`server_id`,`contact_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='FurLLORG Dynadot信息模板';
SQL;

    public function createTable()
    {
        Db::execute(self::CREATE_TABLE_SQL);
        return true;
    }

    public function dropTable()
    {
        Db::execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        return true;
    }

    public function forClient(int $clientId, int $serverId = 0): array
    {
        if ($clientId <= 0) return [];
        $query = $this->where('client_id', $clientId)->where('status', 1);
        if ($serverId > 0) $query->where('server_id', $serverId);
        return $query->order('id', 'desc')->select()->toArray();
    }

    public function owned(int $id, int $clientId, int $serverId = 0)
    {
        if ($id <= 0 || $clientId <= 0) return null;
        $query = $this->where('id', $id)->where('client_id', $clientId)->where('status', 1);
        if ($serverId > 0) $query->where('server_id', $serverId);
        return $query->find();
    }

    public function findByContactHash(int $clientId, int $serverId, string $hash)
    {
        if ($clientId <= 0 || $serverId <= 0 || $hash === '') return null;
        return $this->where('client_id', $clientId)->where('server_id', $serverId)
            ->where('contact_hash', $hash)->where('status', 1)->find();
    }

    public function deleteByServerId(int $serverId)
    {
        if ($serverId > 0) $this->where('server_id', $serverId)->delete();
        return true;
    }

    public static function contactHash(array $contact): string
    {
        ksort($contact);
        return hash('sha256', json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function decodeContact($value): array
    {
        if (is_array($value)) return $value;
        $data = json_decode((string)$value, true);
        return is_array($data) ? $data : [];
    }
}
