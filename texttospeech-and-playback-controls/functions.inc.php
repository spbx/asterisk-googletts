<?php

/********************************************************************
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * ==================================================================
 *
 * FreePBX Module: texttospeech
 *******************************************************************/

// Global Variables
$tts_module_name		= 'texttospeech';
$tts_context_prefix		= 'ext-' . $tts_module_name . '-';
$tts_sound_dir			= 'texttospeech/';
$tts_cache_dir			= $asterisk_conf['astvarlibdir'] . '/sounds/' . $tts_sound_dir;
$tts_legacy_fpbx		= false;
$tts_debug_enabled		= true;
$tts_debug_file			= $asterisk_conf['astlogdir'] . "/fpbx-texttospeech.log";
$tts_debug_level		= 0;		// 0 = Verbose, 1 = Notices, 2 = Warnings, 3 = Errors
$tts_auto_reload		= false;	// FOR DEBUGGING ONLY !!!!!
$tts_run_test			= false;	// FOR DEBUGGING ONLY !!!!
$tts_test_name			= 'pwtest';
$tts_test_cmd			= '/export/pbx/bin/pbxconn -f "TTS Test <421>" -s 701 421';

if (!is_dir($tts_cache_dir)) {
    mkdir($tts_cache_dir, 0755);
}

// Reset our destination base
$destidx = 1;

// Create our texttospeech class
require_once(dirname(__FILE__) . "/texttospeech.php");
$tts = New texttospeech;

// Create our debug class
require_once(dirname(__FILE__) . "/helpers/debug.helper.php");
$tts_debug = New debug;
if ($tts_debug_enabled == true) {
	$tts_dbg_prefix = "[" . date("Y-m-d H:i:s") . " | TTS-%L] ";
	$tts_debug->set( Array(	'file'			=> $tts_debug_file,
							'level'			=> $tts_debug_level,
							'prefix'		=> $tts_dbg_prefix		) );
}

$minfo = module_getinfo("core");
$core_ver = explode(".", $minfo['core']['version']);
if (count($core_ver) > 1) {
//	$tts_debug->verbose("Detected FreePBX version " . $core_ver[0] . "." . $core_ver[1]);
	if ($core_ver[0] == '2' && $core_ver[1] < 10) {
//		$tts_debug->verbose("Enabled FPBX Legacy flag");
		$tts_legacy_fpbx = true;
	}
}

// Functions
function texttospeech_debug_prefix($tts_vars) {
	global $tts_debug;

	$tts_dbg_prefix = "[" . date("Y-m-d H:i:s")
					. " | " . $tts_vars['id'] . ":" . $tts_vars['name']
					. " | " . $tts_vars['source'] . ":" . $tts_vars['engine']
					. " | TTS-%L] ";
	$tts_debug->set_prefix($tts_dbg_prefix);
}

function texttospeech_list() {
	global $db;

	$sql = "SELECT id, name FROM texttospeech ORDER BY name";

	$result = $db->getAll($sql, DB_FETCHMODE_ASSOC);
	if (DB::IsError($result)) {
		return null;
	}

	return $result;
}

function texttospeech_load_entry($id) {
	global $db;
	global $tts;

	$sql = "SELECT * FROM texttospeech WHERE id = ". sql_formattext($id);

	// Fetch configuration from database
	$cfg = $db->getRow($sql, DB_FETCHMODE_ASSOC);
	if (DB::IsError($cfg)) {
		die_freepbx(__FILE__ . " - " . __FUNCTION__ . "() - "
													. $cfg->getMessage() . " - " . $sql);
	}

	// Set our current TTS source, and then provide it with its config
	if ($tts->set_source($cfg['source'])) {
		$tts->set_source_config($cfg['source_conf']);
	}

	// Set our current TTS engine, and then provide it with its config
	if ($tts->set_engine($cfg['engine'])) {
		$tts->set_engine_config($cfg['engine_conf']);
	}

	texttospeech_debug_prefix($cfg);
	return $cfg;
}

function texttospeech_getdest($id) {
	global $tts_context_prefix;

	return array($tts_context_prefix . $id . ',s,1');
}

function texttospeech_getdestinfo($dest) {
	global $tts_context_prefix;
	global $tts_module_name;

	if (substr(trim($dest), 0, strlen($tts_context_prefix)) == $tts_context_prefix) {
		$dest_array = explode(',', $dest);
		$id = substr($dest_array[0], strlen($tts_context_prefix));

		$cfg = texttospeech_load_entry($id);
		if (!empty($cfg)) {
			return array(	'description'	=> sprintf(_("Text To Speech: %s"),$cfg['name']),
							'edit_url'		=> 'config.php?display='
										. urlencode($tts_module_name) . '&id=' . urlencode($id));
		}
	}

	return false;
}

function texttospeech_destinations() {
	global $tts_context_prefix;

	foreach(texttospeech_list() as $ent) {
		$dest = texttospeech_getdest($ent['id']);
		$destinations[] = array('destination'	=> $dest[0],
								'description'	=> $ent['name']);
	}

	return $destinations;
}

function texttospeech_get_config($pbx) {
	global $tts_context_prefix;
	global $ext;

	if ($pbx != 'asterisk') {
		// Unsupported PBX system
		dbug('Configuration requested for unknown PBX system (' . $pbx . ')');
		return;
	}

	foreach(texttospeech_list() as $ent) {
		$cfg = texttospeech_load_entry($ent['id']);
		extract($cfg);

		$context = $tts_context_prefix . $id;
		$sound_file = texttospeech_sound_file($name, true);

		$ext->add(	$context,
					's',
					'',
					new ext_noop('Text To Speech: ' . $name)	);

		$ext->add(	$context,
					's',
					'',
					new ext_agi('texttospeech.agi,' . $id)		);

		$ext->add(	$context,
					's',
					'',
					new ext_hangup()							);


	}

	return;
}

function texttospeech_text_file($name) {
	global $tts_cache_dir;

	return $tts_cache_dir . $name . ".txt";
}

function texttospeech_sound_file($name, $short = false) {
	global $tts_cache_dir;
	global $tts_sound_dir;

	if ($short) {
		return $tts_sound_dir . $name;
	}

	return $tts_cache_dir . $name . ".wav";
}

function texttospeech_process_request($_req = null) {
	global $_REQUEST;
	global $tts_debug;
	global $tts;
	global $db;

	if (!$_req) {
		$_req = $_REQUEST;
	}

	// Fetch our table colums, as they match our form variables
	$sql = 'SELECT column_name FROM information_schema.columns '
													. 'WHERE table_name="texttospeech";';

	$columns = $db->getAll($sql, DB_FETCHMODE_ASSOC);
	if (DB::IsError($columns)) {
		die_freepbx(__FILE__ . " - " . __FUNCTION__ . "() - "
											. $columns->getMessage() . " - " . $sql);
		return null;
	}

	// Move the variables we care about to our own array
	$tts_vars = array();
	foreach ($columns as $ca){ 
		$c = $ca['column_name'];

		if (isset($_req[$c])) {
			$tts_vars[$c] = $_req[$c];
		}
		else {
			$tts_vars[$c] = '';
		}

	}

	// Setup default source if there isn't any set
	if (empty($tts_vars['source'])) {
		if (!($tts_vars['source'] = $tts->default_source())) {
			reset($tts->sources);
			$tts_vars['source'] = key($tts->sources);
		}
	}

	// Setup default engine if there isn't any set
	if (empty($tts_vars['engine'])) {
		if (!($tts_vars['engine'] = $tts->default_engine())) {
			reset($tts->engines);
			$tts_vars['engine'] = key($tts->engines);
		}
	}

	// Setup our other default values, if currently empty
	$def_vals = array(	'wait_before'		=> '1',
						'wait_after'		=> '0',
						'control_esc'		=> '#',
						'control_rew'		=> '1',
						'control_pause'		=> '2',
						'control_fwd'		=> '3',
						'control_skipms'	=> '3000'	);
	foreach ($def_vals as $vn => $dv) {
		if (empty($tts_vars[$vn]) && !isset($_req[$vn])) {
			$tts_vars[$vn] = $dv;
		}
	}

	// Set TTS source and engine, then process the request
	if ($tts->set_source($tts_vars['source']) && $tts->set_engine($tts_vars['engine'])) {
		$tts->process_request($tts_vars, $_req);
	}

	texttospeech_debug_prefix($tts_vars);
	return $tts_vars;
}

function texttospeech_add_entry($tts_vars) {
	if (!is_array($tts_vars)) {
		return false;
	}

	return texttospeech_modify_entry($tts_vars, true);
}

function texttospeech_modify_entry($tts_vars, $new_entry = false) {
	global $db;
	global $tts;
	global $tts_debug;

	texttospeech_debug_prefix($tts_vars);
	if ($new_entry) {
		// Adding a new entry... make sure it's not a duplicate
		foreach (texttospeech_list() as $ent) {
			if ($tts_vars['name'] == $ent['name']) {
				echo "<script>javascript:alert( '"
							. _("An entry with that name already exists") . "' );</script>";
				return false;
			}
		}
		$tts_debug->notice("Adding new entry");
	}
	else {
		$tts_debug->notice("Saving entry changes");
	}

	// Get the source specific configuration
	if (($tts_vars['source_conf'] = $tts->get_source_config()) === false) {
		$tts_vars['source_conf'] = '';
	}

	// Get the engine specific configuration
	if (($tts_vars['engine_conf'] = $tts->get_engine_config()) === false) {
		$tts_vars['engine_conf'] = '';
	}

	// Build the set part of our SQL from our variables
	$cfg_sql = '';
	foreach ($tts_vars as $c => $v) {
		if (strlen($cfg_sql) > 0) {
			$cfg_sql .= ', ';
		}

		$cfg_sql .= $c . ' = ' . sql_formattext($v);
	}
						
	if ($new_entry) {
		// This is an add.. Insert it into the database
		$sql =	"INSERT INTO texttospeech SET " . $cfg_sql;
	}
	else {
		// This is an edit.. Update the database
		$sql = "UPDATE texttospeech SET " . $cfg_sql .
											" WHERE id = " . sql_formattext($tts_vars['id']);
	}

	$result = $db->query( $sql );
	if (DB::IsError($result)) {
		die_freepbx(__FILE__ . " - " . __FUNCTION__ . "() - "
													. $result->getMessage() . " - " . $sql);
	}

	// Check for text file and delete it if it exists
	$text_file = texttospeech_text_file($tts_vars['name']);
	if (file_exists($text_file)) {
		if (!unlink($text_file)) {
			die_freepbx(__FILE__ . " - " . __FUNCTION__
									. "() - Could not delete text file: " . $text_file);
		}
	}

	// Check for and delete sound file
	$sound_file = texttospeech_sound_file($tts_vars['name']);
	if (file_exists($sound_file)) {
		if (!unlink($sound_file)) {
			die_freepbx(__FILE__ . " - " . __FUNCTION__
									  . "() - Could not delete sound file: " . $sound_file);
		}
	}

	// Now generate new text and sound files -- if source is not dynamic
	return $tts->generate(	$text_file, $sound_file,
							$tts_vars['engine'], $tts_vars['engine_conf'],
							$tts_vars['source'], $tts_vars['source_conf']	);
}

function texttospeech_remove_entry($id) {
	global $db;
	global $tts_debug;

	$cfg = texttospeech_load_entry($id);
	if ($cfg) {
		$tts_debug->notice("Removing entry");
		// Remove text file
		$text_file = texttospeech_text_file($cfg['name']);
		if (file_exists($text_file)) {
			if (!unlink($text_file)) {
			}
		}

		// Remove sound file
		$sound_file = texttospeech_sound_file($cfg['name']);
		if (file_exists($sound_file)) {
			if (!unlink($sound_file)) {
				die_freepbx(__FILE__ . " - " . __FUNCTION__
								  	  . "() - Could not delete sound file: " . $sound_file);
				return false;
			}
		}
	}

	// Remove entry from the database
	$sql = "DELETE FROM texttospeech WHERE id = " . sql_formattext($id);

	$result = $db->query( $sql );
	if (DB::IsError($result)) {
		die_freepbx(__FILE__ . " - " . __FUNCTION__ . "() - "
													. $result->getMessage() . " - " . $sql);
		return false;
	}

	return true;
}

function texttospeech_auto_reload() {
	global $tts_auto_reload;
	global $asterisk_conf;
	global $tts_debug_enabled;

	if ($tts_debug_enabled == false || $tts_auto_reload == false) {
		return false;
	}

	exec($asterisk_conf['astvarlibdir'] . '/bin/module_admin reload', $output, $ret);
	if ($ret == 0) {
		return true;
	}

	return false;
}

function texttospeech_run_test($tts_vars) {
	global $tts_debug_enabled;
	global $tts_run_test;
	global $tts_test_cmd;
	global $tts_test_name;

	if ($tts_debug_enabled == false || $tts_run_test == false || $tts_vars['name'] != $tts_test_name) {
		return false;
	}

	echo '<h2>Notice: Running test command</h2>';

	exec($tts_test_cmd, $eout, $ret);
	return;
}
