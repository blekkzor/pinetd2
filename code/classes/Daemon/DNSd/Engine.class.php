<?php

namespace Daemon\DNSd;

use Daemon\DNSd\Type\RFC1035;

class Engine {
	const DNS_CLASS_IN = 1; // Teh Internet
	const DNS_CLASS_CS = 2; // CSNET class (obsolete, used only for examples in some obsolete RFCs)
	const DNS_CLASS_CH = 3; // CHAOS
	const DNS_CLASS_HS = 4; // Hesiod [Dyer 87]

	protected $parent;
	protected $packet_class;

	public function __construct($parent) {
		$this->parent = $parent;
		$this->packet_class = relativeclass($this, 'Packet');
	}

	public function handlePacket($data, $peer_info) {
		$pkt = new $this->packet_class();
		if (!$pkt->decode($data)) return;

		$pkt->resetAnswer();

		foreach($pkt->getQuestions() as $question) {
			if ($question['qclass'] == self::DNS_CLASS_CH) {
				// Special "magical" class...
				if (($question['qname'] == 'version.dnsd.') && ($question['qtype'] == RFC1035::TYPE_TXT)) {
					$txt = Type::factory($pkt, RFC1035::TYPE_TXT);
					$txt->setValue('DNSd PHP daemon (PHP/'.PHP_VERSION.')');
					$pkt->addAnswer('version.dnsd.', $txt, 0, self::DNS_CLASS_CH);

					$auth = Type::factory($pkt, RFC1035::TYPE_NS);
					$auth->setValue('version.dnsd.');
					$pkt->addAuthority('version.dnsd.', $auth, 0, self::DNS_CLASS_CH);
				}
				continue;
			}

			// provide an answer
			$addr = Type::factory($pkt, RFC1035::TYPE_A);
			$addr->setValue('127.0.0.1');
			$pkt->addAnswer($question['qname'], $addr);

			$cname = Type::factory($pkt, RFC1035::TYPE_TXT);
			$cname->setValue('some.other.test.');
			$pkt->addAdditional('some.test.tld.', $cname, 120);

		}

		$pkt->setFlag('qr', 1);
		$pkt->setFlag('aa', 1);

		$pkt = $pkt->encode();

		if (!is_null($pkt)) $this->parent->sendReply($pkt, $peer_info);
	}
}

