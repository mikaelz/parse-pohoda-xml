<?php

/**
 * Import list of stock items from Pohoda
 * http://www.stormware.cz/xml/dokladyexport.aspx#Zásoby
 */

set_time_limit(0);
error_reporting(-1);

$xml = dirname(__FILE__).'/stock.xml';
$content = file_get_contents("php://input");
file_put_contents($xml, $content);

if (!is_file($xml)) {
    echo 'No XML.';
    return;
}

if (!empty($content)) {

    if (!is_dir(WB_PATH.'/media')) {
        mkdir(WB_PATH.'/media');
    }
    if (!is_dir(WB_PATH.'/media/eshop')) {
        mkdir(WB_PATH.'/media/eshop');
    }
    if (!is_dir(WB_PATH.'/media/eshop/images')) {
        mkdir(WB_PATH.'/media/eshop/images');
    }

    $dom = new DomDocument();
    $dom->load($xml);
    $xpath = new DOMXPath($dom);
    $roots = $xpath->query('//rsp:responsePackItem/lStk:listStock');
    if ($roots->length > 0) {
        for ($i = 0; $i < $roots->length; $i++) {
            $products = $xpath->query('./lStk:stock', $roots->item($i));
            for ($j = 0; $j < $products->length; $j++) {
                $node = $products->item($j);
                $id = $xpath->query('./stk:stockHeader/stk:id', $node)->item(0)->nodeValue;
                $name = trim($xpath->query('./stk:stockHeader/stk:name', $node)->item(0)->nodeValue);
                $code = trim(@$xpath->query('./stk:stockHeader/stk:code', $node)->item(0)->nodeValue);
                $ean = @$xpath->query('./stk:stockHeader/stk:EAN', $node)->item(0)->nodeValue;
                $unit = @$xpath->query('./stk:stockHeader/stk:unit', $node)->item(0)->nodeValue;
                $mass = @$xpath->query('./stk:stockHeader/stk:mass', $node)->item(0)->nodeValue;
                $quantity = @$xpath->query('./stk:stockHeader/stk:count', $node)->item(0)->nodeValue;
                $sellingPrice = $xpath->query('./stk:stockHeader/stk:sellingPrice', $node)->item(0)->nodeValue;
                $description = @$xpath->query('./stk:stockHeader/stk:description', $node)->item(0)->nodeValue;

                $categoryIds = array();
                $categories = $xpath->query('./stk:stockHeader/stk:categories/stk:idCategory', $node);
                if ($categories) {
                    for ($k = 0; $k < $categories->length; $k++) {
                        $category = (int) $categories->item($k)->nodeValue;
                        if ($category > 0) {
                            $categoryIds[] = $category;
                        }
                    }
                }

                $parameters = array();
                $params = $xpath->query('./stk:stockHeader/stk:intParameters/stk:intParameter', $node);
                if ($params->length > 0) {
                    for ($k = 0; $k < $params->length; $k++) {
                        $param_name = trim($xpath->query('./stk:intParameterName', $params->item($k))->item(0)->nodeValue);
                        $param_value = trim($xpath->query('./stk:intParameterValues/stk:intParameterValue/stk:parameterValue', $params->item($k))->item(0)->nodeValue);
                        if (!empty($param_value)) {
                            $param_value = $param_value == 'true' ? 'áno' : $param_value;
                            $param_value = $param_value == 'false' ? 'nie' : $param_value;
                            $parameters[$param_name] = $param_value;
                        }
                    }
                }

                $prices = array();
                $price_items = $xpath->query('./stk:stockPriceItem/stk:stockPrice', $node);
                if ($price_items->length > 0) {
                    for ($k = 0; $k < $price_items->length; $k++) {
                        $price_type = $xpath->query('./typ:ids', $price_items->item($k))->item(0)->nodeValue;
                        $price_value = $xpath->query('./typ:price', $price_items->item($k))->item(0)->nodeValue;
                        if (!empty($price_value)) {
                            $prices[$price_type] = $price_value;
                        }
                    }
                }

                $related = array();
                $related_items = $xpath->query('./stk:stockHeader/stk:relatedStocks/stk:idStocks', $node);
                if ($related_items->length > 0) {
                    for ($k = 0; $k < $related_items->length; $k++) {
                        $related_id = $xpath->query('./typ:stockItem/typ:id', $related_items->item($k))->item(0)->nodeValue;
                        if (!empty($related_id)) {
                            $related[] = $related_id;
                        }
                    }
                }

                if (empty($name)) {
                    continue;
                }

                if (empty($categoryIds[0])) {
                    continue;
                }

                $page_ids = array();
                $q = "SELECT p.page_id, p2.page_id as is_parent
                    FROM ".TABLE_PREFIX."pages p
                    LEFT JOIN ".TABLE_PREFIX."pages p2 ON p.page_id = p2.parent
                    WHERE p.cat_id IN (".implode(',', $categoryIds).")";
                $q = $database->query($q);
                while ($row = $q->fetchRow()) {
                    // Get only pages which are not parents
                    if (is_null($row['is_parent'])) {
                        $page_ids[] = $row['page_id'];
                    }
                }

                if (!is_array($page_ids)) {
                    continue;
                }

                foreach ($page_ids as $page_id) {
                    if (1 > $page_id) {
                        continue;
                    }

                    // Check if already exists
                    $q = "SELECT item_id FROM ".TABLE_PREFIX."mod_eshop_items WHERE page_id = $page_id AND sku = '$code' LIMIT 1";
                    $q = $database->query($q);
                    if ($q->numRows() > 0) {
                        list($item_id) = $q->fetchRow(MYSQL_NUM);
                        $update_data = array(
                            'title' => $name,
                            'sku' => $code,
                            'stock' => $quantity,
                            'ean' => $ean,
                            'price' => $sellingPrice,
                            'unit' => $unit,
                            'weight' => $mass,
                            'description' => $database->handle->real_escape_string($description),
                        );
                        $update = array();
                        foreach ($update_data as $key => $value) {
                            $update[] = "$key = '$value'";
                        }
                        if (!empty($update[0])) {
                            $q = "UPDATE ".TABLE_PREFIX."mod_eshop_items SET ".implode(',', $update)."
                                WHERE item_id = $item_id LIMIT 1";
                            $database->query($q);
                        }

                        insert_parameters($item_id, $parameters);

                        insert_related($item_id, $related);

                        insert_prices($item_id, $prices);

                        continue;
                    }

                    $insert_data = array(
                        'item_id' => $id,
                        'page_id' => $page_id,
                        'section_id' => $database->get_one("SELECT section_id FROM ".TABLE_PREFIX."sections WHERE page_id = $page_id LIMIT 1"),
                        'title' => $name,
                        'sku' => $code,
                        'stock' => $quantity,
                        'ean' => $ean,
                        'price' => $sellingPrice,
                        'unit' => $unit,
                        'weight' => $mass,
                        'description' => $database->handle->real_escape_string($description),
                    );

                    $item_id = insert_item($insert_data);

                    insert_related($item_id, $related);

                    insert_parameters($item_id, $parameters);

                    insert_prices($item_id, $prices);

                    $picture = @$xpath->query('./stk:stockHeader/stk:pictures/stk:picture[@default="true"]/stk:filepath', $node)->item(0)->nodeValue;
                    if ($item_id > 0 && !empty($picture) && is_file(WB_PATH.'/temp/images/'.$picture)) {
                        $main_image = $picture;

                        if (!is_dir(WB_PATH.'/media/eshop/images/item'.$item_id)) {
                            mkdir(WB_PATH.'/media/eshop/images/item'.$item_id);
                        }
                        if (!is_dir(WB_PATH.'/media/eshop/thumbs/item'.$item_id)) {
                            mkdir(WB_PATH.'/media/eshop/thumbs/item'.$item_id);
                        }
                        $new_file = WB_PATH.'/media/eshop/images/item'.$item_id.'/'.$main_image;

                        copy(WB_PATH.'/temp/images/'.$main_image, $new_file);

                        $thumb_destination = WB_PATH.MEDIA_DIRECTORY.'/eshop/thumbs/item'.$item_id.'/'.$main_image;

                        $fileext = pathinfo($main_image, PATHINFO_EXTENSION);
                        if ($fileext == "png") {
                            resizePNG($new_file, $thumb_destination, $resize, $resize);
                        } else {
                            make_thumb($new_file, $thumb_destination, $resize);
                        }

                        $q = "UPDATE ".TABLE_PREFIX."mod_eshop_items SET main_image = '$main_image' WHERE item_id = $item_id LIMIT 1";
                        $database->query($q);
                    }
                }
            }
        }
    }
}

function insert_item($data) {
    global $database, $module_pages_directory;

    $q = "INSERT INTO ".TABLE_PREFIX."mod_eshop_items (section_id, page_id, active, title, sku, ean, stock, price, unit, weight, description, full_desc, pohoda_id)
        VALUES ({$data['section_id']}, {$data['page_id']}, 1, '{$data['title']}', '{$data['sku']}', '{$data['ean']}', '{$data['stock']}', {$data['price']}, '{$data['unit']}', '{$data['weight']}', '{$data['description']}', '{$data['description']}', {$data['item_id']})";
    $items = $database->query($q);
    $item_id = $items->handle->insert_id;

    if (1 > $item_id) {
        return;
    }

    $item_link = $module_pages_directory.page_filename($data['title'].'-'.$item_id);
    $item_link = str_replace(PAGE_SPACER.PAGE_SPACER.PAGE_SPACER, PAGE_SPACER, $item_link);

    $q = "UPDATE ".TABLE_PREFIX."mod_eshop_items SET link = '$item_link' WHERE item_id = $item_id LIMIT 1";
    $database->query($q);

    return $item_id;
}

function insert_parameters($item_id, $parameters) {
    global $database;
    if ($item_id > 0 && isset($parameters) && is_array($parameters)) {
        foreach ($parameters as $param_name => $param_value) {
            if (!empty($param_name) && !empty($param_value)) {
                $slug = page_filename($param_name);
                $q = "INSERT INTO ".TABLE_PREFIX."mod_eshop_item_parameter (item_id, name, slug, value)
                    VALUES ($item_id, '$param_name', '$slug', '$param_value')
                    ON DUPLICATE KEY UPDATE value = '$param_value'";
                $database->query($q);
            }
        }
    }
}

function insert_prices($item_id, $prices) {
    global $database;
    if ($item_id > 0 && !empty($prices) && is_array($prices)) {
        foreach ($prices as $price_type => $price) {
            if (!empty($price_type) && !empty($price)) {
                $q = "INSERT INTO ".TABLE_PREFIX."mod_eshop_item_price (item_id, price_type, price)
                    VALUES ($item_id, '$price_type', '$price')
                    ON DUPLICATE KEY UPDATE price = '$price'";
                $database->query($q);
            }
        }
    }
}

function insert_related($item_id, $related) {
    global $database;
    if ($item_id > 0 && is_array($related)) {
        foreach ($related as $related_id) {
            if (!empty($related_id)) {
                $q = "INSERT INTO ".TABLE_PREFIX."mod_eshop_item_related (item_id, related_id)
                    VALUES ($item_id, $related_id)
                    ON DUPLICATE KEY UPDATE related_id = $related_id";
                $database->query($q);
            }
        }
    }
}


