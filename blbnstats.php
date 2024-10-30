<?php
/**
 * @package BlockedBannersStats
 */
/*
Plugin Name: Blocked Banners Stats
Description: Count visitors who has Ad-blockers installed
Version: 0.0.1
Author: Max Deshkevich <maxd@firstbeatmedia.com>
Text Domain: blbnstats
* License: GPLv2
*
*  Copyright 2014 Content.ad (info@content.ad)
*
*  This program is free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License, version 2, as
*  published by the Free Software Foundation.
*
*  This program is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  You should have received a copy of the GNU General Public License
*  along with this program; if not, write to the Free Software
*  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

define( 'BLBNSTATS_VERSION', '0.0.1' );
define( 'BLBNSTATS_JS_VERSION', '0.0.1' );
define( 'BLBNSTATS__PLUGIN_URL', plugin_dir_url(__FILE__));
define( 'BLBNSTATS__PLUGIN_DIR', plugin_dir_path(__FILE__));

if( !class_exists('Net_GeoIP')) require_once('GeoIP.php');
require_once('blbnstats.class.php');
BlockedBannersStats::init();

register_activation_hook( __FILE__, array('BlockedBannersStats', 'activation'));

if( is_admin() )
{
	add_action('admin_menu', function(){
		add_management_page('Blocked Banners Stats', 'Blocked Banners', 'manage_options', 'blbnstats-counter', 
			array('BlockedBannersStats','render_stats_page'));
	});

	wp_register_script('blbnstats-charts-js', plugins_url('js/Chart.min.js', __FILE__), array(), BLBNSTATS_JS_VERSION);
	wp_enqueue_script('blbnstats-charts-js');

	add_filter("plugin_action_links_".plugin_basename(__FILE__), function ($links) {
		$settings_link = '<a href="tools.php?page=blbnstats-counter">Stats</a>';
		array_unshift($links, $settings_link);
		return $links;
	} );
}
else
{
	add_action('wp_ajax_blbnstats_hit', $fAdblockCount);
	add_action('wp_ajax_nopriv_blbnstats_hit', $fAdblockCount);

	wp_register_script('blbnstats-probe-js', plugins_url('js/banner.js', __FILE__), array('jquery'), BLBNSTATS_JS_VERSION);
	wp_enqueue_script('blbnstats-probe-js');
	wp_register_script('blbnstats-probe-js2', plugins_url('js/adsbygoogle.js', __FILE__), array('jquery'), BLBNSTATS_JS_VERSION);
	wp_enqueue_script('blbnstats-probe-js2');

	wp_localize_script('jquery', 'BlockedBannersStatsAjax', array('ajaxurl' => admin_url('admin-ajax.php')));

	add_action( 'wp_head', function() {
		?><script>jQuery(document).ready(function($){

				if (document.cookie.match(/__blbnsts_s=1/)) {
					//
				} else {
					var isBlocked = false;
					if( $.blbnStatsProbeJs==undefined ) isBlocked = true;
					if( $.blbnStatsProbeJs2==undefined ) isBlocked = true;
					jQuery.post(
						'<?=admin_url('admin-ajax.php')?>', {
							'action': 'blbnstats_hit',
							'data': isBlocked },
						function(response) {
							document.cookie = "__blbnsts_s=1; path=/";
						} );
				}
			});</script><?
	});
}