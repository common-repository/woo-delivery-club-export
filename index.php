<?php
/*
Plugin Name: Woo Delivery-club Export
Version: 1.1.2
Description: Unloading the woocommerce menu in xml format Delivery Club
*/

require_once ('libs/makexml.php');
require_once ('libs/AIO/aio.v0.inc');

register_activation_hook( __FILE__, 'WDCExportActivate' );
register_deactivation_hook( __FILE__, function(){wp_clear_scheduled_hook('WDCExportXml');} );

$wpUploadDir = wp_upload_dir();

function WDCExportActivate() 
{
	add_option('cronDCXML', 'checked');
	update_option('cronDCXML', 'checked');
	wp_schedule_single_event(WDCExportGetTime(), 'WDCExportXml', array(true));
	WDCExportUpdateXML();
}

add_action('admin_menu', 'WDCExportMenu');

function WDCExportMenu()
{
	add_menu_page('Woo Delivery-club Export', 'Woo Delivery-club Export', 8, __FILE__, 'WDCExportStart');
}

function WDCExportStart() {
	global $wpUploadDir;

	$postData = WDCExportFilter($_POST);
	
	if ($postData['updateXml']) {
		WDCExportUpdateXML();
	}

	if ($postData['form']) {
		update_option('cronDCXML', $postData['cronDCXML']);
		WDCExportUpdateXML(true);
	}

	if (get_option('cronDCXML') != 'checked') {
		wp_clear_scheduled_hook('WDCExportXml');
	}

	echo '
	<head>
	<link rel="stylesheet" type="text/css" href="' . plugins_url('css/style.css', __FILE__) . '">
	</head>
	';

	echo '<img style="width: 100%;margin-left: -10px;" src="' . plugins_url('image/header.jpg', __FILE__) . '">';
	echo '<div style="text-align:center;">
	<h1>Delivery Club Woocommerce Export</h1>';
	echo '<form method="post">
	<input class="sbm-btn" type="submit" name="updateXml" value="Обновить XML">
	</form>	
	<form method="post">
	<input style="margin: 0;" onchange="jQuery(this).parent().submit();" id="cronDCXML" type="checkbox" name="cronDCXML" ' . get_option('cronDCXML') . ' value="checked"><label for="cronDCXML">Автоматическое обновление</label>
	<input type="hidden" name="form" value="send">
	</form>

	<p>Сгенерированный файл можно получить по адресу:  <a href="' . $wpUploadDir['baseurl'] . '/deliveryclub.xml">' . $wpUploadDir['baseurl'] . '/deliveryclub.xml</a></p>

	<p>По всем вопросам обращайтесь в наш <a href="tg://t.me/joinchat/BzRWmklC-XYjmuwNZYV8kA">Telegram Чат</a></p>
	
	</div>';
}

function WDCExportUpdateXML($cronDCXML = false)
{
	global $wpUploadDir;

	$DCExportXML = new WooDeliveryClubExport();
	$data = $DCExportXML->generateData();
	WDCExportcheckForEmptiness($data[0], $data[1]);
	WDCExportcleanCats($data[0], $data[1]);
	$dom = WDCExportmakeXML($data[0], $data[1], 'string');
	$xml = $dom->saveXML();
	$xmlFile = fopen($wpUploadDir['basedir'] . '/deliveryclub.xml', "w+");
	fwrite($xmlFile, $xml);
	fclose($xmlFile);

	if (get_option('cronDCXML') == 'checked' && $cronDCXML) {
		wp_schedule_single_event(WDCExportGetTime(), 'WDCExportXml', array(true));
	}
}

function WDCExportGetTime()
{
	return time() + 5400;
}

function WDCExportFilter($check_array)
{
	$vowels = array('"',"'",'«','»','<','>','`','\\','\`');
	$keys = array_keys($check_array);
	$d = count($check_array);

	for($i = 0; $i < $d; $i++) {
		$check_array[$keys[$i]] = str_replace($vowels, '', $check_array[$keys[$i]]);
	}

	return $check_array;
}

add_action('WDCExportXml', 'WDCExportUpdateXML', 10, 1);