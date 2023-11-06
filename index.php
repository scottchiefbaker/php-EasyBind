<?php

require("include/easy_bind.class.php");

sw();

$eb = new easy_bind;

$edit = $_GET['edit'] ?? "";

////////////////////////////////////////////////////////

$file1 = "/home/bakers/html/tmp/easy_bind/etc/named.conf";
$file2 = "/home/bakers/html/tmp/easy_bind/etc/named-zones.conf";
$files = [$file1, $file2];
$x     = $eb->parse_named_conf($files);

$info = $x['zone'][$edit] ?? [];
if ($edit && $info) {
	$zone_name = $edit;
	$file      = $info['file'] ?? '';

	$y = $eb->parse_zone_file($file);

	/*
	$str = $eb->create_zone_str($y);
	print "<pre>$str</pre>";

	$date_str  = date("Y-m-d");
	$file_name = $eb->scratch_dir . "/$zone_name-$date_str.dns";
	$bytes     = file_put_contents($file_name, $str);

	$ok = $eb->verify_zone_file($zone_name, $file_name, $err_str);

	if (!$ok) {
		k($err_str);
	}
	*/
}

$zones = $x['zone'] ?? "";
ksort($zones);

$eb->sluz->assign('zones', $zones);

if (!empty($_GET['debug'])) { k($eb->sluz->tpl_vars); }
print $eb->sluz->fetch("tpls/index.stpl");

////////////////////////////////////////////////////////

// vim: tabstop=4 shiftwidth=4 noexpandtab autoindent softtabstop=4

