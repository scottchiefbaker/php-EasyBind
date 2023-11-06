<?php

require("include/easy_bind.class.php");

$eb = new easy_bind;

$domain   = $_GET['domain']  ?? "";
$action   = $_GET['action']  ?? "";
$rec_num  = $_GET['rec_num'] ?? 0;
$rec_type = $_GET['type']    ?? "";

////////////////////////////////////////////////////////

$file1 = "/home/bakers/html/tmp/easy_bind/etc/named.conf";
$file2 = "/home/bakers/html/tmp/easy_bind/etc/named-zones.conf";
$x     = $eb->parse_named_conf([$file1, $file2]);

$info = $x['zone'][$domain] ?? [];

if (!$info) {
	$eb->error_out("Could not find zone file for $domain", 95242);
}

$real_file    = $info['file'] ?? '';
$scratch_file = $eb->get_scratch_zone_name($domain);

if (is_readable($scratch_file)) {
	$file = $scratch_file;
	$eb->sluz->assign("scratch_file", $scratch_file);
} else {
	$file = $real_file;
}

if ($action === "view_zonefile") {
	$x   = $eb->parse_zone_file($file);
	$str = $eb->create_zone_str($x);

	$eb->sluz->assign("zone_file", $file);
	$eb->sluz->assign("zone_file_content", $str);

	$eb->sluz->assign("domain", $domain);
	print $eb->sluz->fetch("tpls/show_zone.stpl");
	exit;
}

$dom_info = $eb->parse_zone_file($file);

if ($action) {
	$ok = handle_action($action, $rec_type, $rec_num);

	if ($ok) {
		$dom_info = $ok;

		header("Location: ?domain=$domain");
	} else {
		$eb->error_out("Error with action", 58929);
	}
}

$eb->sluz->assign($dom_info);
$eb->sluz->assign("domain", $domain);

if (!empty($_GET['debug'])) { k($eb->sluz->tpl_vars); }
print $eb->sluz->fetch("tpls/edit_zone.stpl");

////////////////////////////////////////////////////////

function handle_action($action, $rec_type, $rec_num) {
	global $eb;
	global $dom_info;
	global $domain;

	$new_key = $_GET['key']   ?? "";
	$new_val = $_GET['value'] ?? "";

	//$x = $eb->get_record($dom_info, $rec_type, $rec_num);
	$new_obj = [];

	if ($action === "update_record" && $rec_type && $rec_num) {
		//k("Doing edit");
		$new_obj = $eb->update_record($dom_info, $rec_type, $rec_num, $new_key, $new_val);
	} elseif ($action === "delete_rec" && $rec_type && $rec_num) {
		//k("Doing delete");
		$new_obj = $eb->delete_record($dom_info, $rec_type, $rec_num);
	} elseif ($action === "add_record" && $new_key) {
		//k("Doing add");
		$new_obj = $eb->add_record($dom_info, $rec_type, $new_key, $new_val);
	} elseif ($action === "publish") {
		$ok = $eb->publish_zone($domain);
		kd($ok);
	} elseif ($action === "discard") {
		$scratch_file = $eb->get_scratch_zone_name($domain);
		$ok           = unlink($scratch_file);

		if (!$ok) {
			$eb->error_out("Unable to remove scratch file $scratch_file", 84321);
		} else {
			header("Location: ?domain=$domain");
			exit(7);
		}
	} else {
		$eb->error_out("Unknown action $action", 49542);
	}

	$eb->write_scratch_zone($domain, $new_obj);

	return $new_obj;
}

// vim: tabstop=4 shiftwidth=4 noexpandtab autoindent softtabstop=4

