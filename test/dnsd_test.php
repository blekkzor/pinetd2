<?php

require(dirname(__FILE__).'/DNSd_updater.class.php');

$dnsd = new DNSd_updater('MyPeer', '127.0.0.1', 'azerty', 10053);

echo 'Connected to '.$dnsd->getNode()."\n";

$id = $dnsd->getZone('example.zone');

if (is_null($id)) { // zone not found?

	// let's create it
	$id = $dnsd->createZone('example.zone');

	if (!$id) die("Failed to create zone!\n");

	// Now, let's have some fun with our zone

	// addRecord(zone, host, type, value, ttl)
	$dnsd->addRecord($id, '', 'A', '127.0.0.1', 600);

	// NS record on a full domain (notice the "." at the end)
	$dnsd->addRecord($id, '', 'NS', 'localhost.');

	// a MX
	$dnsd->addRecord($id, '', 'MX', array('data' => 'mail.example.com.', 'mx_priority' => 10));

	// SOA...
	$dnsd->addRecord($id, '', 'SOA', array('data' => 'localhost.', 'resp_person' => 'root', 'serial' => '2009021500', 'refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600));

	// Let's put a host for www
	var_dump($dnsd->addRecord($id, 'www', 'A', '127.0.0.1'));

	// wildcard cname
	var_dump($dnsd->addRecord($id, '*', 'CNAME', 'www'));

	// ipv6 address
	var_dump($dnsd->addRecord($id, 'ipv6', 'AAAA', '::1'));
	var_dump($dnsd->addRecord($id, 'ipv6', 'A', '127.0.0.1'));
}

// Listing records in a zone
// dumpZone(zone[, start[, limit]])
//var_dump($dnsd->dumpZone('shigoto'));

// Deleting a record (the id can be found via dumpZone, or via the value returned by addRecord)
//var_dump($dnsd->deleteRecord(7));

// createDomain(domain, zone)
var_dump($dnsd->createDomain('example.com', $id));
//var_dump($dnsd->createDomain('test.com', $id));
//var_dump($dnsd->createDomain('test2.com', $id));
//var_dump($dnsd->createDomain('test3.com', $id));

//var_dump($dnsd->deleteDomain('test2.com'));

