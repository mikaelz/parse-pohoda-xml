<?php

/**
 * Import categories from Pohoda
 * https://github.com/techi602/prestashop-pohoda/blob/master/pohoda/categories.php
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

    $xml = simplexml_load_string($content);
    $ns = $xml->getNameSpaces(true);
    foreach ($xml->xpath('//lst:categoryDetail') as $categories) {
        $ctg = $categories->children($ns['ctg']);
        foreach ($ctg->category as $category) {
            insert_page(
                (int) $category->id,
                trim( $category->name ),
                1
            );
            if (isset($category->subCategories)) {
                insertSubcategory($category);
            }
        }
    }
}

function insertSubcategory($category) {
    foreach ($category->subCategories->category as $subcategory) {
        insert_page($subcategory->id, $subcategory->name, (int) $category->id);
        if (isset($subcategory->subCategories)) {
            insertSubcategory($subcategory);
        }
    }
}

function insert_page($cat_id, $title, $parent_cat_id) {
    global $database;

    $title = trim($title);
    if (1 > $cat_id) {
        return;
    }

    $parent = (int) $database->get_one("SELECT page_id FROM ".TABLE_PREFIX."pages WHERE cat_id = $parent_cat_id LIMIT 1");

    $parent_section = '';
    $parent_titles = array_reverse(get_parent_titles($parent));
    foreach($parent_titles AS $parent_title) {
        $parent_section .= page_filename($parent_title).'/';
    }
    if($parent_section == '/') { $parent_section = ''; }
    $link = '/'.$parent_section.page_filename($title);
    $filename = WB_PATH.PAGES_DIRECTORY.$link.'.php';
    make_dir(WB_PATH.PAGES_DIRECTORY.'/'.$parent_section);

    $get_same_page = $database->query("SELECT page_id FROM ".TABLE_PREFIX."pages WHERE link = '$link' LIMIT 1");
    if ($get_same_page->numRows() > 0) {
        echo "Page $link exists.";
        return;
    }

    $q = "INSERT INTO ".TABLE_PREFIX."pages (cat_id,  page_title, menu_title, parent, template, target, visibility, searching, menu, language, admin_groups, viewing_groups, modified_when, modified_by)
        VALUES ($cat_id,  '$title', '$title', $parent, '', '', 'public', '1', '3', 'CS', '1,2', '1', '".time()."', 1)";
    $pages = $database->query($q);
    if($database->is_error()) {
        echo $database->get_error();
        return;
    }
    $page_id = $pages->handle->insert_id;

    return $page_id;
}


