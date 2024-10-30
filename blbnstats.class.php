<?php

class BlockedBannersStats
{
	/**
	 * @var string @see init()
	 */
	private static $tableName = 'adblockstats_stats';
	private static $initiated=false;

	/**
	 * @var Net_GeoIP
	 */
	private static $geoIp;
	
	public static function init()
	{
		/** @var $wpdb wpdb */
		global $wpdb;
		
		if( self::$initiated ) return;

		self::$tableName = $wpdb->prefix.self::$tableName;

		$fname_dat_file = BLBNSTATS__PLUGIN_DIR.'data/GeoIP.dat';
		self::$geoIp = Net_GeoIP::getInstance($fname_dat_file);
		
		self::$initiated=true;
	}
	
	public static function activation()
	{
		/** @var $wpdb wpdb */
		global $wpdb;

		$table_name = self::$tableName;

		if($wpdb->get_var("SHOW TABLES LIKE $table_name") != $table_name) {

			$sql = "
			CREATE TABLE IF NOT EXISTS {$table_name} (
			  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  dt_reported datetime NOT NULL,
			  country_code char(2) NOT NULL,
			  is_blocked tinyint(1) NOT NULL,
			  is_mobile tinyint(1) DEFAULT NULL,
			  PRIMARY KEY (id),
			  KEY dt_reported (dt_reported)
			) ENGINE=InnoDB;
			";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );
		}
	}
	
	public static function countHit()
	{
		/** @var $wpdb wpdb */
		/** @var $detect Mobile_Detect */
		global $wpdb;
		global $detect;

		$ip = @$_SERVER['REMOTE_ADDR'];

		$geoip = self::$geoIp;
		$country_code = $geoip->lookupCountryCode($ip);

		if( isset($_REQUEST['data']) and $_REQUEST['data']=='true') {
			$is_blocked = 1;
		} else {
			$is_blocked = 0;
		}

		if( !empty($detect) and $detect instanceof Mobile_Detect )
		{
			$is_mobile = $detect->isMobile() or $detect->isTablet();
			$is_mobile = intval($is_mobile);
		}
		else
		{
			$is_mobile = null;
		}

		$item_insert = array(
			'dt_reported' => current_time( 'mysql',false ),
			'country_code' => $country_code,
			'is_blocked' => $is_blocked,
		);

		if( !is_null($is_mobile) ) $item_insert['is_mobile'] = $is_mobile;

		$wpdb->insert(self::$tableName,$item_insert);
		wp_die();
	}
	
	public static function render_stats_page()
	{
		/** @var $wpdb wpdb */
		global $wpdb;

		$table_name = self::$tableName;

		$tsNow = current_time( 'mysql',false );
		$tsNow_ts = current_time('timestamp', false);

		if( !empty($_REQUEST['_cc']) ) {
			$sql_filter_country = " country_code='".$_REQUEST['_cc']."' AND ";
		} else {
			$sql_filter_country = '';
		}

		$sql = "
		SELECT
			dt_reported,
			count(*) AS _count,
			FLOOR((UNIX_TIMESTAMP('{$tsNow}')-UNIX_TIMESTAMP(dt_reported))/3600) AS _diff
		FROM {$table_name}
		WHERE
			{$sql_filter_country}
			dt_reported > '{$tsNow}' - INTERVAL 1 DAY

		GROUP BY
			_diff
		";

		$rows = $wpdb->get_results($sql);

		$sql = "
		SELECT
			dt_reported,
			count(*) AS _count,
			FLOOR((UNIX_TIMESTAMP('{$tsNow}')-UNIX_TIMESTAMP(dt_reported))/86400) AS _diff
		FROM {$table_name}
		WHERE
			{$sql_filter_country}
			dt_reported > '{$tsNow}' - INTERVAL 30 DAY

		GROUP BY
			_diff
			
		ORDER BY dt_reported
	";

		$rows2 = $wpdb->get_results($sql);

		$sql = "
		SELECT
			country_code,
			count(*) AS _count
		FROM {$table_name}
		WHERE
			dt_reported > '{$tsNow}' - INTERVAL 30 DAY
			

		GROUP BY
			country_code
			
		ORDER BY _count DESC
		
		LIMIT 20
	";

		$rows3 = $wpdb->get_results($sql);


		$data1 = array_fill(0, 24, 0);

		foreach($rows as $row)
		{
			$diff = intval($row->_diff);
			$data1[$diff] = intval($row->_count);
		}

		$data1 = array_reverse($data1, true);
		$data1_labels = array();

		foreach($data1 as $k=>$v)
		{
			$ts_period_end = $tsNow_ts - ($k*3600);
			$ts_period_start = $ts_period_end - 3600;
			$label = date('H:i', $ts_period_start).'-'.date('H:i', $ts_period_end);
			$data1_labels[$k] = $label;
		}

		$data2 = array_fill(0, 30, 0);

		foreach($rows2 as $row)
		{
			$diff = intval($row->_diff);
			$data2[$diff] = intval($row->_count);
		}

		$data2 = array_reverse($data2, true);
		$data2_labels = array();

		foreach($data2 as $k=>$v)
		{
			$ts_period_end = $tsNow_ts - ($k*86400);
			$ts_period_start = $ts_period_end - 86400;
			$label = date('M,j', $ts_period_end);
			$data2_labels[$k] = $label;
		}

		$data3 = array();
		$data3_labels = array();

		foreach($rows3 as $row)
		{
			$label = Net_GeoIP::$COUNTRY_NAMES[array_search($row->country_code, Net_GeoIP::$COUNTRY_CODES)];
			if( empty($label)) $label = 'Unknown';
			$data3[] = $row->_count;
			$data3_labels[] = $label;
		}

		$arCountryNames = array();

		foreach(Net_GeoIP::$COUNTRY_NAMES as $k => $v)
		{
			$arCountryNames[Net_GeoIP::$COUNTRY_CODES[$k]] = Net_GeoIP::$COUNTRY_NAMES[$k];
		}

		asort($arCountryNames);

		?>
		<div style="clear: both;"></div>
		<h1>Visits by users having AD-blockers enabled</h1>
		<form id="frmCountrySel" action="?" method="get">
			<input type="hidden" name="page" value="<?=$_REQUEST['page']?>"/>
			Show Country: <select name="_cc" onchange="chCountrySelected(this)">

				<?foreach($arCountryNames as $k => $v):?>
					<?
					if( empty($v) )
					{
						?><option value="">ALL</option><?
					}
					else
					{
						?><option <?=(@$_REQUEST['_cc']==$k)?'selected="selected"':''?> value="<?=$k?>"><?=$v?></option><?
					}
					?>

				<?endforeach;?>
			</select><br/>
		</form>
		<h2>Past 24 hours</h2>
		<canvas id="myChart" width="800" height="300"></canvas>
		<h2>Past 30 days</h2>
		<canvas id="myChart2" width="800" height="300"></canvas>
		<h2>Per Country (last 30 days)</h2>
		<canvas id="myChart3" width="800" height="300"></canvas>


		<script>
			var ctx = document.getElementById("myChart").getContext("2d");
			var ctx2 = document.getElementById("myChart2").getContext("2d");
			var ctx3 = document.getElementById("myChart3").getContext("2d");

			var data = {
				labels: ['<?=join("','", $data1_labels)?>'],
				datasets: [
					{
						label: "1",
						fillColor: "rgba(220,220,220,0.5)",
						strokeColor: "rgba(220,220,220,0.8)",
						highlightFill: "rgba(220,220,220,0.75)",
						highlightStroke: "rgba(220,220,220,1)",
						data: [<?=join(',', $data1)?>]
					}
				]
			};

			var data2 = {
				labels: ['<?=join("','", $data2_labels)?>'],
				datasets: [
					{
						label: "2",
						fillColor: "rgba(220,220,220,0.5)",
						strokeColor: "rgba(220,220,220,0.8)",
						highlightFill: "rgba(220,220,220,0.75)",
						highlightStroke: "rgba(220,220,220,1)",
						data: [<?=join(',', $data2)?>]
					}
				]
			};

			var data3 = {
				labels: ['<?=join("','", $data3_labels)?>'],
				datasets: [
					{
						label: "3",
						fillColor: "rgba(220,220,220,0.5)",
						strokeColor: "rgba(220,220,220,0.8)",
						highlightFill: "rgba(220,220,220,0.75)",
						highlightStroke: "rgba(220,220,220,1)",
						data: [<?=join(',', $data3)?>]
					}
				]
			};

			var options = {};

			var myBarChart = new Chart(ctx).Bar(data, options);
			var myBarChart2 = new Chart(ctx2).Bar(data2, options);
			var myBarChart3 = new Chart(ctx3).Bar(data3, options);


			function chCountrySelected(slcChanged) {
				document.getElementById('frmCountrySel').submit();
			}

		</script>
	<?
	}
}