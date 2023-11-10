<?php

require("include/easy_bind.class.php");

sw();

$eb = new easy_bind;

$domain = $_GET['edit'] ?? "";

////////////////////////////////////////////////////////

$info = $eb->get_all_zones();

if ($domain && $info) {
	$file      = $info['file'] ?? '';

	$y = $eb->parse_zone_file($file);
}

$zones = $info['zone'] ?? "";
ksort($zones);

$eb->sluz->assign('zones', $zones);
$eb->sluz->assign('logout_url', $eb->logout_url());
$eb->sluz->assign('version', $eb->version);

if (!empty($_GET['debug'])) { k($eb->sluz->tpl_vars); }
print $eb->sluz->fetch("tpls/index.stpl");

////////////////////////////////////////////////////////

// vim: tabstop=4 shiftwidth=4 noexpandtab autoindent softtabstop=4

