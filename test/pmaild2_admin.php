#!../php/php
<?php
date_default_timezone_set('GMT');

class PMaild2 {
	private $fd;
	private $seq;
	private $uuid;

	public function __construct($host, $port, $uuid, $key) {
		$sock = fsockopen('ssl://'.$host, $port, $errno, $errstr, 10);
		if (!$sock) throw new Exception('Failed to connect: '.$errstr);

		$handshake = fread($sock, 44); // remote id

		// check uuid
		$uuidb = pack('H*', str_replace('-', '', $uuid));
		if (substr($handshake, 0, 16) != $uuidb) throw new Exception('Peer didn\'t identify correctly');

		// check timestamp drift
		$stamp = unpack('N2', substr($handshake, 16, 8));
		$stamp[1] ^= $stamp[2];
		$stamp = (($stamp[1] << 32) | $stamp[2]) / 1000000;
		$offset = abs(microtime(true)-$stamp);
		if ($offset > 0.5) throw new Exception('Time drift is over 0.5 secs ('.$offset.'), please resync servers');

		// check signature
		$sign = sha1(pack('H*', $key).substr($handshake, 0, 24), true);
		if ($sign != substr($handshake, 24)) throw new Exception('Bad signature');

		// send our own packet
		$stamp = (int)round(microtime(true)*1000000);
		$stamp = pack('NN', (($stamp >> 32) & 0xffffffff) ^ ($stamp & 0xffffffff), $stamp & 0xffffffff);
		$handshake = str_repeat("\0", 16).$stamp;
		$handshake .= sha1(pack('H*', $key).$handshake, true);
		fwrite($sock, $handshake);

		$this->fd = $sock;
		$this->seq = 0;
		$this->uuid = $uuid;
	}

	protected function _sendPacket(array $pkt) {
		$pkt = json_encode($pkt);
		if (strlen($pkt) > 65535) throw new Exception('Error: packet is too big!');
		return fwrite($this->fd, pack('n', strlen($pkt)).$pkt);
	}

	protected function _readPacket() {
		$len = fread($this->fd, 2);
		if (feof($this->fd)) throw new Exception('Connection interrupt!');
		list(,$len) = unpack('n', $len);
		if ($len == 0) throw new Exception('Invalid packet!');

		$data = fread($this->fd, $len);
		if (strlen($data) != $len) throw new Exception('Could not read enough data (expect: '.$len.' got: '.strlen($data).')');

		return json_decode($data, true);
	}

	protected function _waitAck($ack) {
		while(1) {
			$pkt = $this->_readPacket();
			if (!isset($pkt['ack'])) continue;
			if ($pkt['ack'] == $ack) return $pkt['res'];
		}
	}

	protected function _event($evt, array $ref, $fd = NULL) {
		ksort($ref);
		$pkt = array(
			'evt' => $evt,
			'ref' => http_build_query($ref, '', '&'),
			'stp' => (int)round(microtime(true)*1000000), // magic stamp
		);
		if (!is_null($fd)) {
			fseek($fd, 0, SEEK_END);
			$pkt['dat'] = ftell($fd);
		}
		$ack = $this->seq++;
		$pkt = array(
			'typ' => 'log',
			'pkt' => $pkt,
			'ack' => $ack,
		);
		$this->_sendPacket($pkt);
		if (!is_null($fd)) {
			// send extra data too
			rewind($fd);
			stream_copy_to_stream($fd, $this->fd);
		}
		return $this->_waitAck($ack);
	}

	protected function _query($type, $ref = null) {
		if (!is_null($ref)) ksort($ref);
		$ack = $this->seq++;
		$pkt = array(
			'qry' => $type,
		);
		if (!is_null($ref)) $pkt['ref'] = http_build_query($ref, '', '&');
		$pkt = array(
			'typ' => 'qry',
			'pkt' => $pkt,
			'ack' => $ack,
		);
		$this->_sendPacket($pkt);
		return $this->_waitAck($ack);
	}

	public function askUuid() {
		// ask remote peer to be kind enough to produce an uuid and send it to us
		return $this->_query('uuid');
	}

	public function createNode($uuid, $ip, $port, $key) {
		// connect to the new node first
		$info = $this->getNode($this->uuid);
		if (!$info) return false;
		try {
			$node = new self($ip, $port, $uuid, $key);
		} catch(Exception $e) {
			return false;
		}

		// the node might have a wrong ip/port for itself
		$node_info = stream_socket_get_name($this->fd, true);
		$node_info = explode(':', $node_info);
		$info['ip'] = $node_info[0];
		$info['port'] = $node_info[1];

		// create an entry for us on the new node
		if (!$node->subCreateNode($info['node'], $info['ip'], $info['port'], $info['key'])) return false;
		// and add the node to us
		return $this->subCreateNode($uuid, $ip, $port, $key);
	}

	private function subCreateNode($uuid, $ip, $port, $key) {
		$fd = fopen('php://temp', 'r+');
		fwrite($fd, json_encode(array('ip' => $ip, 'port' => $port, 'key' => $key)));
		$res = $this->_event('node/add', array('node' => $uuid), $fd);
		fclose($fd);
		return $res;
	}

	public function getNodes() {
		return $this->_query('node');
	}

	public function getNode($node) {
		$res = $this->_query('node', array('node' => $node));
		if (isset($res[$node])) return $res[$node];
		return NULL;
	}

	public function createStore($uuid = null) {
		if (is_null($uuid)) $uuid = $this->askUuid();
		if (!$this->_event('store/add', array('store' => $uuid))) return false;
		return $uuid;
	}

	public function getStores() {
		return $this->_query('store');
	}

	public function getStore($store) {
		return $this->_query('store', array('store' => $store));
	}

	public function createDomain($domain, $store) {
		$fd = fopen('php://temp', 'r+');
		fwrite($fd, json_encode(array('store' => $store)));
		$res = $this->_event('domain/add', array('domain' => $domain), $fd);
		fclose($fd);
		return $res;
	}

	public function getDomains() {
		return $this->_query('domain');
	}

	public function getDomain($domain) {
		$res = $this->_query('domain', array('domain' => $domain));
		foreach($res as $sres) return $sres;
		return $res;
	}

	public function createAccount($store, array $properties = array(), $uuid = NULL) {
		if (is_null($uuid)) $uuid = $this->askUuid();
		$fd = fopen('php://temp', 'r+');
		fwrite($fd, json_encode($properties));
		if (!$this->_event('account/add', array('store' => $store, 'account' => $uuid), $fd)) return false;
		fclose($fd);
		return $uuid;
	}

	public function createLogin($store, $login, $type, $target) {
		$fd = fopen('php://temp', 'r+');
		fwrite($fd, json_encode(array('type' => $type, 'target' => $target)));
		$res = $this->_event('login/add', array('store' => $store, 'login' => $login), $fd);
		fclose($fd);
		return $res;
	}

	public function createLoginToAccount($store, $login, $account) {
		return $this->createLogin($store, $login, 'account', $account);
	}

	public function getLogins($store) {
		return $this->_query('login', array('store' => $store));
	}

	public function getLogin($store, $login) {
		$res = $this->_query('login', array('store' => $store, 'login' => $login));
		foreach($res as $sres) return $sres;
		return $res;
	}
}

$adm = new PMaild2('127.0.0.1',10006,'eb720e74-223d-4660-b83f-852a1de7040e','5726a8e0dbf1f65e443de80c5dbe28c2fcced407aa70ff241017458cbe7474e1');
//var_dump($adm->listStores());

$domain = $adm->getDomain('example.com');

if ($domain) {
	$store = $domain['store'];
} else {
	$store = $adm->createStore();
	var_dump($adm->createDomain('example.com', $store));
}

$login = $adm->getLogin($store, 'test');

if (!$login) {
	$account = $adm->createAccount($store, array('password' => crypt('test')));
	var_dump($adm->createLoginToAccount($store, 'test', $account));
} else {
	var_dump($login);
}

var_dump($adm->getNodes());

var_dump($adm->createNode('5880f24b-0f87-4abd-9d1f-c9f124bbf939', '127.0.0.1', 20006, 'f799839ef564b5086907ffd03024d161dd530088b0dfa25e7e9ceb34983fb045'));

