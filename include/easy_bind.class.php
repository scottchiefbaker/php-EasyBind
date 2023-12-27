<?php

require('krumo/class.krumo.php');
require('sluz/sluz.class.php');

$s = new sluz();

////////////////////////////////////////////////////////

class easy_bind {
	var $sluz;
	var $scratch_dir = "";
	var $version     = "0.1.0";

	var $bind_config_files    = [];
	var $rndc_key             = '';
	var $rndc_path            = '';
	var $named_checkzone_path = '';
	var $ini_file             = '';

	function __construct() {
		$this->sluz = new sluz();

		$ini_file = __DIR__ . "/../easy_bind.ini";
		if (!is_readable($ini_file)) {
			$this->error_out("Unable to read <code>easy_bind.ini</code>", 42040);
		}

		$x                          = parse_ini_file($ini_file);
		$str                        = $x['bind_config_files']    ?? "";
		$this->rndc_key             = $x['rndc_key_file']        ?? "";
		$this->rndc_path            = $x['rndc_path']            ?? "/usr/sbin/rndc";
		$this->named_checkzone_path = $x['named_checkzone_path'] ?? "/usr/bin/named-checkzone";
		$this->scratch_dir          = $x['scratch_dir']          ?? "/var/tmp/easy_bind/";
		$this->bind_config_files    = preg_split("/,\s*/",$str);
		$this->ini_file             = $ini_file;

		$ok = $this->check_user();

		if (!str_ends_with($this->scratch_dir, '/')) {
			$this->scratch_dir .= '/';
		}

		// Make sure dirs are in the correct place and writeable
		$this->startup_checks();
	}

	function startup_checks() {
		// If the scratch dir is not there attempt to create it real quick
		if (!is_dir($this->scratch_dir)) {
			mkdir($this->scratch_dir, 0744);
		}

		if (!is_dir($this->scratch_dir)) {
			$this->error_out("<p>Scratch directory <code>{$this->scratch_dir}</code> missing</p>Command Fix: <code>mkdir {$this->scratch_dir}</code>", 19405);
		}

		if (!is_writeable($this->scratch_dir)) {
			$this->error_out("<p>Scratch directory <code>{$this->scratch_dir}</code> not writable</p>Command Fix: <code>chmod apache {$this->scratch_dir}</code>", 89034);
		}
	}

	function check_user() {
		$raw   = parse_ini_file($this->ini_file, true);
		$users = $raw['users'] ?? [];

		if (!isset($_SERVER['PHP_AUTH_USER'])) {
			$this->send_login_header();
		} else {
			// These are the ones passed in from the browser
			$php_user = $_SERVER['PHP_AUTH_USER'] ?? "";
			$php_pass = $_SERVER['PHP_AUTH_PW']   ?? "";

			// This is the hashed pwd from the INI file
			$ini_pwd = $users[$php_user] ?? "";

			// User not found in INI file
			if (!$ini_pwd) {
				$this->send_login_header();
			}

			// Password does not match INI file
			$ok = password_verify($php_pass, $ini_pwd);
			if (!$ok) {
				$this->send_login_header();
			}

			return $php_user;
		}
	}

	function logout_url() {
		$scheme = $_SERVER['REQUEST_SCHEME'] ?? "";
		$domain = $_SERVER['HTTP_HOST']      ?? "";
		$uri    = $_SERVER['REQUEST_URI']    ?? "";

		$url = "//log:out@" . $domain . $uri;

		return $url;
	}

	function send_login_header() {
		$realm = "EasyBind";

		header("WWW-Authenticate: Basic realm=\"$realm\"");
		header('HTTP/1.0 401 Unauthorized');
		echo 'Cancelled by user';
		exit;
	}

	function parse_named_conf(array $files) {
		$str = '';

		foreach ($files as $file) {
			if (!file_exists($file)) {
				$this->error_out("Config file <code>$file</code> does not exist", 95980);
			}

			if (!is_readable($file)) {
				$this->error_out("Unable to read <code>$file</code>", 95953);
			}

			$str .= file_get_contents($file);
		}

		// directory "/var/named";
		$ret['dir'] = '';

		if (preg_match("/directory \"(.+?)\";/", $str, $m)) {
			$ret['dir'] = $m[1];
		}

		$x          = preg_match_all("/zone \"(.+?)\".+?file \"(.+?)\"/sm", $str, $m);
		$zone_count = 0;

		for ($i = 0; $i < $x; $i++) {
			$key = $m[1][$i];
			$val = $m[2][$i];

			// If it doesn't start with / we prepend the dir
			if ($val[0] != '/') {
				$val = $ret['dir'] . '/' . $val;
			}

			// Skip arpa and . (for now)
			if (preg_match("/.arpa|^\.$/", $key)) {
				continue;
			}

			$ret['zone'][$key]['file'] = $val;
			$ret['zone'][$key]['name'] = $key;

			$zone_count++;
		}

		if ($zone_count == 0) {
			$ret['zone'] = [];
			$msg = "<b>Warning</b>: no zones found in config files";

			$this->sluz->assign('warning_msg', $msg);
		}

		return $ret;
	}

	// Parse a zone file in to hash
	function parse_zone_file($file) {
		$lines = file($file);

		// We do this first clean up to get rid of the comments
		foreach ($lines as &$line) {
			$line = rtrim($line);
			$line = preg_replace("/;.+/", "", $line);
		}

		$ret = [];

		// Build a full string again but this time without the comments
		$full = join("\n", $lines);

		// TTL is after the literal '$TTL'
		$ret['ttl'] = $this->get_text("^\\\$TTL (\d+)", $full);

		// Header part is the first section between parens
		$head = $this->get_text('\((.+?)\)', $full);
		$head = trim($head);

		// Split apart the header to get the needed parts
		$parts = qw($head);
		$ret['serial']  = $parts[0] ?? -1;
		$ret['refresh'] = $parts[1] ?? -1;
		$ret['retry']   = $parts[2] ?? -1;
		$ret['expire']  = $parts[3] ?? -1;
		$ret['min_ttl'] = $parts[4] ?? -1;

		if (preg_match("/^.*SOA.*$/m", $full, $m)) {
			$p = qw($m[0]);

			$ret['zone_name']  = $p[0] ?? "";
			$ret['zone_dns']   = $p[3] ?? "";
			$ret['zone_email'] = $p[4] ?? "";
		} else {
			$this->error_out("Unable to find SOA data", 13915);
		}

		$count = 1;

		// Throw away all the header lines: everything up to the first ')'
		$xline = '';
		while (!str_contains($xline, ")")) {
			$xline = array_shift($lines);
		}

		$line_count = 0;
		// Look for each line/entry
		foreach ($lines as $l) {
			// If it has IN/MX it's a valid entry
			if (preg_match("/\sIN\s|\sMX\s/", $l)) {
				$parts = preg_split("/\s+/", $l, 4);

				$key  = $parts[0] ?? "";
				$type = $parts[2] ?? "";
				$val  = $parts[3] ?? "";
				$type = strtoupper($type);

				// MX Records have an additional field
				if ($type === 'MX') {
					$p   = qw($val);
					$num = $p[0] ?? 0;
					$val = $p[1] ?? '';

					$ret['records'][$type][$num][$count] = $val;
				} else {
					$ret['records'][$type][$key][$count] = $val;
				}

				$count++;
			// These are NON entry lines
			} elseif ($l) {
				print "<div>Unknown data on line $line_count on zone import: <code>$l</code></div>\n";
			}

			$line_count++;
		}

		return $ret;
	}

	function create_zone_str(array $x) {
		if (empty($x['serial'])) {
			return '';
		}

		$cur_serial = $x['serial'];

		//$cur_serial = "20231104095708";

		// I like using the unixtime as the serial number, but some people use
		// YYYYMMDDHHIISS so this should allow us to work with either
		$now = time();
		if ($now > $cur_serial) {
			$serial = $now;
		} else {
			$serial = $cur_serial + 1;
		}

		// Header section
		$ret  = '$ORIGIN ' . $x['zone_name'] . "\n";
		$ret .= '$TTL ' . $x['ttl'] . "\n";
		$ret .= "{$x['zone_name']}	IN\tSOA\t{$x['zone_dns']}\t{$x['zone_email']} (\n";
		$ret .= sprintf("\t%-14s ; Serial\n", $serial);
		$ret .= sprintf("\t%-14s ; Refresh with master\n", $x['refresh']);
		$ret .= sprintf("\t%-14s ; Retry if master down\n", $x['retry']);
		$ret .= sprintf("\t%-14s ; Expire domain\n", $x['expire']);
		$ret .= sprintf("\t%-14s ; Minimum TTL\n", $x['min_ttl']);

		//$ret .= "\t{$x['refresh']}		; Refresh with master\n";
		//$ret .= "\t{$x['retry']}		; Retry if master down\n";
		//$ret .= "\t{$x['expire']}		; Expire domain\n";
		//$ret .= "\t{$x['min_ttl']}		; Minimum TTL\n";
		$ret .= ")\n";
		$ret .= "\n";

		$width     = 100;
		$recs      = $x['records'] ?? [];
		$rec_types = array_keys($recs);
		rsort($rec_types);

		$count = 0;
		foreach ($rec_types as $type) {
			$txt    = " $type records ";
			$remain = $width - strlen($txt);
			$left   = str_repeat(";", ceil($remain / 2));
			$right  = str_repeat(";", floor($remain / 2));

			$banner = $left . $txt . $right;

			$ret .= "$banner\n";
			$ret .= "\n";

			$section = $recs[$type] ?? [];
			ksort($section);

			//$keys   = array_keys($section);
			//$maxlen = max(array_map('strlen', $keys));
			//if ($maxlen < 10) { $maxlen = 10; }

			foreach ($section as $key => $data) {
				foreach ($data as $idx => $val) {
					if ($type === 'MX') {
						$mx_val  = $key;
						$nkey    = $x['zone_name'];

						$ret .= sprintf("%-20s\tIN\tMX\t%d\t%s\n", $nkey, $mx_val, $val);
					} else {
						$ret .= sprintf("%-20s\tIN\t%s\t%s\n", $key, $type, $val);
					}

					$count++;
				}
			}

			$ret .= "\n";
		}

		$time_str = date("Y-m-d H:i:s");
		$version  = $this->version;
		$footer   = " Total Records: $count / Last updated $time_str / EasyBind v$version ";
		$remain   = $width - strlen($footer);
		$left     = str_repeat(";", ceil($remain / 2));
		$right    = str_repeat(";", floor($remain / 2));

		$ret .= $left . $footer . $right;
		$ret .= "\n";

		return $ret;
	}

	function get_record($obj, $type, $num) {
		$recs    = $obj['records'] ?? [];
		$section = $recs[$type];

		foreach ($section as $key => $data) {
			foreach ($data as $idx => $val) {
				if ($idx == $num) {
					$ret = [
						'type'    => $type,
						'key'     => $key,
						'value'   => $val,
						'rec_num' => $idx,
					];

					return $ret;
				}
			}
		}

		return [];
	}

	function delete_record($obj, $type, $num) {
		$recs    = $obj['records'] ?? [];
		$section = $recs[$type];

		foreach ($section as $key => $data) {
			foreach ($data as $idx => $val) {
				if ($idx == $num) {
					// Remove the original key
					unset($obj['records'][$type][$key][$num]);

					// If the whole array key is empty, remove it
					if (empty($obj['records'][$type][$key])) {
						unset($obj['records'][$type][$key]);
					}
				}
			}
		}

		return $obj;
	}

	function add_record($obj, $type, $new_key, $new_val) {
		$num  = $this->get_highest_rec_id($obj) + 1;
		$recs = $obj['records'] ?? [];

		$obj['records'][$type][$new_key][$num] = $new_val;

		return $obj;
	}

	// Either IPV4 or IPV6
    public function is_ip($str) {
        $ret = filter_var($str, FILTER_VALIDATE_IP);

        return $ret;
    }

    public function is_ipv4($str) {
        $ret = filter_var($str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        return $ret;
    }

    public function is_ipv6($str) {
        $ret = filter_var($str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        return $ret;
    }

	function get_highest_rec_id($obj) {
		$max  = 0;
		$recs = $obj['records'] ?? [];

		foreach ($recs as $section) {
			foreach ($section as $key => $data) {
				foreach ($data as $idx => $val) {
					if ($idx > $max) {
						$max = $idx;
					}
				}
			}
		}

		return $max;
	}

	function get_zone_info($zone_name) {
		$cfgs = $this->bind_config_files;
		$x    = $this->parse_named_conf($cfgs);

		$ret = $x['zone'][$zone_name] ?? [];

		// Don't bother adding anything else if we can't find the domain
		if (!$ret) {
			return $ret;
		}

		$file_name = $this->get_scratch_zone_name($zone_name);
		if (is_readable($file_name)) {
			$ret['scratch_file'] = $file_name;
		} else {
			$ret['scratch_file'] = false;
		}

		return $ret;
	}

	function get_all_zones() {
		$cfgs = $this->bind_config_files;
		$ret  = $this->parse_named_conf($cfgs);

		return $ret;
	}

	function publish_zone($zone_name) {
		$scratch_file = $this->get_scratch_zone_name($zone_name);

		$cfgs  = $this->bind_config_files;
		$x     = $this->parse_named_conf($cfgs);

		$info = $x['zone'][$zone_name] ?? [];

		if (!$info) {
			$eb->error_out("Could not find zone file for $zone_name", 56902);
		}

		$real_file = $info['file'] ?? '';
		$ret       = 0;

		$ok = $this->verify_zone_file($zone_name, $scratch_file, $err_str);

		if (!$ok) {
			$this->error_out("<p>Error publishing zone</p><p><pre>$err_str</pre></p>", 49294);
		}

		if ($real_file && $scratch_file) {
			if (!is_writable($real_file)) {
				$this->error_out("Unable to write to <code>$real_file</code>", 45952);
			}

			$ok = copy($scratch_file, $real_file);

			if ($ok) {
				unlink($scratch_file);
			} else {
				$this->error_out("Something went wrong with the copy", 58422);
			}

			$ret = 1;
		} else {
			$this->error_out("Missing data", 94524);
		}

		$ok = $this->reload_zone($zone_name, $err_str);

		if (!$ok) {
			$this->error_out("<p>Error reloading zone</p><p><pre>$err_str</pre></p>", 47510);
			$ret = false;
		}

		return $ret;
	}

	function update_record($obj, $type, $num, $new_key, $new_val) {
		$obj = $this->delete_record($obj, $type, $num);
		$obj = $this->add_record($obj, $type, $new_key, $new_val);

		return $obj;
	}

	// Simple regexp paren matcher/extractor
	function get_text($pattern, $str, $default = "") {
		$x = preg_match("/$pattern/sm", $str, $m);

		$ret = $m[1] ?? $default;

		return $ret;
	}

	function error_out($msg, $num) {
		$this->sluz->assign('err_num', $num);
		$this->sluz->assign('err_msg', $msg);
		print $this->sluz->fetch("tpls/error_out.stpl");
		exit(7);
	}

	function reload_zone($zone_name, $err_str = "") {
		$cmd = "/usr/sbin/rndc reload $zone_name";
		exec($cmd, $out, $exit);

		$err_str = $out;

		// Unix: Zero = good, Non-zero = bad
		$ret = !$exit;

		return $ret;
	}

	// Pass in an err_str with your data to return any potential errors
	function verify_zone_file($zone_name, $file_name, &$error_str = '') {
		$cmd = "/usr/sbin/named-checkzone $zone_name $file_name";
		exec($cmd, $out, $exit);

		$error_str = join("\n", $out);

		// Unix: Zero = good, Non-zero = bad
		$ret = !$exit;

		return $ret;
	}

	function write_scratch_zone($zone_name, $obj) {
		$str       = $this->create_zone_str($obj);
		$file_name = $this->get_scratch_zone_name($zone_name);
		$bytes     = file_put_contents($file_name, $str);

		$ok = $this->verify_zone_file($zone_name, $file_name, $err_str);

		//k("Wrote $bytes bytes to $file_name");

		return $ok;
	}

	function get_scratch_zone_name($zone_name) {
		$date_str  = date("Y-m-d");
		$file_name = $this->scratch_dir . "/$zone_name-$date_str.dns";

		return $file_name;
	}

	function get_diff($zone_name, $html = 1) {
		$info = $this->get_zone_info($zone_name);

		$scratch = $info['scratch_file'];
		$live    = $info['file'];

		$cmd = "/usr/bin/diff -wu '$live' '$scratch'";
		exec($cmd, $out, $exit);

		if ($html) {
			foreach ($out as &$line) {
				if (str_starts_with($line, "+")) {
					$line = "<div class=\"line_add\">$line</div>";
				} elseif (str_starts_with($line, "-")) {
					$line = "<div class=\"line_delete\">$line</div>";
				} else {
					$line = "<div class=\"d-inline-block\">$line</div>";
				}
			}
		}

		$ret = join("\n", $out);

		return $ret;
	}

}

////////////////////////////////////////////////////////////////////
// Global functions outside of the class
////////////////////////////////////////////////////////////////////

function qw($str,$return_hash = false) {
	$str = trim($str);

	// Word characters are any printable char
	$words = str_word_count($str,1,"!\"#$%&'()*+,./0123456789-:;<=>?@[\]^_`{|}~");

	if ($return_hash) {
		$ret = array();
		$num = sizeof($words);

		// Odd number of elements, can't build a hash
		if ($num % 2 == 1) {
			return array();
		} else {
			// Loop over each word and build a key/value hash
			for ($i = 0; $i < $num; $i += 2) {
				$key   = $words[$i];
				$value = $words[$i + 1];

				$ret[$key] = $value;
			}

			return $ret;
		}
	} else {
		return $words;
	}
}

// Stopwatch function: returns milliseconds
function sw() {
	static $start = null;

	if (!$start) {
		$start = hrtime(1);
	} else {
		$ret   = (hrtime(1) - $start) / 1000000;
		$start = null; // Reset the start time
		return $ret;
	}
}

////////////////////////////////////////////////////////

// vim: tabstop=4 shiftwidth=4 noexpandtab autoindent softtabstop=4

