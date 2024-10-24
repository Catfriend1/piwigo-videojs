<?php
/***********************************************
* File      :   admin_batchmanager.php
* Project   :   piwigo-videojs
* Descr     :   handle batch manager
*
* Created   :   4.06.2013
*
* Copyright 2012-2024 <xbgmsharp@gmail.com>
*
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
************************************************/

// Check whether we are indeed included by Piwigo.
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

// Hook to add a new filter in the batch mode
add_event_handler('get_batch_manager_prefilters', 'vjs_get_batch_manager_prefilters');
function vjs_get_batch_manager_prefilters($prefilters)
{
	$prefilters[] = array('ID' => 'videojs0', 'NAME' => l10n('All videos'));
	$prefilters[] = array('ID' => 'videojs1', 'NAME' => l10n('All videos with poster'));
	$prefilters[] = array('ID' => 'videojs2', 'NAME' => l10n('All videos without poster'));
	return $prefilters;
}

// Hook to perfom the filter in the batch mode
add_event_handler('perform_batch_manager_prefilters', 'vjs_perform_batch_manager_prefilters', 50, 2);
function vjs_perform_batch_manager_prefilters($filter_sets, $prefilter)
{
	if ($prefilter==="videojs0")
		$filter = "";
	else if ($prefilter==="videojs1")
		$filter = "AND `representative_ext` IS NOT NULL";
	else if ($prefilter==="videojs2")
		$filter = "AND `representative_ext` IS NULL";

	if ( isset($filter) )
	{
		$query = "SELECT id FROM ".IMAGES_TABLE." WHERE ".SQL_VIDEOS." ".$filter;
		$filter_sets[] = array_from_query($query, 'id');
	}
	return $filter_sets;
}

// Hook to show action when selected
add_event_handler('loc_end_element_set_global', 'vjs_loc_end_element_set_global');
function vjs_loc_end_element_set_global()
{
	global $template;
	$template->append('element_set_global_plugins_actions',
		array('ID' => 'videojs', 'NAME'=>l10n('Videos'), 'CONTENT' => '
    <legend>Metadata</legend>
    <ul>
      <li>
	<label><input type="checkbox" name="vjs_metadata" value="1" checked="checked" /> filesize, width, height, latitude, longitude, date_creation, rotation</label>
	<br/><small>Will overwrite the information in the database with the metadata from the video.</small>
	<br/><small><strong>Requires <a href="https://github.com/xbgmsharp/piwigo-videojs/wiki/How-to-add-videos#external-tools" target="_blank">\'MediaInfo\' or \'ffprobe\' or \'Exiftool\'</a> to be installed.</strong></small>
      </li>
    </ul>
    <legend>Poster</legend>
    <ul>
      <li>
	<label><input type="checkbox" name="vjs_poster" value="1" checked="checked" /> Create a poster at position in second:</label>
	<!-- <input type="range" name="vjs_postersec" value="4" min="0" max="60" step="1"/> -->
	<input type="text" name="vjs_postersec" value="4" size="2" required/>
	<br/><small>Create a poster from the video at specify position.</small>
	<br/><small><strong>Requires <a href="https://github.com/xbgmsharp/piwigo-videojs/wiki/How-to-add-videos#external-tools" target="_blank">\'FFmpeg\'</a> to be installed.</strong></small>
      </li>
      <li>
	<label><input type="checkbox" name="vjs_posteroverwrite" value="1" checked="checked"> Overwrite existing posters</label>
	<br/><small>Overwrite existing thumbnails with new ones. If uncheck it should only run for newly added video.</small>
      </li>
      <li>
	<label><span class="property">Output format : </span></label>
	<label><input type="radio" name="vjs_output" value="jpg" checked="checked"/> JPG</label>
	<label><input type="radio" name="vjs_output" value="png" /> PNG</label>
	<br/><small>Select the output format for the poster and thumbnail.</small>
      </li>
      <li>
	<label><input type="checkbox" name="vjs_posteroverlay" value="1" /> Add film effect</label>
	<br/><small>Apply an overlay on the poster creation.</small>
      </li>
    </ul>
    <legend>Thumbnail</legend>
    <ul>
      <li>
	<label><input type="checkbox" name="vjs_thumb" value="1" /> Create a thumbnail at every seconds:</label>
	<!-- <input type="range" name="vjs_thumbsec" value="5" min="0" max="60" step="1"/> -->
	<input type="text" name="vjs_thumbsec" value="5" size="2" required/>
	<br/><small>Create a thumbnail every x seconds. <strong>Use by the videoJS plugin thumbnail</strong>.</small>
      </li>
      <li>
	<label>Size of the thumbnail:</label>
	<input type="text" name="vjs_thumbsize" value="120x68" size="5" placeholder="120x68" required/>
	<br/><small>Size in pixel, keep it small, default is fine, Youtube use 190x68.</small>
      </li>
    </ul>
'));
}

// Hook to perform the action on in global mode
add_event_handler('element_set_global_action', 'vjs_element_set_global_action', 50, 2);
function vjs_element_set_global_action($action, $collection)
{
	if ($action!=="videojs")
		return;

	global $page, $conf, $prefixeTable;

	$query = "SELECT `id`, `file`, `path`
			FROM ".IMAGES_TABLE."
			WHERE id IN (".implode(',',$collection).")";

	// Generate default value
	$sync_options = array(
	    'mediainfo'         => 'mediainfo',
	    'ffmpeg'            => 'ffmpeg',
	    'exiftool'          => 'exiftool',
	    'ffprobe'           => 'ffprobe',
	    'metadata'          => true,
	    'poster'            => true,
	    'postersec'         => 4,
	    'output'            => 'jpg',
	    'posteroverlay'     => false,
	    'posteroverwrite'   => true,
	    'thumb'             => false,
	    'thumbsec'          => 5,
	    'thumbsize'         => "120x68",
	    'simulate'          => true,
	    'cat_id'            => 0,
	    'subcats_included'  => true,
	);

	if(isset($_POST['submit']))
	{
	    // Override default value from the form
	    $sync_options_form = array(
	        'metadata'          => isset($_POST['vjs_metadata']),
	        'poster'            => isset($_POST['vjs_poster']),
	        'postersec'         => $_POST['vjs_postersec'],
	        'output'            => $_POST['vjs_output'],
	        'posteroverlay'     => isset($_POST['vjs_posteroverlay']),
	        'posteroverwrite'   => isset($_POST['vjs_posteroverwrite']),
	        'thumb'             => isset($_POST['vjs_thumb']),
	        'thumbsec'          => $_POST['vjs_thumbsec'],
	        'thumbsize'         => $_POST['vjs_thumbsize'],
	        'simulate'          => false,
	    );

	    // Merge default value with user data from the form
	    $sync_options = array_merge($sync_options, $sync_options_form);
	}

	// Do the work, share with admin sync and photo
	require_once(dirname(__FILE__).'/../include/function_sync2.php');

	$page['errors'] = $errors;
	$page['warnings'] = $warnings;
	$page['infos'] = $infos;
}
?>
