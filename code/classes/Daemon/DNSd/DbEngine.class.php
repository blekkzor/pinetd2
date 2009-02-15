<?php

namespace Daemon\DNSd;

use pinetd\Logger;
use pinetd\SQL;

class DbEngine {
	protected $localConfig;
	protected $tcp;

	function __construct($localConfig, $tcp) {
		$this->localConfig = $localConfig;

		// check table struct
		$this->sql = SQL::Factory($this->localConfig['Storage']);

		$storage = relativeclass($this, 'Storage');
		$storage::validateTables($this->sql);

		$this->tcp = $tcp;
	}

	function __call($func, $args) {
		return NULL;
	}

	protected function tableKey($table) {
		switch($table) {
			case 'domains': return 'key';
			case 'zone_records': return 'record_id';
			case 'zones': return 'zone_id';
			case 'deletions': return 'key';
			default: return NULL;
		}
	}

	protected function doDelete($table, $key, $value) {
		$this->sql->query('BEGIN TRANSACTION');

		// delete entry
		if (!$this->sql->query('DELETE FROM `'.$table.'` WHERE `'.$key.'` = '.$this->sql->quote_escape($value))) {
			$this->sql->query('ROLLBACK');
			return false;
		}

		if ($this->sql->affected_rows < 1) {
			$this->sql->query('ROLLBACK');
			return false;
		}
		
		// store delete event and dispatch it
		$insert = array(
			'deletion_table' => $table,
			'deletion_id' => $value,
			'changed' => $this->sql->now(),
		);
		if (!$this->sql->insert('deletions', $insert)) {
			$this->sql->query('ROLLBACK');
			return false;
		}

		$insert['key'] = $this->sql->insert_id;

		$this->sql->query('COMMIT');

		$this->tcp->dispatch('deletions', $insert['key'], $insert);

		return true;
	}

	public function processUpdate($table, $data) {
		if (!isset($this->localConfig['Master'])) return NULL; // I GOT NO MASTERS!

		$key = $this->tableKey($table);
		if (is_null($key)) {
			Logger::log(Logger::LOG_WARN, 'Got update from DNSd master for unknown table '.$table);
			return;
		}
		$key_val = $data[$key];

		if ($this->sql->query('SELECT 1 FROM `'.$table.'` WHERE `'.$key.'` = '.$this->sql->quote_escape($key_val))->fetch_assoc()) {
			// update
			unset($data[$key]);
			$req = '';
			foreach($data as $var => $val) $req.=($req==''?'':',').'`'.$var.'` = '.$this->sql->quote_escape($val);
			$req = 'UPDATE `'.$table.'` SET '.$req.' WHERE `'.$key.'` = '.$this->sql->quote_escape($key_val);
			$this->sql->query($req);
		} else {
			$this->sql->insert($table, $data);
		}

		if ($table == 'deletions') {
			$delete_key = $this->tableKey($data['deletion_table']);
			if (!is_null($delete_key)) {
				$req = 'DELETE FROM `'.$data['deletion_table'].'` WHERE `'.$delete_key.'` = '.$this->sql->quote_escape($data['deletion_id']);
				$this->sql->query($req);
			}
		}
	}

	/*****
	 ** Zones management
	 *****/

	public function createZone($zone) {
		// Try to insert zone
		$zone = strtolower($zone);
		$now = $this->sql->now();

		$data = array(
			'zone' => $zone,
			'created' => $now,
			'changed' => $now,
		);

		if (!$this->sql->insert('zones', $data)) return false;

		$id = $this->sql->insert_id;
		$data['zone_id'] = $id;
		$this->tcp->dispatch('zones', $id, $data);

		return $id;
	}

	public function getZone($zone) {
		$res = $this->sql->query('SELECT `zone_id` FROM `zones` WHERE `zone` = '.$this->sql->quote_escape($zone))->fetch_assoc();

		return $res['zone_id'];
	}

	public function deleteZone($zone) {
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}
		if (!$zone) return false;

		return $this->doDelete('zones', 'zone_id', $zone);
	}

	/*****
	 ** Records management
	 *****/

	public function addRecord($zone, $host, $type, $value, $ttl = 86400) {
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}
		if (!$zone) return NULL;

		if (!is_array($value)) {
			$value = array('data' => $value);
		}
//CREATE TABLE `zone_records` (`record_id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `zone` int(11) NOT NULL, `host` varchar(128) NOT NULL, `ttl` int(11) NOT NULL, `type` varchar(10) NOT NULL, `mx_priority` int(11), `data` text NOT NULL, `resp_person` text, `serial` unsigned , `refresh` unsigned , `retry` unsigned , `expire` unsigned , `minimum` unsigned , `changed` datetime NOT NULL);

		$allowed = array('mx_priority', 'data', 'resp_person', 'serial', 'refresh', 'retry', 'expire', 'minimum', 'changed');

		$insert = array();

		foreach($allowed as $var) {
			if (array_key_exists($var, $value)) $insert[$var] = $value[$var];
		}

		$insert['zone'] = strtolower($zone);
		$insert['host'] = strtolower($host);
		$insert['type'] = strtoupper($type);
		$insert['ttl'] = $ttl;
		$insert['changed'] = $this->sql->now();

		$fields = '';
		$values = '';
		foreach($insert as $var => $val) {
			$fields .= ($fields == ''?'':', ') . '`' . $var . '`';
			$values .= ($values == ''?'':', ') . $this->sql->quote_escape($val);
		}

		$res = $this->sql->insert('zone_records', $insert);
		if (!$res) return false;

		$id = $this->sql->insert_id;
		$insert['record_id'] = $id;

		$this->tcp->dispatch('zone_records', $id, $insert);

		return $id;
	}

	public function deleteRecord($rid) {
		if (!$rid) return false;

		return $this->doDelete('zone_records', 'record_id', $rid);
	}

	public function dumpZone($zone, $start = 0, $limit = 50) {
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}

		$req = 'SELECT * FROM `zone_records` WHERE `zone` = '.$this->sql->quote_escape($zone).' LIMIT '.((int)$start).','.((int)$limit);
		$res = $this->sql->query($req);

		$final_res = array();

		while($row = $res->fetch_assoc()) {
			foreach($row as $var=>$val) if (is_null($val)) unset($row[$var]);
			$final_res[] = $row;
		}

		return $final_res;
	}

	/*****
	 ** Domains management
	 *****/

	public function createDomain($domain, $zone) {
		// Try to insert domain
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}
		$domain = strtolower($domain);
		$insert = array(
			'domain' => $domain,
			'zone' => $zone,
			'created' => $this->sql->now(),
			'changed' => $this->sql->now(),
		);

		$res = $this->sql->insert('domains', $insert);
		if (!$res) return false;

		$id = $this->sql->insert_id;
		$insert['key'] = $id;

		$this->tcp->dispatch('domains', $id, $insert);
		return $id;
	}

	public function getDomain($domain) {
		$res = $this->sql->query('SELECT `key` FROM `domains` WHERE `domain` = '.$this->sql->quote_escape($domain))->fetch_assoc();

		return $res['key'];
	}

	public function deleteDomain($domain) {
		if (!is_numeric($domain)) {
			$domain = $this->getDomain($domain);
		}
		if (!$domain) return false;

		return $this->doDelete('domains', 'key', $domain);
	}

	/*****
	 ** Etc
	 *****/

	public function lastUpdateDate() {
		$recent = 0;

		foreach(array('deletions', 'domains', 'zone_records', 'zones') as $table) {
			$req = 'SELECT UNIX_TIMESTAMP(MAX(`changed`)) AS changed FROM `'.$table.'`';
			$res = $this->sql->query($req)->fetch_assoc();
			if ($res) $recent = max($recent, $res['changed']);
		}

		return $recent;
	}
}

