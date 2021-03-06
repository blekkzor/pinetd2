<?php

namespace Daemon\PMaild\MTA;
use pinetd\SQL;

class MailSolver {
	static protected function readAlias($SQL, $alias, $res) {
		$alias->last_transit = $SQL->now();
		$alias->commit();
		// determine case
		if (!is_null($alias->http_target)) {
			$res['type'] = 'http';
			$res['target'] = $alias->http_target;
			$res['on_error'] = $alias->mail_target; // if HTTP call fails, a mail is dispatched
			return $res;
		}
		if (!is_null($alias->mail_target)) {
			$target = $alias->mail_target;
			if ($target[0] == '@') $target = $user . $target;
			$res['type'] = 'redirect';
			$res['target'] = $target;
			return $res;
		}
		// CONTINUE HERE: load account referenced, and continue to next
		return $alias->real_target;
	}

	static protected function readList($SQL, $list, $res, $mode = 'message') {
		$list->last_transit = $SQL->now();
		$list->commit();

		$res['type'] = 'list';
		$res['target'] = $list->id;
		$res['list'] = $list;
		$res['mode'] = $mode;
		return $res;
	}

	static protected function getListName($name) {
		$pos = strrpos($name, '-');
		if ($pos === false) return false;
		$code = strtolower(substr($name, $pos+1));
		$name = substr($name, 0, $pos);
		switch($code) {
			case 'subscribe':
			case 'unsubscribe':
				return array($name, $code);
		}
		return false;
	}

	static public function solve($mail, &$localConfig) {
		$res = array();
		$res['mail'] = $mail;
		$res['received'] = array();

		$pos = strrpos($mail, '@');
		if ($pos === false) return false;
		$user = substr($mail, 0, $pos);
		$domainname = substr($mail, $pos+1);

		$res['mail_user'] = $user;
		$res['mail_domain'] = $domainname;

		$SQL = SQL::Factory($localConfig['Storage']);

		$DAO_domains = $SQL->DAO('domains', 'domainid');
		$domain = $DAO_domains->loadByField(array('domain' => $domainname));

		if (!$domain) {
			$DAO_domainaliases = $SQL->DAO('domainaliases', 'domain');
			$alias = $DAO_domainaliases[$domainname];
			if (!$alias) {
				// No domainalias, no domain, it's not someone from here
				$res['type'] = 'remote';
				$res['target'] = $res['mail'];
				return $res;
			}
			$domain = $DAO_domains[$alias->domainid];
		} else {
			$domain = $domain[0];
		}

		// fetch flags for this domain
		$flags = $domain->flags;
		$flags = array_flip(explode(',', $flags));

		// at this point we know we'll have a local delivery
		Storage::validateTables($SQL, $domain->domainid);
		$res['domainid'] = $domain->domainid;
		$res['domainbean'] = $domain;

		if (isset($flags['account_without_plus_symbol'])) {
			// emails on this domain can't contain a "+" symbol. It is used to add extra data
			list($user) = explode('+', $user);
		}

		// spawn this domain's DAO
		$DAO_accounts = $SQL->DAO('z'.$domain->domainid.'_accounts', 'id');
		$DAO_alias = $SQL->DAO('z'.$domain->domainid.'_alias', 'id');
		$DAO_lists = $SQL->DAO('z'.$domain->domainid.'_lists', 'id');
		// first, locate an alias...

		$alias = $DAO_alias->loadByField(array('user'=>$user));
		if ($alias) {
			$aliasres = self::readAlias($SQL, $alias[0], $res);
			if (is_array($aliasres)) return $aliasres;
			$account = $DAO_accounts[$aliasres];
			// CONTINUE HERE: load account referenced, and continue to next
		} else {
			// load mailing list
			$list = $DAO_lists->loadByField(array('user'=>$user));
			if ($list) {
				return self::readList($SQL, $list[0], $res);
			} else {
				// Load account
				$account = $DAO_accounts->loadByField(array('user' => $user));
			}
		}
		if (!$account) {
			// check for special list cases
			$list_name = self::getListName($user);
			if ($list_name) {
				$list = $DAO_lists->loadByField(array('user'=>$list_name[0]));
				if ($list) {
					$list = $list[0];
					if ($list->allow_subscribe == 'Y') {
						return self::readList($SQL, $list, $res, $list_name[1]);
					}
				}
			}
			// check for 'default' alias
			$alias = $DAO_alias->loadByField(array('user'=>'default'));
			if ($alias) {
				$aliasres = self::readAlias($SQL, $alias[0], $res);
				if (is_array($aliasres)) return $aliasres;
				$account = $DAO_accounts[$aliasres];
			}
			// check if we should "create account on mail"
			if (isset($flags['create_account_on_mail'])) {
				// Let's create an empty account
				$DAO_accounts->insertValues(array('user' => $user, 'password' => NULL));
				$account = $DAO_accounts->loadByField(array('user' => $user));
			}
			if (!$account)
				return '503 5.1.1 Mailbox not found';
		}

		// check if we got an account list, or just one account
		if (is_array($account))
			$account = $account[0];

		if (!is_null($account->redirect)) {
			$res['type'] = 'redirect';
			$res['target'] = $account->redirect;
			return $res;
		}
		$res['type'] = 'local';
		$res['target'] = $account->id;
		$res['target_mail'] = $account->user.'@'.$domain->domain;
		return $res;
	}
}

