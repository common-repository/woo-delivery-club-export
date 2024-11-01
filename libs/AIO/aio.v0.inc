<?php

$_AIO['version'] = '14.03 #73';

mb_internal_encoding("UTF-8");

if (!function_exists('WDCExportmb_ucfirst') && function_exists('mb_substr')) {
	function WDCExportmb_ucfirst($string) {
		$string = mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);

		return $string;
	}
}

/**
 * @param $string
 * @param bool $needFormat
 * @return string
 */
function WDCExportCapsLock($string, $needFormat = true)
{
	if ($needFormat === true) {
		$string = WDCExportmb_ucfirst(mb_strtolower($string));
	}

	$substrings = explode(' ', $string);
	$outputSubstrings = array();

	foreach ($substrings as $key => $substring) {
		$engReplaceSymbol = array('a', 'e', 'c');
		$rusReplaceSymbol = array('а', 'е', 'с');
		$substringTemp = str_replace($engReplaceSymbol, $rusReplaceSymbol, $substring);

		if (preg_match('/безалк|б\/а|б\\\а|bbq/ui', $substringTemp)) {
			$substring = mb_strtoupper($substring);
		}

		if (isset($substrings[$key - 1]) && strpos($substrings[$key - 1], '.') !== false) {
			$substring = WDCExportmb_ucfirst($substring);
		}

		if (!empty($substring) && preg_match('/\"|\'|«|&quot;/u', $substring)) {
			$substringTemp = str_replace(array('"',"'",'«','&quot;'),'', $substring);
			$substring = str_replace($substringTemp, WDCExportmb_ucfirst($substringTemp), $substring);
		}

		$outputSubstrings[] = $substring;
	}

	return implode(' ', $outputSubstrings);
}

/**
 * @param $categories
 * @param $products
 * @return void
 * @throws Exception
 */
function WDCExportcleanCats(&$categories, &$products)
{
	foreach ($categories as $categoryId => $category) {
		if (empty($category[0])) {
			unset($categories[$categoryId]);
			continue;
		}

		if ($category[2] != -1 && empty($categories[$category[2]])) {
			unset($categories[$categoryId]);
			continue;
		}

		$categories[$categoryId][9] = 0;
		$categories[$categoryId][10] = 0;
	}

	foreach ($categories as $categoryId => $category) {
		if ($category[2] != -1) {
			$categories[$category[2]][9]++;
		}
	}

	foreach ($products as $productId => $product) {
		if (empty($categories[$product[0]])) {
			unset($products[$productId]);
			continue;
		}

		if (WDCExportisInvalidPrice($product)) {
			unset($products[$productId]);
		}

		$categories[$product[0]][10]++;
	}

	foreach ($categories as $categoryId => $category) {
		if ($category[9] === 0 && $category[10] === 0) {
			if ($category[2] != -1 && $categories[$category[2]][9] === 0) {
				unset($categories[$category[2]]);
			}

			unset($categories[$categoryId]);
		}
	}

	WDCExportCheckForEmptiness($categories, $products);
}

# : File operations :
function WDCExportmakeXML($cat, $prod, $values = 'real')
{
	$implementation = new DOMImplementation();
	$dtd = $implementation->createDocumentType('dc_catalog', null, 'https://www.delivery-club.ru/xml/dc.dtd');
	$xml = $implementation->createDocument('', '', $dtd);
	$xml->encoding = 'utf-8';
	$xml->formatOutput = true ;

	$xml_root = $xml->createElement('dc_catalog');
	$xml_root->setAttribute("last_update", date('Y-m-d H:i'));
	$xml->appendChild($xml_root);

	$xml_dservice = $xml->createElement('delivery_service');
	$xml_root->appendChild($xml_dservice);
	$xml_categories = $xml->createElement('categories');
	$xml_dservice->appendChild($xml_categories);

	foreach ($cat as $key => $category) {
		$xml_category = $xml->createElement('category');

		switch ($values) {
			case 'real' :
				$xml_category->setAttribute("id", $key + 1);
				break;
			case 'string' :
				$xml_category->setAttribute("id", strval($key));
				break;
		}

		if (isset($category[2])) {
			switch ($values) {
				case 'real':
					if ($category[2] != -1) {
						$xml_category->setAttribute("parentId", $category[2] + 1);
					}
					break;
				case 'string':
					if (($category[2] != null) && ($category[2] != -1)) {
						$xml_category->setAttribute("parentId", strval($category[2])); 
					}
					break;
			}
		}

		if (isset($category['delivery_type'])) {
			switch ($category['delivery_type']) {
				case 'delivery, takeaway' :
					$xml_category->setAttribute("delivery_type", "delivery, takeaway");
					break;
				case 'delivery' :
					$xml_category->setAttribute("delivery_type", "delivery");
					break;
				case 'takeaway' :
					$xml_category->setAttribute("delivery_type", "takeaway");
					break;
			}
		}

		$xml_category->nodeValue = WDCExportCapsLock($category[0]);
		$xml_categories->appendChild($xml_category);
	}

	$xml_products = $xml->createElement('products');
	$xml_dservice->appendChild($xml_products);

	$i = 1;

	foreach ($prod as $iid => $item) {
		$xml_product = $xml->createElement('product');

		switch ($values) {
			case 'real':
				$xml_product->setAttribute("id", $i);
				break;
			case 'string':
				$xml_product->setAttribute("id", $iid);
				break;
		}

		if (isset($item['delivery_type'])) {
			switch ($item['delivery_type']) {
				case 'delivery, takeaway' :
					$xml_product->setAttribute("delivery_type", "delivery, takeaway");
					break;
				case 'delivery' :
					$xml_product->setAttribute("delivery_type", "delivery");
					break;
				case 'takeaway' :
					$xml_product->setAttribute("delivery_type", "takeaway");
					break;
			}
		}

		$xml_products->appendChild($xml_product);

		$xml_product_cid = $xml->createElement('category_id');

		switch ($values) {
			case 'real' :
				$xml_product_cid->nodeValue = $item[0] + 1; 
				break;
			case 'string':
				$xml_product_cid->nodeValue = strval($item[0]); 
				break;
		}

		$xml_product->appendChild($xml_product_cid);

		$xml_product_name = $xml->createElement('name');
		$item[1] = WDCExportCapsLock($item[1]);
		$item[1] = preg_replace('/,([a-zа-яеё])/ui', ', $1', $item[1]);
		$item[1] = preg_replace('/(\d)([а-яеё])/ui', '$1&#160;$2', $item[1]);
		$item[1] = str_replace('ё', 'е', $item[1]);

		$xml_product_name->nodeValue = $item[1];
		$xml_product->appendChild($xml_product_name);

		if (!empty($item[2]) && !empty($item[2])) {
			$xml_product_desc = $xml->createElement('description');
			$item[2] = preg_replace('/,([a-zа-яеё])/ui', ', $1', $item[2]);
			$item[2] = preg_replace('/(\d)([а-яеё])/ui', '$1 $2', $item[2]);

			$xml_product_desc->nodeValue = $item[2];
			$xml_product->appendChild($xml_product_desc);
		}

		$xml_product_cost = $xml->createElement('price');
		$xml_product_cost->nodeValue = $item[3];
		$xml_product->appendChild($xml_product_cost);

		if ($item[4] != null) {
			$pictureUrl = parse_url($item[4]);

			if (preg_match('/http/',$pictureUrl['scheme'])&&!empty($pictureUrl['path'])) {
				$xml_product_img = $xml->createElement('picture');
				$url = $item[4];

				$xml_product_img->nodeValue = $url;
				$xml_product->appendChild($xml_product_img);
			}
		}

		if (isset($item['byWeight'])) {
			if ( ($item['byWeight'] != null) and (!empty($item['byWeight'])) ) {
				$xml_product_byw = $xml->createElement('byWeight');
				$xml_product_byw->nodeValue = $item['byWeight'];
				$xml_product->appendChild($xml_product_byw);
			}
		}

		if (isset($item[6])) {
			if ($item[6] != null) {
				$xml_ingredients = $xml->createElement('ingredients');
				$xml_product->appendChild($xml_ingredients);

				foreach ($item[6] as $var) {
					$xml_ingredient = $xml->createElement('ingredient');

					if (isset($var['id'])) {
						$xml_ingredient->setAttribute( 'id', $var['id'] );
					}

					$xml_ingredient->setAttribute("title", WDCExportCapsLock($var[0]));
					$xml_ingredient->setAttribute("price", $var[1]);
					$xml_ingredients->appendChild($xml_ingredient);
				}
			}
		}

		if (isset($item[5])) {
			if ($item[5] != null) {
				$xml_variants = $xml->createElement('variants');
				$xml_product->appendChild($xml_variants);

				foreach ($item[5] as $v_group){
					if (isset($v_group['variants'])) {
						$xml_v_group = $xml->createElement('variants_group');
						$xml_v_group->setAttribute("title", WDCExportCapsLock($v_group['title']));
						$xml_variants->appendChild($xml_v_group);

						foreach ($v_group['variants'] as $var) {
							$xml_variant = $xml->createElement('variant');
							$xml_variant->setAttribute("title", $var['title']);

							if (isset($var['price'])) {
								$xml_variant->setAttribute('price', $var['price']);
							}

							if (isset($var['id'])) {
								$xml_variant->setAttribute('id', $var['id']);
							}

							$xml_v_group->appendChild($xml_variant);
						}
					}
				}
			}
		}

		if (isset($item['weight'])) {
			$xml_weight = $xml->createElement('weight');
			$xml_weight->nodeValue = WDCExportTransformToInventoryFormat($item['weight']);
			$xml_product->appendChild($xml_weight);
		}

		if (isset($item['energy'])) {
			$xml_energy = $xml->createElement('energy_value');
			$xml_energy->nodeValue = WDCExportTransformToInventoryFormat($item['energy']);
			$xml_product->appendChild($xml_energy);
		}

		if (isset($item['volume'])) {
			$xml_volume = $xml->createElement('volume');
			$xml_volume->nodeValue = WDCExportTransformToInventoryFormat($item['volume']);
			$xml_product->appendChild($xml_volume);
		}
		
		$i++;
	}

	$xml->xmlVersion = "1.0";

	return $xml;
}

/**
 * @param mixed[] $product
 * @return boolean
 */
function WDCExportisInvalidPrice($product)
{
	if (empty($product[3]) || $product[3] > 100000 || $product[3] < 1) {
		return true;
	}

	return false;
}

function WDCExportforceSave($file, $content_type = 'text/xml') {
	global $parser_name;

	if ((isset($file)) && (file_exists($file))) {
		header("Content-length: ".filesize($file));
		header('Content-Type: ' . $content_type);
		header('Content-Disposition: attachment; filename="' . $parser_name . '.xml' . '"');
		readfile("$file");
	}
}

/**
 * @param mixed[] $categories
 * @param mixed[] $products
 * @throws Exception
 */
function WDCExportCheckForEmptiness($categories, $products)
{
	if (empty($categories)) {
		throw new UnableToBuildMenuException('Categories list is empty');
	}

	if (empty($products)) {
		throw new UnableToBuildMenuException('Products list is empty');
	}
}

/**
 * @param string $string
 * @return string
 */
function WDCExportTransformToInventoryFormat($string)
{
	return
		trim(
			preg_replace(
				'/[^\d|\\|\/|,]/ui',
				'',
				str_replace(
					'.',
					',',
					$string
				)
			),
			', '
		);
}

/**
 * @param mixed[] $context
 * @return string
 */
function WDCExportAssembleInventoryProductField($context)
{
	return
		strtr(
			'{baseName} {value} {unit}',
			$context
		);
}