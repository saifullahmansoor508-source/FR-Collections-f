<?php
/**
 * Variant Combination Helper Functions
 */

/**
 * Generate SKU for a variant combination
 */
function generateVariantSKU($db, $product_id, $combination_data) {
    // Get product name
    $stmt = $db->prepare("SELECT name FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) return null;
    
    // Create product code (first 3 letters of product name)
    $product_code = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $product['name']), 0, 3));
    if (strlen($product_code) < 3) $product_code = str_pad($product_code, 3, 'X');
    
    // Create combination identifier
    $combo_string = is_array($combination_data) ? json_encode($combination_data) : $combination_data;
    $combo_hash = strtoupper(substr(md5($combo_string), 0, 6));
    
    return $product_code . '-' . $product_id . '-' . $combo_hash;
}

/**
 * Get all combinations for a product with full details
 */
function getProductCombinations($db, $product_id) {
    $stmt = $db->prepare("
        SELECT 
            pvc.id,
            pvc.sku,
            pvc.price,
            pvc.original_price,
            pvc.stock_quantity,
            pvc.image_path,
            pvc.is_active,
            GROUP_CONCAT(
                CONCAT(va.attribute_name, ':', vav.value_name)
                ORDER BY va.display_order
                SEPARATOR '|'
            ) as combination_string
        FROM product_variant_combinations pvc
        INNER JOIN combination_attribute_map cam ON pvc.id = cam.combination_id
        INNER JOIN variant_attribute_values vav ON cam.attribute_value_id = vav.id
        INNER JOIN variant_attributes va ON vav.attribute_id = va.id
        WHERE pvc.product_id = ?
        GROUP BY pvc.id
        ORDER BY pvc.id
    ");
    $stmt->execute([$product_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get available attributes for a product
 */
function getProductAttributes($db, $product_id) {
    $stmt = $db->prepare("
        SELECT 
            va.id as attribute_id,
            va.attribute_name,
            vav.id as value_id,
            vav.value_name
        FROM variant_attributes va
        INNER JOIN variant_attribute_values vav ON va.id = vav.attribute_id
        WHERE va.product_id = ?
        ORDER BY va.display_order, vav.display_order
    ");
    $stmt->execute([$product_id]);
    
    $attributes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attr_name = $row['attribute_name'];
        if (!isset($attributes[$attr_name])) {
            $attributes[$attr_name] = [
                'id' => $row['attribute_id'],
                'name' => $attr_name,
                'values' => []
            ];
        }
        $attributes[$attr_name]['values'][] = [
            'id' => $row['value_id'],
            'name' => $row['value_name']
        ];
    }
    
    return $attributes;
}

/**
 * Check if product uses combinations
 */
function productUsesCombinations($db, $product_id) {
    $stmt = $db->prepare("SELECT uses_variant_combinations FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result && $result['uses_variant_combinations'] == 1;
}

/**
 * Parse combination string (e.g., "Color:Red|Size:Large|Design:Pattern1")
 */
function parseCombinationString($combination_string) {
    $result = [];
    if (empty($combination_string)) return $result;
    
    $pairs = explode('|', $combination_string);
    foreach ($pairs as $pair) {
        if (strpos($pair, ':') !== false) {
            list($attr, $value) = explode(':', $pair, 2);
            $result[$attr] = $value;
        }
    }
    return $result;
}