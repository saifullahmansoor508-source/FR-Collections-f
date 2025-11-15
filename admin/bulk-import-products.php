<?php
session_start();
require_once '../config/config.php';
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle image uploads
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['product_images'])) {
    $upload_dir = '../' . PRODUCT_IMAGES_DIR;
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $uploaded_files = [];
    foreach ($_FILES['product_images']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['product_images']['error'][$key] == 0) {
            $file_ext = strtolower(pathinfo($_FILES['product_images']['name'][$key], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_ext, $allowed)) {
                $new_filename = uniqid() . '.' . $file_ext;
                if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                    $uploaded_files[] = $new_filename;
                }
            }
        }
    }
    
    echo json_encode(['success' => true, 'files' => $uploaded_files]);
    exit;
}

// Handle bulk product save
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_save'])) {
    $products_data = json_decode($_POST['products_data'], true);
    
    if (!empty($products_data)) {
        try {
            $db->beginTransaction();
            $saved_count = 0;
            $errors = [];
            
            foreach ($products_data as $product_data) {
                try {
                    // Validate required fields
                    if (empty($product_data['name']) || empty($product_data['original_price'])) {
                        $errors[] = "Skipped product {$product_data['productNo']}: Missing name or price";
                        continue;
                    }
                    
                    // Get assigned images and extract just the filename (remove path prefix)
                    $assignedImages = $product_data['assignedImages'] ?? [];
                    $primaryImage = $assignedImages['primary'] ?? null;
                    
                    // Extract filename only (remove 'uploads/products/' prefix if present)
                    if ($primaryImage) {
                        $primaryImage = basename($primaryImage);
                    }
                    
                    $shopImage = $primaryImage;
                    $homepageImage = ($product_data['display_location'] ?? 'Shop Page') !== 'Shop Page' ? $primaryImage : null;
                    
                    // Insert product with images
                    $stmt = $db->prepare("
                        INSERT INTO products (name, category_id, original_price, discounted_price, commission_rate, 
                                            delivery_charges, description, keywords, status, stock_count, sales_count, 
                                            display_location, shop_page_image, home_page_image, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $product_data['name'],
                        $product_data['category_id'] ?? 1,
                        $product_data['original_price'],
                        $product_data['discounted_price'] ?? null,
                        $product_data['commission'] ?? 0,
                        $product_data['delivery_charges'] ?? 0,
                        $product_data['description'] ?? '',
                        $product_data['keywords'] ?? '',
                        $product_data['status'] ?? 'In Stock',
                        $product_data['stock_count'] ?? 0,
                        $product_data['sales_count'] ?? 0,
                        $product_data['display_location'] ?? 'Shop Page',
                        $shopImage,
                        $homepageImage
                    ]);
                    
                    $product_id = $db->lastInsertId();
                    
                    // Insert primary image into product_images
                    if ($primaryImage) {
                        $stmt = $db->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, 1)");
                        $stmt->execute([$product_id, $primaryImage]);
                    }
                    
                    // Insert additional images
                    if (!empty($assignedImages['additional'])) {
                        $stmt = $db->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, 0)");
                        foreach ($assignedImages['additional'] as $additionalImage) {
                            if ($additionalImage) {
                                // Extract filename only (remove path prefix if present)
                                $additionalImage = basename($additionalImage);
                                $stmt->execute([$product_id, $additionalImage]);
                            }
                        }
                    }
                    
                    // Insert features
                    if (!empty($product_data['features'])) {
                        $stmt_feature = $db->prepare("INSERT INTO product_features (product_id, feature_name, feature_description) VALUES (?, ?, ?)");
                        foreach ($product_data['features'] as $feature) {
                            if (!empty($feature['name'])) {
                                $stmt_feature->execute([
                                    $product_id, 
                                    $feature['name'], 
                                    $feature['description'] ?? ''
                                ]);
                            }
                        }
                    }
                    
                    // Handle variants - Check if it's combination format or simple variants
                    if (!empty($product_data['variants'])) {
                        // Check if this is combination format (has Color, Size, Design columns)
                        $hasCombinations = false;
                        foreach ($product_data['variants'] as $variant) {
                            if (isset($variant['combination_data'])) {
                                $hasCombinations = true;
                                break;
                            }
                        }
                        
                        if ($hasCombinations) {
                            // New: Combination Variants Format
                            // Set product to use combinations
                            $stmt = $db->prepare("UPDATE products SET uses_variant_combinations = 1 WHERE id = ?");
                            $stmt->execute([$product_id]);
                            
                            // Process combinations
                            require_once '../config/variant_helpers.php';
                            
                            foreach ($product_data['variants'] as $variantIdx => $variant) {
                                if (empty($variant['combination_data'])) continue;
                                
                                $comboData = $variant['combination_data'];
                                
                                // Create/get attribute IDs and value IDs
                                $attributeValueIds = [];
                                
                                foreach ($comboData as $attrName => $attrValue) {
                                    if (empty($attrValue)) continue;
                                    
                                    // Get or create attribute
                                    $stmt = $db->prepare("
                                        INSERT INTO variant_attributes (product_id, attribute_name) 
                                        VALUES (?, ?)
                                        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
                                    ");
                                    $stmt->execute([$product_id, $attrName]);
                                    $attrId = $db->lastInsertId();
                                    
                                    // Get or create attribute value
                                    $stmt = $db->prepare("
                                        INSERT INTO variant_attribute_values (attribute_id, value_name) 
                                        VALUES (?, ?)
                                        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
                                    ");
                                    $stmt->execute([$attrId, $attrValue]);
                                    $valueId = $db->lastInsertId();
                                    
                                    $attributeValueIds[] = $valueId;
                                }
                                
                                // Create combination
                                $sku = generateVariantSKU($db, $product_id, $comboData);
                                $comboPrice = $variant['price'] ?? $product_data['original_price'];
                                $comboOriginalPrice = $variant['original_price'] ?? null;
                                $comboStock = $variant['stock'] ?? 0;
                                
                                // Get assigned image for this variant
                                $comboImage = $assignedImages['variants'][$variantIdx] ?? null;
                                if ($comboImage) {
                                    $comboImage = basename($comboImage); // Extract filename only
                                }
                                
                                $stmt = $db->prepare("
                                    INSERT INTO product_variant_combinations 
                                    (product_id, sku, price, original_price, stock_quantity, image_path) 
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $product_id, 
                                    $sku, 
                                    $comboPrice, 
                                    $comboOriginalPrice, 
                                    $comboStock,
                                    $comboImage
                                ]);
                                $comboId = $db->lastInsertId();
                                
                                // Map combination to attribute values
                                $stmt = $db->prepare("
                                    INSERT INTO combination_attribute_map (combination_id, attribute_value_id) 
                                    VALUES (?, ?)
                                ");
                                foreach ($attributeValueIds as $valueId) {
                                    $stmt->execute([$comboId, $valueId]);
                                }
                            }
                            
                            // Update combination count
                            $stmt = $db->prepare("
                                UPDATE products 
                                SET variant_combination_count = (
                                    SELECT COUNT(*) FROM product_variant_combinations 
                                    WHERE product_id = ?
                                )
                                WHERE id = ?
                            ");
                            $stmt->execute([$product_id, $product_id]);
                            
                        } else {
                            // Old: Simple Variants Format (backward compatible)
                            $stmt_variant = $db->prepare("INSERT INTO product_variants (product_id, variant_type, variant_name, variant_price, variant_original_price, variant_image) VALUES (?, ?, ?, ?, ?, ?)");
                            foreach ($product_data['variants'] as $variantIdx => $variant) {
                                if (!empty($variant['name'])) {
                                    $variantImage = $assignedImages['variants'][$variantIdx] ?? null;
                                    
                                    // Extract filename only (remove path prefix if present)
                                    if ($variantImage) {
                                        $variantImage = basename($variantImage);
                                    }
                                    
                                    // Preserve custom variant types exactly as provided
                                    $variantType = !empty($variant['type']) ? trim($variant['type']) : 'Color';
                                    
                                    $stmt_variant->execute([
                                        $product_id,
                                        $variantType,
                                        $variant['name'],
                                        $variant['price'] ?? null,
                                        $variant['original_price'] ?? null,
                                        $variantImage
                                    ]);
                                }
                            }
                        }
                    }
                    
                    // Insert reviews
                    if (!empty($product_data['reviews'])) {
                        $stmt_review = $db->prepare("INSERT INTO reviews (product_id, user_name, rating, review_text, is_approved) VALUES (?, ?, ?, ?, 1)");
                        foreach ($product_data['reviews'] as $review) {
                            if (!empty($review['reviewer_name']) && !empty($review['review_text'])) {
                                $stmt_review->execute([
                                    $product_id,
                                    $review['reviewer_name'],
                                    $review['rating'] ?? 5,
                                    $review['review_text']
                                ]);
                            }
                        }
                    }
                    
                    $saved_count++;
                    
                } catch (Exception $e) {
                    $errors[] = "Error saving product {$product_data['productNo']}: " . $e->getMessage();
                }
            }
            
            $db->commit();
            
            if ($saved_count > 0) {
                $success = "✅ Successfully imported {$saved_count} products with all their data and images!";
                if (!empty($errors)) {
                    $success .= " (" . count($errors) . " products skipped)";
                }
                // Clear progress and redirect to products page after successful import
                echo "<script>
                    // Clear sessionStorage immediately
                    sessionStorage.removeItem('bulkImportProgress');
                    console.log('✓ Progress cleared after successful import');
                    
                    // Redirect after a short delay
                    setTimeout(function() {
                        window.location.href = 'products.php?imported=success&count={$saved_count}';
                    }, 3000);
                </script>";
            } else {
                $error = "No products were imported. " . implode("; ", $errors);
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error importing products: " . $e->getMessage();
        }
    }
}

// Get categories for dropdown
$stmt = $db->prepare("SELECT * FROM categories ORDER BY name ASC");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Bulk Import Products";
require_once 'includes/header.php';
?>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>

<style>
.bulk-import-container {
    max-width: 1400px;
    margin: 0 auto;
}

.import-step {
    display: none;
}

.import-step.active {
    display: block;
}

.upload-zone {
    border: 3px dashed #0058a3;
    border-radius: 16px;
    padding: 40px;
    text-align: center;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    transition: all 0.3s ease;
    cursor: pointer;
    height: 300px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.upload-zone:hover {
    border-color: #003d73;
    background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0, 88, 163, 0.2);
}

.upload-zone i {
    font-size: 4rem;
    color: #0058a3;
    margin-bottom: 20px;
}

.product-card {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    background: white;
    transition: all 0.3s ease;
}

.product-card:hover {
    border-color: #0058a3;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.15);
}

.step-indicator {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 40px;
    padding: 20px;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
}

.step-indicator::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #0058a3, #00a3e0, #10b981);
    animation: shimmer 3s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.step-item {
    flex: 1;
    text-align: center;
    position: relative;
    z-index: 1;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.step-item:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 25px;
    left: 60%;
    width: 80%;
    height: 3px;
    background: linear-gradient(90deg, #e5e7eb 0%, #d1d5db 100%);
    z-index: -1;
    transition: all 0.5s ease;
}

.step-item.completed:not(:last-child)::after {
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.step-circle {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
    color: #9ca3af;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.3rem;
    margin-bottom: 10px;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow: hidden;
}

.step-circle::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.4) 50%, transparent 70%);
    transform: rotate(45deg);
    animation: stepShine 3s infinite;
}

@keyframes stepShine {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

.step-item.active .step-circle {
    background: linear-gradient(135deg, #0058a3 0%, #00a3e0 100%);
    color: white;
    transform: scale(1.15);
    box-shadow: 0 8px 24px rgba(0, 88, 163, 0.4);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { box-shadow: 0 8px 24px rgba(0, 88, 163, 0.4); }
    50% { box-shadow: 0 8px 32px rgba(0, 88, 163, 0.6); }
}

.step-item.completed .step-circle {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

.step-item.completed .step-circle::after {
    content: '✓';
    position: absolute;
    font-size: 1.5rem;
    animation: checkmark 0.5s ease;
}

@keyframes checkmark {
    0% { transform: scale(0) rotate(-45deg); opacity: 0; }
    100% { transform: scale(1) rotate(0deg); opacity: 1; }
}

.step-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: #6b7280;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.step-item.active .step-label {
    color: #0058a3;
    transform: scale(1.05);
}

.step-item.completed .step-label {
    color: #10b981;
}

.step-item:hover .step-circle {
    transform: scale(1.1);
}

.step-item:hover .step-label {
    color: #0058a3;
}

/* Mapping Interface Styles */
.mapping-sheet-card {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    margin-bottom: 30px; /* Increased spacing between sheet blocks */
    overflow: hidden;
    transition: all 0.3s ease;
}

.mapping-sheet-card:hover {
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.1);
}

.mapping-sheet-header {
    padding: 15px 20px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
    transition: background 0.3s ease;
}

.mapping-sheet-header:hover {
    background: #f8f9fa;
}

.mapping-sheet-header.products { 
    background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.mapping-sheet-header.features { 
    background: linear-gradient(135deg, #047857 0%, #065f46 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(4, 120, 87, 0.3);
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.mapping-sheet-header.variants { 
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.mapping-sheet-header.reviews { 
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.mapping-sheet-header.soldinfo { 
    background: linear-gradient(135deg, #4b5563 0%, #374151 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(75, 85, 99, 0.3);
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.mapping-sheet-body {
    padding: 25px; /* Increased padding */
    display: none;
    background: #f8f9fa;
}

.mapping-sheet-body.active {
    display: block;
}

.mapping-table {
    width: 100%;
    background: white;
    border-radius: 8px;
    overflow: hidden;
}

.mapping-table th {
    background: #f1f5f9;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
}

.mapping-table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
}

.mapping-table tr:last-child td {
    border-bottom: none;
}

.mapping-table tr:hover {
    background: #f8fafc;
}

.mapping-select {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.mapping-select:focus {
    outline: none;
    border-color: #0058a3;
}

.mapping-select.mapped {
    border-color: #10b981;
    background: #f0fdf4;
}

.mapping-select.unmapped {
    border-color: #f59e0b;
    background: #fffbeb;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge.mapped {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.unmapped {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.required {
    background: #fee2e2;
    color: #991b1b;
}

.field-tooltip {
    display: inline-block;
    width: 18px;
    height: 18px;
    line-height: 18px;
    text-align: center;
    background: #e2e8f0;
    color: #64748b;
    border-radius: 50%;
    font-size: 12px;
    cursor: help;
    margin-left: 5px;
}

.mapping-stats {
    display: flex;
    gap: 15px;
    margin-top: 10px;
    font-size: 13px;
}

.mapping-stat {
    display: flex;
    align-items: center;
    gap: 5px;
}

.chevron-icon {
    transition: transform 0.3s ease;
}

.chevron-icon.rotated {
    transform: rotate(180deg);
}

/* Image Preview Styles */
.image-preview-card {
    border: 2px solid #e5e7eb;
    transition: all 0.3s ease;
}

.image-preview-card:hover {
    border-color: #10b981;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
}

.image-preview-card img {
    max-height: 200px;
    object-fit: cover;
}

.product-thumb {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
    border: 2px solid #e5e7eb;
}

/* Step 4 & 5 Styles */
.image-slot {
    position: relative;
    min-height: 100px;
}

.hover-shadow {
    transition: all 0.3s ease;
}

.hover-shadow:hover {
    box-shadow: 0 8px 25px rgba(0, 88, 163, 0.15) !important;
    transform: translateY(-5px);
}

.border-dashed {
    border-style: dashed !important;
}

.variant-images-container {
    max-height: 300px;
    overflow-y: auto;
}

.variant-images-container::-webkit-scrollbar {
    width: 6px;
}

.variant-images-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.variant-images-container::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 10px;
}

.variant-images-container::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Step 3: Enhanced Image Upload Styles */
.image-summary-stats {
    background: linear-gradient(135deg, #1e3a8a 0%, #0f766e 100%);
    border-radius: 16px;
    padding: 20px;
    color: white;
    box-shadow: 0 10px 30px rgba(15, 118, 110, 0.4);
    position: relative;
    overflow: hidden;
}

.image-summary-stats::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    animation: shimmer 3s infinite;
}

@keyframes shimmer {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(-20px, -20px); }
}

.stat-card {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    padding: 20px 15px;
    text-align: center;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.3);
    min-height: 130px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    position: relative;
    z-index: 1;
}

.stat-card:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    border-color: rgba(255, 255, 255, 0.5);
}

.stat-number {
    font-size: 2.2rem;
    font-weight: 700;
    line-height: 1.1;
    margin-bottom: 8px;
    margin-top: 5px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.stat-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    opacity: 1;
    font-weight: 600;
    line-height: 1.2;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    color: #ffffff;
}

/* Image Name Badge Styles */
.badge.bg-secondary,
.badge.bg-info,
.badge.bg-success {
    font-family: 'Courier New', monospace;
    font-weight: 500;
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    padding: 6px 12px;
    transition: all 0.2s ease;
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.badge.bg-secondary:hover,
.badge.bg-info:hover,
.badge.bg-success:hover {
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
}

/* Image list grouped cards with gradients */
.image-product-card {
    border: none !important;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
}

.image-product-card:hover {
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    transform: translateY(-3px);
}

.card-header.gradient-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    font-size: 0.85rem;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.card-header.gradient-warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    font-size: 0.85rem;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.image-badge-container {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

/* Clickable image name badges */
.badge[onclick] {
    cursor: pointer;
    user-select: none;
}

.badge[onclick]:active {
    transform: scale(0.95);
}

/* Auto-matched grid improvements */
.auto-matched-card {
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    transition: all 0.3s ease;
}

.auto-matched-card:hover {
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    transform: translateY(-5px);
}

.auto-matched-card .card-body {
    padding: 16px;
}

.product-header-badge {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.3px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
}

.warning-badge {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    animation: pulse-warning 2s ease-in-out infinite;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

@keyframes pulse-warning {
    0%, 100% { 
        opacity: 1; 
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }
    50% { 
        opacity: 0.9;
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.5);
    }
}

.success-badge {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.stat-box {
    background: linear-gradient(145deg, #f1f5f9 0%, #e2e8f0 100%);
    border-radius: 10px;
    padding: 10px 8px;
    text-align: center;
    border: 1px solid #cbd5e1;
    min-height: 70px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.stat-box .stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.2;
    margin-bottom: 3px;
    word-break: keep-all;
    max-width: 100%;
    white-space: nowrap;
}

.stat-box .stat-number span {
    font-weight: 400;
}

.stat-box .stat-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    font-weight: 600;
    line-height: 1.1;
}

.image-name-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 6px;
    line-height: 1.8;
}

.image-name-badge {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 0.68rem;
    font-family: 'Courier New', monospace;
    font-weight: 500;
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    line-height: 1;
}

.image-name-badge:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 10px rgba(99, 102, 241, 0.4);
}

.image-name-badge i {
    font-size: 0.6rem;
    margin-right: 4px;
}

.image-name-badge.primary {
    background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%);
    box-shadow: 0 2px 8px rgba(168, 85, 247, 0.3);
}

.image-name-badge.primary:hover {
    box-shadow: 0 4px 12px rgba(168, 85, 247, 0.5);
}

.image-name-badge.variant {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
}

.image-name-badge.variant:hover {
    box-shadow: 0 4px 12px rgba(6, 182, 212, 0.5);
}

.image-name-badge.additional {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    box-shadow: 0 2px 8px rgba(107, 114, 128, 0.3);
}

.image-name-badge.additional:hover {
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.5);
}

.section-header {
    font-size: 0.7rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 2px solid #e2e8f0;
}

/* Primary image preview */
.primary-image-preview {
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.primary-image-preview img {
    transition: transform 0.3s ease;
}

.primary-image-preview:hover img {
    transform: scale(1.05);
}

.image-overlay-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.95) 0%, rgba(124, 58, 237, 0.95) 100%);
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
    backdrop-filter: blur(10px);
    line-height: 1.2;
    display: inline-flex;
    align-items: center;
}

.image-overlay-badge i {
    font-size: 0.6rem;
    margin-right: 3px;
}

/* Compact alert styles */
.alert-compact {
    padding: 12px 16px;
    font-size: 0.85rem;
    border-radius: 10px;
    border: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.alert-compact.alert-warning {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}

.alert-compact.alert-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
}

.alert-compact.alert-info {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
}

/* Animations */
@keyframes slideInDown {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes slideOutUp {
    from {
        transform: translateY(0);
        opacity: 1;
    }
    to {
        transform: translateY(-20px);
        opacity: 0;
    }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        transform: translateY(20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        max-height: 5000px;
        transform: translateY(0);
    }
}

/* Smooth scroll behavior */
html {
    scroll-behavior: smooth;
}

/* Top navigation buttons styling */
.step-nav-top {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 2px solid #dee2e6;
    position: sticky;
    top: 10px;
    z-index: 100;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.step-nav-top .btn {
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

/* Responsive adjustments for stat numbers */
@media (max-width: 768px) {
    .stat-box .stat-number {
        font-size: 1.3rem;
    }
    
    .stat-box .stat-label {
        font-size: 0.6rem;
    }
    
    .stat-number {
        font-size: 2rem;
    }
    
    .stat-label {
        font-size: 0.65rem;
    }
}

@media (max-width: 576px) {
    .stat-box .stat-number {
        font-size: 1.1rem;
    }
    
    .stat-box .stat-label {
        font-size: 0.55rem;
    }
}

/* Section headers with gradient underline */
.section-title {
    position: relative;
    display: inline-block;
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.section-title::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 60%;
    height: 3px;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    border-radius: 2px;
}

/* Collapsible section headers */
.section-collapse-header {
    user-select: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.section-collapse-header:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.section-collapse-header:active {
    transform: scale(0.99);
}

/* ============================================
   MOBILE RESPONSIVE DESIGN - BEAUTIFUL & MODERN
   ============================================ */

/* Mobile-first responsive container */
@media (max-width: 1200px) {
    .bulk-import-container {
        max-width: 100%;
        padding: 0 15px;
    }
}

/* ========================================
   MOBILE RESPONSIVE - Step Indicator
   ======================================== */
@media (max-width: 768px) {
    .step-indicator {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: hidden !important;
        overflow-y: hidden !important;
        padding: 15px 10px !important;
        gap: 0 !important;
        justify-content: space-between !important;
        align-items: center !important;
    }
    
    .step-indicator::before {
        display: none;
    }
    
    .step-item {
        flex: 1 !important;
        min-width: 0 !important;
        max-width: none !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
    }
    
    .step-item:not(:last-child)::after {
        display: none;
    }
    
    .step-circle {
        width: 40px !important;
        height: 40px !important;
        font-size: 1rem !important;
        margin-bottom: 0 !important;
    }
    
    .step-item.active .step-circle {
        transform: scale(1.05) !important;
        animation: mobilePulse 2s infinite;
    }
    
    @keyframes mobilePulse {
        0%, 100% { box-shadow: 0 4px 16px rgba(0, 88, 163, 0.4); }
        50% { box-shadow: 0 4px 20px rgba(0, 88, 163, 0.6); }
    }
    
    /* HIDE labels on mobile - only show numbers */
    .step-label {
        display: none !important;
    }
}

/* Mobile Upload Zone */
@media (max-width: 768px) {
    .upload-zone {
        padding: 30px 20px;
        height: auto;
        min-height: 200px;
        border-width: 2px;
    }
    
    .upload-zone i {
        font-size: 3rem;
        margin-bottom: 15px;
    }
    
    .upload-zone h5 {
        font-size: 1.1rem;
    }
    
    .upload-zone p {
        font-size: 0.9rem;
    }
}

@media (max-width: 480px) {
    .upload-zone {
        padding: 25px 15px;
        min-height: 180px;
    }
    
    .upload-zone i {
        font-size: 2.5rem;
    }
    
    .upload-zone h5 {
        font-size: 1rem;
    }
    
    .upload-zone p {
        font-size: 0.85rem;
    }
}

/* Mobile Stat Cards */
@media (max-width: 768px) {
    .stat-card {
        min-height: 110px;
        padding: 15px 10px;
    }
    
    .image-summary-stats {
        padding: 15px;
    }
}

@media (max-width: 576px) {
    .stat-card {
        min-height: 100px;
        padding: 12px 8px;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }
    
    .stat-label {
        font-size: 0.65rem;
    }
}

/* Mobile Mapping Tables */
@media (max-width: 992px) {
    .mapping-table {
        font-size: 0.9rem;
    }
    
    .mapping-table th,
    .mapping-table td {
        padding: 10px 8px;
    }
    
    .mapping-select {
        font-size: 13px;
        padding: 6px 10px;
    }
}

@media (max-width: 768px) {
    .mapping-table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
    }
    
    .mapping-table th,
    .mapping-table td {
        padding: 8px 6px;
        font-size: 0.85rem;
    }
    
    .mapping-sheet-header {
        padding: 12px 15px;
        font-size: 0.95rem;
    }
    
    .mapping-sheet-body {
        padding: 15px;
    }
}

/* Mobile Auto-Matched Cards */
@media (max-width: 768px) {
    .auto-matched-card .card-body {
        padding: 12px;
    }
    
    .product-header-badge {
        font-size: 0.65rem;
        padding: 4px 10px;
    }
    
    .primary-image-preview {
        margin-bottom: 10px;
    }
    
    .primary-image-preview img {
        height: 150px !important;
    }
}

@media (max-width: 576px) {
    .auto-matched-card .card-body {
        padding: 10px;
    }
    
    .primary-image-preview img {
        height: 130px !important;
    }
}

/* Mobile Image Name Badges */
@media (max-width: 768px) {
    .image-name-badge {
        font-size: 0.65rem;
        padding: 4px 8px;
    }
    
    .image-name-badge i {
        font-size: 0.55rem;
        margin-right: 3px;
    }
    
    .image-badge-container {
        gap: 5px;
    }
}

@media (max-width: 480px) {
    .image-name-badge {
        font-size: 0.6rem;
        padding: 3px 7px;
    }
}

/* Mobile Buttons & Navigation */
@media (max-width: 768px) {
    .step-nav-top {
        padding: 12px;
        position: relative;
        top: 0;
    }
    
    .step-nav-top .btn {
        font-size: 0.9rem;
        padding: 8px 16px;
    }
    
    .btn-lg {
        font-size: 1rem !important;
        padding: 10px 20px !important;
    }
}

@media (max-width: 576px) {
    .step-nav-top .btn {
        font-size: 0.85rem;
        padding: 7px 14px;
    }
    
    .step-nav-top .btn i {
        font-size: 0.8rem;
    }
    
    .btn-lg {
        font-size: 0.95rem !important;
        padding: 9px 18px !important;
    }
}

/* Mobile Alerts */
@media (max-width: 768px) {
    .alert {
        font-size: 0.9rem;
        padding: 12px 15px;
    }
    
    .alert-compact {
        font-size: 0.8rem;
        padding: 10px 14px;
    }
}

/* Mobile Cards & Spacing */
@media (max-width: 768px) {
    .card {
        margin-bottom: 15px;
    }
    
    .card-body {
        padding: 15px;
    }
    
    .product-card {
        padding: 15px;
        margin-bottom: 15px;
    }
}

@media (max-width: 576px) {
    .card-body {
        padding: 12px;
    }
    
    .product-card {
        padding: 12px;
    }
}

/* Mobile Grid Adjustments */
@media (max-width: 768px) {
    .row.g-3 {
        gap: 12px !important;
    }
    
    .row.g-2 {
        gap: 8px !important;
    }
}

/* Mobile Section Headers */
@media (max-width: 768px) {
    .section-collapse-header {
        padding: 10px 12px !important;
        font-size: 0.85rem;
    }
    
    .section-collapse-header h6 {
        font-size: 0.85rem !important;
    }
    
    .section-collapse-header i {
        font-size: 0.9rem;
    }
}

/* Mobile Table Responsive */
@media (max-width: 768px) {
    .table-responsive {
        border-radius: 8px;
        margin-bottom: 10px;
    }
    
    .table {
        font-size: 0.85rem;
    }
    
    .table th,
    .table td {
        padding: 8px 6px;
    }
}

/* Mobile Form Controls */
@media (max-width: 768px) {
    .form-control,
    .form-select {
        font-size: 0.9rem;
        padding: 8px 12px;
    }
    
    .form-control-modern {
        font-size: 0.9rem;
    }
}

/* Mobile Image Preview */
@media (max-width: 768px) {
    .image-slot img {
        max-height: 150px !important;
    }
    
    .product-thumb {
        width: 50px;
        height: 50px;
    }
}

/* Mobile Stat Boxes in Final Preview */
@media (max-width: 992px) {
    .card.text-center {
        margin-bottom: 15px;
    }
    
    .card.text-center h3 {
        font-size: 2rem;
    }
    
    .card.text-center i {
        font-size: 2.5rem !important;
    }
}

@media (max-width: 576px) {
    .card.text-center h3 {
        font-size: 1.5rem;
    }
    
    .card.text-center i {
        font-size: 2rem !important;
        margin-bottom: 10px !important;
    }
    
    .card.text-center p {
        font-size: 0.85rem;
    }
}

/* Mobile Scrollbar Styling */
@media (max-width: 768px) {
    .variant-images-container {
        max-height: 250px;
    }
    
    .variant-images-container::-webkit-scrollbar {
        width: 4px;
    }
}

/* Mobile Progress Bar */
@media (max-width: 768px) {
    .progress {
        height: 20px !important;
    }
    
    .progress-bar {
        font-size: 0.75rem;
    }
}

/* Mobile Badge Sizes */
@media (max-width: 768px) {
    .badge {
        font-size: 0.7rem;
        padding: 4px 10px;
    }
    
    .status-badge {
        font-size: 0.65rem;
        padding: 3px 10px;
    }
}

/* Mobile Unmatched Images Table */
@media (max-width: 768px) {
    #unmappedImagesBody img {
        width: 40px !important;
        height: 40px !important;
    }
    
    #unmappedImagesBody .btn-sm {
        font-size: 0.75rem;
        padding: 4px 10px;
    }
}

/* Mobile Hover Effects - Reduced for Touch */
@media (max-width: 768px) {
    .upload-zone:hover {
        transform: none;
    }
    
    .auto-matched-card:hover {
        transform: translateY(-2px);
    }
    
    .hover-shadow:hover {
        transform: translateY(-2px);
    }
}

/* Mobile Page Header */
@media (max-width: 768px) {
    .bulk-import-container > .row.mb-4 {
        margin-bottom: 20px !important;
    }
    
    .bulk-import-container h4 {
        font-size: 1.3rem;
    }
    
    .bulk-import-container p.text-muted {
        font-size: 0.85rem;
    }
}

@media (max-width: 576px) {
    .bulk-import-container h4 {
        font-size: 1.1rem;
    }
    
    .bulk-import-container p.text-muted {
        font-size: 0.8rem;
        margin-bottom: 10px !important;
    }
    
    .d-flex.justify-content-between {
        flex-direction: column;
        gap: 10px;
    }
    
    .d-flex.justify-content-between > div {
        width: 100%;
    }
    
    .d-flex.justify-content-between .btn {
        width: 100%;
    }
}

/* Mobile Gradient Animations - Optimized */
@media (max-width: 768px) {
    @keyframes shimmer {
        0%, 100% { transform: translate(0, 0); }
        50% { transform: translate(-10px, -10px); }
    }
}

/* Mobile Info Boxes */
@media (max-width: 768px) {
    .bg-gradient-to-r {
        padding: 15px !important;
        border-radius: 12px !important;
    }
    
    .bg-gradient-to-r h6 {
        font-size: 0.95rem;
    }
    
    .bg-gradient-to-r p {
        font-size: 0.85rem;
    }
    
    .grid {
        gap: 10px !important;
    }
}

@media (max-width: 576px) {
    .bg-gradient-to-r {
        padding: 12px !important;
    }
    
    .bg-gradient-to-r h6 {
        font-size: 0.9rem;
    }
    
    .bg-gradient-to-r p,
    .bg-gradient-to-r .text-xs {
        font-size: 0.75rem;
    }
}

/* Mobile Image Processing Status */
@media (max-width: 768px) {
    #imageProcessing .card-body {
        padding: 15px;
    }
    
    #imageProcessing h6 {
        font-size: 0.95rem;
    }
    
    #imageStatus {
        font-size: 0.85rem;
    }
}

/* Mobile Floating Action - Sticky Buttons */
@media (max-width: 768px) {
    .float-end {
        float: none !important;
        display: block;
        width: 100%;
        margin-top: 10px;
    }
}

/* Landscape Mobile Optimization */
@media (max-width: 896px) and (orientation: landscape) {
    .upload-zone {
        height: auto;
        min-height: 150px;
        padding: 20px;
    }
    
    .step-circle {
        width: 35px;
        height: 35px;
        font-size: 0.9rem;
    }
    
    .step-label {
        font-size: 0.7rem;
    }
}

/* Ultra Small Screens */
@media (max-width: 360px) {
    .bulk-import-container {
        padding: 0 10px;
    }
    
    .card-body {
        padding: 10px;
    }
    
    .btn {
        font-size: 0.8rem;
        padding: 6px 12px;
    }
    
    .stat-number {
        font-size: 1.5rem;
    }
    
    .stat-label {
        font-size: 0.6rem;
    }
}

/* Touch-friendly Interactive Elements */
@media (hover: none) and (pointer: coarse) {
    .upload-zone {
        -webkit-tap-highlight-color: rgba(0, 88, 163, 0.1);
    }
    
    .btn {
        min-height: 44px;
        -webkit-tap-highlight-color: transparent;
    }
    
    .section-collapse-header {
        -webkit-tap-highlight-color: rgba(0, 0, 0, 0.05);
    }
    
    .image-name-badge {
        min-height: 28px;
    }
}

/* Smooth Transitions for Mobile */
@media (max-width: 768px) {
    * {
        -webkit-tap-highlight-color: transparent;
    }
    
    .card,
    .btn,
    .upload-zone,
    .stat-card {
        transition: all 0.2s ease;
    }
}

/* Mobile Dark Mode Support (Optional) */
@media (prefers-color-scheme: dark) and (max-width: 768px) {
    .upload-zone {
        background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
        border-color: #374151;
    }
    
    .card {
        background: #1f2937;
        border-color: #374151;
    }
}

/* ============================================
   STEP 4: COLLAPSIBLE PRODUCT PREVIEW CARDS
   ============================================ */

/* Product preview card header - clickable */
.card-header.bg-primary[onclick] {
    transition: all 0.3s ease;
    background: linear-gradient(135deg, #0058a3 0%, #003d73 100%) !important;
}

.card-header.bg-primary[onclick]:hover {
    background: linear-gradient(135deg, #003d73 0%, #002952 100%) !important;
    box-shadow: 0 4px 12px rgba(0, 88, 163, 0.3);
    transform: translateY(-1px);
}

.card-header.bg-primary[onclick]:active {
    transform: translateY(0);
}

/* Chevron icon animation */
.card-header i.fa-chevron-down,
.card-header i.fa-chevron-up {
    font-size: 1rem;
    opacity: 0.9;
}

.card-header:hover i.fa-chevron-down,
.card-header:hover i.fa-chevron-up {
    opacity: 1;
}

/* Product card body animation */
.card-body[id^="product-body-"] {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Mobile responsive for Step 4 product cards */
@media (max-width: 768px) {
    .card-header.bg-primary h6 {
        font-size: 0.9rem;
    }
    
    .card-header.bg-primary i.fa-box {
        font-size: 0.85rem;
    }
    
    .card-header i.fa-chevron-down,
    .card-header i.fa-chevron-up {
        font-size: 0.9rem;
    }
}

@media (max-width: 576px) {
    .card-header.bg-primary h6 {
        font-size: 0.85rem;
        line-height: 1.3;
    }
    
    .card-header.bg-primary {
        padding: 10px 12px;
    }
}

/* Product info table in Step 4 */
@media (max-width: 768px) {
    #editablePreviewContent .table-sm {
        font-size: 0.85rem;
    }
    
    #editablePreviewContent .table-sm td {
        padding: 6px 8px;
    }
}

/* Image slots in Step 4 */
@media (max-width: 768px) {
    #editablePreviewContent .image-slot img {
        max-height: 150px !important;
    }
    
    #editablePreviewContent .col-md-4 {
        margin-bottom: 15px;
    }
}

@media (max-width: 576px) {
    #editablePreviewContent .image-slot img {
        max-height: 130px !important;
    }
}

/* ============================================
   STEP 5: COLLAPSIBLE PRODUCT PREVIEW SECTION
   ============================================ */

/* Product Preview header in Step 5 */
.card-header.bg-gradient[onclick] {
    transition: all 0.3s ease;
}

.card-header.bg-gradient[onclick]:hover {
    background: linear-gradient(135deg, #003d73 0%, #002952 100%) !important;
    box-shadow: 0 6px 16px rgba(0, 88, 163, 0.4);
    transform: translateY(-2px);
}

.card-header.bg-gradient[onclick]:active {
    transform: translateY(0);
}

/* Chevron animation in Step 5 */
#chevron-final-preview {
    font-size: 1.1rem;
    opacity: 0.9;
}

.card-header.bg-gradient:hover #chevron-final-preview {
    opacity: 1;
}

/* Final preview body animation */
#final-preview-body {
    animation: slideDown 0.4s ease-out;
}

/* Mobile responsive for Step 5 collapsible section */
@media (max-width: 768px) {
    .card-header.bg-gradient h6 {
        font-size: 0.95rem;
    }
    
    .card-header.bg-gradient i.fa-eye {
        font-size: 0.9rem;
    }
    
    #chevron-final-preview {
        font-size: 1rem;
    }
    
    #finalPreviewContent {
        padding: 15px !important;
    }
}

@media (max-width: 576px) {
    .card-header.bg-gradient h6 {
        font-size: 0.9rem;
        line-height: 1.3;
    }
    
    .card-header.bg-gradient {
        padding: 12px 15px;
    }
    
    #chevron-final-preview {
        font-size: 0.95rem;
    }
    
    #finalPreviewContent {
        padding: 12px !important;
    }
}

/* Product cards in Step 5 final preview */
@media (max-width: 768px) {
    #finalPreviewContent .card {
        margin-bottom: 15px;
    }
    
    #finalPreviewContent .card-img-top {
        height: 180px !important;
    }
    
    #finalPreviewContent .card-title {
        font-size: 0.95rem;
    }
}

@media (max-width: 576px) {
    #finalPreviewContent .card-img-top {
        height: 150px !important;
    }
    
    #finalPreviewContent .card-title {
        font-size: 0.9rem;
    }
    
    #finalPreviewContent .badge {
        font-size: 0.7rem;
    }
}
</style>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="bulk-import-container">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1"><i class="fas fa-file-import me-2"></i>Bulk Import Products</h4>
                    <p class="text-muted mb-0">Import multiple products with features, variants, and reviews from Google Sheets or Excel</p>
                </div>
                <div>
                    <button onclick="confirmClearProgress()" class="btn btn-outline-warning me-2" id="clearProgressBtn" style="display: none;">
                        <i class="fas fa-trash-restore me-2"></i>Start Over
                    </button>
                    <a href="products.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Products
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Step Indicator -->
    <div class="step-indicator">
        <div class="step-item active" id="stepIndicator1">
            <div class="step-circle">1</div>
            <div class="step-label">Upload Data</div>
        </div>
        <div class="step-item" id="stepIndicator2">
            <div class="step-circle">2</div>
            <div class="step-label">Map Data</div>
        </div>
        <div class="step-item" id="stepIndicator3">
            <div class="step-circle">3</div>
            <div class="step-label">Upload Images</div>
        </div>
        <div class="step-item" id="stepIndicator4">
            <div class="step-circle">4</div>
            <div class="step-label">Preview & Edit</div>
        </div>
        <div class="step-item" id="stepIndicator5">
            <div class="step-circle">5</div>
            <div class="step-label">Save Products</div>
        </div>
    </div>

    <!-- Step 1: Upload -->
    <div class="import-step active" id="step1">
        <div class="card shadow-sm">
            <div class="card-body p-5">
                <h5 class="mb-4"><i class="fas fa-cloud-upload-alt me-2"></i>Step 1: Upload Your Data</h5>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="upload-zone" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-file-excel"></i>
                            <h5>Upload Excel/CSV File</h5>
                            <p class="text-muted">Click to browse or drag & drop</p>
                            <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display: none;" onchange="handleFileUpload(event)">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="upload-zone">
                            <i class="fas fa-link"></i>
                            <h5>Google Sheets Link</h5>
                            <input type="text" id="googleSheetInput" class="form-control mt-3" placeholder="Paste Google Sheets URL here...">
                            <button class="btn btn-primary mt-3" onclick="handleGoogleSheetImport()">Load Sheet</button>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-blue-500 p-6 rounded-2xl shadow-sm">
                    <h6 class="text-lg font-bold text-blue-900 mb-3">
                        <i class="fas fa-info-circle me-2"></i>Product Number-Based Matching System
                    </h6>
                    <p class="text-gray-700 mb-3">Your Excel/Google Sheets file should contain <strong>5 sheets</strong> with data matched by <strong>Product Number</strong>:</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        <div class="bg-white p-3 rounded-lg shadow-sm">
                            <strong class="text-blue-600">📦 Sheet 1: Products</strong>
                            <p class="text-gray-600 text-xs mt-1">Product No, Name, Description, Category, Price, Discount, etc.</p>
                        </div>
                        <div class="bg-white p-3 rounded-lg shadow-sm">
                            <strong class="text-green-600">⭐ Sheet 2: Features</strong>
                            <p class="text-gray-600 text-xs mt-1">Product No, Feature Name, Feature Value, etc.</p>
                        </div>
                        <div class="bg-white p-3 rounded-lg shadow-sm">
                            <strong class="text-purple-600">🎨 Sheet 3: Variants</strong>
                            <p class="text-gray-600 text-xs mt-1">Product No, Variant Name, Option Name, Option Value, etc.</p>
                        </div>
                        <div class="bg-white p-3 rounded-lg shadow-sm">
                            <strong class="text-yellow-600">💬 Sheet 4: Reviews</strong>
                            <p class="text-gray-600 text-xs mt-1">Product No, Reviewer Name, Rating, Comment, etc.</p>
                        </div>
                        <div class="bg-white p-3 rounded-lg shadow-sm col-span-full">
                            <strong class="text-red-600">📊 Sheet 5: Sold Info</strong>
                            <p class="text-gray-600 text-xs mt-1">Product No, Units Sold, Views, Stock Count, etc.</p>
                        </div>
                    </div>
                    <p class="text-xs text-gray-600 mt-3">
                        <i class="fas fa-lightbulb text-yellow-500 me-1"></i>
                        <strong>Tip:</strong> All sheets will be automatically matched using the "Product No" column. You can map columns manually in the next step.
                    </p>
                </div>

                <div id="loadingIndicator" style="display: none;" class="text-center my-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Processing your data...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Map Data -->
    <div class="import-step" id="step2">
        <div class="card shadow-sm">
            <div class="card-body p-5">
                <h5 class="mb-4"><i class="fas fa-map me-2"></i>Step 2: Map Data Columns</h5>
                
                <!-- Top Navigation Buttons -->
                <div id="mappingActionsTop" style="display: none;" class="step-nav-top">
                    <button class="btn btn-secondary" onclick="goToStep(1)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                    <button class="btn btn-success float-end" onclick="saveMappings()"><i class="fas fa-check me-2"></i>Save Mapping & Continue</button>
                </div>
                
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Auto-Detection Complete:</strong> Review the auto-detected column mappings below. Use dropdowns to manually correct any mismatched fields.
                </div>

                <div id="mappingContent">
                    <p class="text-center text-muted py-5">Data mapping will appear here after upload...</p>
                </div>

                <!-- Bottom Navigation Buttons -->
                <div id="mappingActions" style="display: none;" class="mt-4">
                    <button class="btn btn-secondary" onclick="goToStep(1)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                    <button class="btn btn-success float-end" onclick="saveMappings()"><i class="fas fa-check me-2"></i>Save Mapping & Continue</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Upload Images -->
    <div class="import-step" id="step3">
        <div class="card shadow-sm">
            <div class="card-body p-5">
                <h5 class="mb-4"><i class="fas fa-images me-2"></i>Step 3: Upload Product Images</h5>
                
                <!-- Top Navigation Buttons -->
                <div class="step-nav-top">
                    <button class="btn btn-secondary" onclick="goToStep(2)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                    <button id="continueToPreviewTop" class="btn btn-primary float-end" onclick="validateAndGoToStep4()">
                        Next: Edit & Preview <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
                
                <div class="upload-zone" onclick="document.getElementById('imageInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h5>Upload Product Images</h5>
                    <p class="text-muted">Click to browse or drag & drop multiple images</p>
                    <p class="text-success mb-0"><strong><i class="fas fa-infinity me-1"></i>Unlimited Upload</strong> - Upload 2000+ images at once!</p>
                    <input type="file" id="imageInput" accept="image/*" multiple style="display: none;" onchange="handleImageUploadEvent(event)">
                </div>

                <div class="alert alert-info mt-4">
                    <i class="fas fa-lightbulb me-2"></i>
                    <strong>Image Naming Patterns (Required):</strong>
                    <br>
                    <div class="mt-2 p-3 bg-white rounded">
                        <strong class="text-primary">📋 Pattern 1: Sequential Images</strong> <code>ProductID (Number)[.extension]</code>
                        <ul class="mb-2 mt-2 small">
                            <li><code>1 (1).jpg</code> → Product #1, Primary Image (shop + homepage)</li>
                            <li><code>1 (2)</code> → Product #1, First Variant Image (no extension is OK)</li>
                            <li><code>1 (3).png</code> → Product #1, Second Variant Image</li>
                            <li><code>2 (1).webp</code> → Product #2, Primary Image</li>
                            <li><code>2 (2).jpg</code> → Product #2, First Variant Image</li>
                            <li><code>15 (5).jpg</code> → Product #15, Additional Image</li>
                        </ul>
                        
                        <strong class="text-success">🎨 Pattern 2: Non-Sequential Images (Always Additional)</strong> <code>ProductID + Letters[.extension]</code>
                        <ul class="mb-0 mt-2 small">
                            <li><code>1A.jpg</code> → Product #1, Additional Image</li>
                            <li><code>1B.png</code> → Product #1, Additional Image</li>
                            <li><code>1 (A).jpg</code> → Product #1, Additional Image</li>
                            <li><code>1TY.jpg</code> → Product #1, Additional Image</li>
                            <li><code>2F</code> → Product #2, Additional Image</li>
                        </ul>
                    </div>
                    <div class="mt-2 p-2 bg-warning bg-opacity-10 rounded">
                        <strong class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Assignment Rules for Sequential Images:</strong>
                        <ul class="mb-0 mt-1 small">
                            <li><strong>(1)</strong> = Primary + Shop + Homepage image</li>
                            <li><strong>(2)</strong> = First variant image</li>
                            <li><strong>(3)</strong> = Second variant image</li>
                            <li><strong>(4)</strong> = Third variant image</li>
                            <li><strong>(5+)</strong> = Additional product images (after all variants)</li>
                        </ul>
                    </div>
                    <div class="mt-2 p-2 bg-success bg-opacity-10 rounded">
                        <strong class="text-success"><i class="fas fa-info-circle me-1"></i>Non-Sequential Images:</strong>
                        <ul class="mb-0 mt-1 small">
                            <li>Images like <code>1A</code>, <code>1B</code>, <code>1TY</code>, <code>1 (A)</code> are <strong>ALWAYS</strong> assigned as additional images</li>
                            <li>They are added regardless of whether variant images exist or not</li>
                        </ul>
                    </div>
                </div>

                <!-- Image Processing Progress -->
                <div id="imageProcessing" style="display: none;" class="mt-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="mb-3">Processing Images...</h6>
                            <div class="progress" style="height: 25px;">
                                <div id="imageProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 0%">0%</div>
                            </div>
                            <div id="imageStatus" class="mt-2 text-center"></div>
                        </div>
                    </div>
                </div>

                <!-- Image Summary -->
                <div id="imageSummary" style="display: none;" class="mt-4">
                    <div class="image-summary-stats">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <i class="fas fa-check-circle mb-2" style="font-size: 2rem; opacity: 0.9;"></i>
                                    <div class="stat-number" id="autoMatchedCount">0</div>
                                    <div class="stat-label">Auto-Matched</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <i class="fas fa-hand-pointer mb-2" style="font-size: 2rem; opacity: 0.9;"></i>
                                    <div class="stat-number" id="manualAssignedCount">0</div>
                                    <div class="stat-label">Manual Assigned</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <i class="fas fa-exclamation-triangle mb-2" style="font-size: 2rem; opacity: 0.9;"></i>
                                    <div class="stat-number" id="skippedCount">0</div>
                                    <div class="stat-label">Unmatched</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Auto-Mapped Images Preview -->
                <div id="autoMappedSection" style="display: none;" class="mt-4">
                    <div class="section-collapse-header d-flex align-items-center justify-content-between mb-3 p-3 rounded" 
                         style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);"
                         onclick="toggleSection('autoMatched')"
                         onmouseover="this.style.background='linear-gradient(135deg, #059669 0%, #047857 100%)'; this.style.boxShadow='0 6px 16px rgba(16, 185, 129, 0.4)'"
                         onmouseout="this.style.background='linear-gradient(135deg, #10b981 0%, #059669 100%)'; this.style.boxShadow='0 4px 12px rgba(16, 185, 129, 0.3)'">
                        <div class="d-flex align-items-center">
                            <div class="d-flex align-items-center justify-content-center rounded-circle me-2" 
                                 style="width: 36px; height: 36px; background: rgba(255, 255, 255, 0.25); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                                <i class="fas fa-check-circle text-white"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 0.95rem; font-weight: 600; color: #ffffff; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);">Auto-Matched Images</h6>
                        </div>
                        <i class="fas fa-chevron-down text-white" id="chevron-autoMatched" style="transition: transform 0.3s ease; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));"></i>
                    </div>
                    <div id="autoMappedGrid" class="row g-3"></div>
                </div>

                <!-- Unmapped Images Section -->
                <div id="unmappedSection" style="display: none;" class="mt-4">
                    <div class="section-collapse-header d-flex align-items-center justify-content-between mb-3 p-3 rounded" 
                         style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);"
                         onclick="toggleSection('unmatched')"
                         onmouseover="this.style.background='linear-gradient(135deg, #d97706 0%, #b45309 100%)'; this.style.boxShadow='0 6px 16px rgba(245, 158, 11, 0.4)'"
                         onmouseout="this.style.background='linear-gradient(135deg, #f59e0b 0%, #d97706 100%)'; this.style.boxShadow='0 4px 12px rgba(245, 158, 11, 0.3)'">
                        <div class="d-flex align-items-center">
                            <div class="d-flex align-items-center justify-content-center rounded-circle me-2" 
                                 style="width: 36px; height: 36px; background: rgba(255, 255, 255, 0.25); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                                <i class="fas fa-exclamation-triangle text-white"></i>
                            </div>
                            <h6 class="mb-0" style="font-size: 0.95rem; font-weight: 600; color: #ffffff; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);">Unmatched Images - Manual Assignment</h6>
                        </div>
                        <i class="fas fa-chevron-down text-white" id="chevron-unmatched" style="transition: transform 0.3s ease; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));"></i>
                    </div>
                    <div class="card border-0 shadow-sm" style="border-radius: 12px; overflow: hidden;">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);">
                                    <tr>
                                        <th width="80" style="font-size: 0.75rem; font-weight: 600; color: #92400e;">Preview</th>
                                        <th style="font-size: 0.75rem; font-weight: 600; color: #92400e;">File Name</th>
                                        <th style="font-size: 0.75rem; font-weight: 600; color: #92400e;">Product</th>
                                        <th style="font-size: 0.75rem; font-weight: 600; color: #92400e;">Type</th>
                                        <th width="120" style="font-size: 0.75rem; font-weight: 600; color: #92400e;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="unmappedImagesBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Complete Upload List -->
                <div id="uploadedImagesList" style="display: none;" class="mt-4">
                    <div class="section-collapse-header d-flex align-items-center justify-content-between mb-3 p-3 rounded" 
                         style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);"
                         onclick="toggleSection('allImages')"
                         onmouseover="this.style.background='linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)'; this.style.boxShadow='0 6px 16px rgba(59, 130, 246, 0.4)'"
                         onmouseout="this.style.background='linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.3)'">
                        <div class="d-flex align-items-center">
                            <div class="d-flex align-items-center justify-content-center rounded-circle me-2" 
                                 style="width: 36px; height: 36px; background: rgba(255, 255, 255, 0.25); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                                <i class="fas fa-images text-white"></i>
                            </div>
                            <div>
                                <h6 class="mb-0" style="font-size: 0.95rem; font-weight: 600; color: #ffffff; line-height: 1; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);">All Uploaded Images</h6>
                                <span class="badge" style="background: rgba(255, 255, 255, 0.25); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3); font-size: 0.65rem; margin-top: 3px; color: #ffffff;">Verification List</span>
                            </div>
                        </div>
                        <i class="fas fa-chevron-down text-white" id="chevron-allImages" style="transition: transform 0.3s ease; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));"></i>
                    </div>
                    <div id="uploadedImagesContent" class="row g-2"></div>
                </div>

                <div class="mt-4">
                    <button class="btn btn-secondary" onclick="goToStep(2)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                    <button id="continueToPreview" class="btn btn-primary float-end" onclick="validateAndGoToStep4()">
                        Next: Edit & Preview <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 4: Edit & Preview (Assignment Review) -->
    <div class="import-step" id="step4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h5 class="mb-4"><i class="fas fa-edit me-2"></i>Step 4: Edit & Preview Image Assignments</h5>
                
                <!-- Top Navigation Buttons -->
                <div class="step-nav-top">
                    <button class="btn btn-secondary" onclick="goToStep(3)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                    <button class="btn btn-success float-end" onclick="goToStep(5)">
                        Next: Final Preview & Import <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
                
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Review and Edit:</strong> Verify all image assignments below. You can change, add, or remove images before final import.
                </div>

                <div id="editablePreviewContent"></div>

                <!-- Bottom Navigation Buttons -->
                <div class="mt-4">
                    <button class="btn btn-secondary" onclick="goToStep(3)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                    <button class="btn btn-success float-end" onclick="goToStep(5)">
                        Next: Final Preview & Import <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 5: Final Preview & Import -->
    <div class="import-step" id="step5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h5 class="mb-4"><i class="fas fa-check-circle me-2"></i>Step 5: Final Preview & Import</h5>
                
                <!-- Top Navigation Buttons -->
                <div class="step-nav-top">
                    <button type="button" class="btn btn-secondary" onclick="goToStep(4)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                    <button type="button" class="btn btn-success btn-lg float-end" onclick="document.getElementById('bulkSaveForm').submit()">
                        <i class="fas fa-save me-2"></i>Save & Import All Products
                    </button>
                </div>
                
                <div class="alert alert-success mb-4">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Final Review:</strong> This is your last chance to review everything before importing to the database.
                </div>

                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center border-primary">
                            <div class="card-body">
                                <i class="fas fa-box fa-3x text-primary mb-3"></i>
                                <h3 id="totalProducts" class="text-primary">0</h3>
                                <p class="text-muted mb-0">Total Products</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center border-success">
                            <div class="card-body">
                                <i class="fas fa-images fa-3x text-success mb-3"></i>
                                <h3 id="totalImages" class="text-success">0</h3>
                                <p class="text-muted mb-0">Total Images</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center border-info">
                            <div class="card-body">
                                <i class="fas fa-palette fa-3x text-info mb-3"></i>
                                <h3 id="totalVariants" class="text-info">0</h3>
                                <p class="text-muted mb-0">Total Variants</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center border-warning">
                            <div class="card-body">
                                <i class="fas fa-star fa-3x text-warning mb-3"></i>
                                <h3 id="totalReviews" class="text-warning">0</h3>
                                <p class="text-muted mb-0">Total Reviews</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Collapsible Product Preview Section -->
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-header bg-gradient text-white d-flex justify-content-between align-items-center" 
                         style="cursor: pointer; user-select: none; background: linear-gradient(135deg, #0058a3 0%, #003d73 100%);" 
                         onclick="toggleFinalPreview()">
                        <h6 class="mb-0">
                            <i class="fas fa-eye me-2"></i>Product Preview
                        </h6>
                        <i class="fas fa-chevron-down" id="chevron-final-preview" style="transition: transform 0.3s ease;"></i>
                    </div>
                    <div class="card-body p-0" id="final-preview-body" style="display: none;">
                        <!-- Final Preview Grid -->
                        <div id="finalPreviewContent" class="p-3"></div>
                    </div>
                </div>

                <form method="POST" id="bulkSaveForm">
                    <input type="hidden" name="bulk_save" value="1">
                    <input type="hidden" name="products_data" id="productsDataInput">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Ready to import?</strong> All products and images will be saved to the main database. This action cannot be undone.
                    </div>

                    <div class="mt-4">
                        <button type="button" class="btn btn-secondary" onclick="goToStep(4)"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                        <button type="submit" class="btn btn-success btn-lg float-end">
                            <i class="fas fa-save me-2"></i>Save & Import All Products
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- SheetJS Library for Excel/CSV parsing -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
let productsData = [];
// Note: currentStep is declared in bulk_upload_handler.js
let categoriesMap = {};

// Global variables for mapping
let sheetsRawData = {};
let columnMappings = {};
let sheetHeaders = {};

// Initialize categories map from PHP
const categories = <?php echo json_encode($categories); ?>;
categories.forEach(cat => {
    categoriesMap[cat.name.toLowerCase()] = cat.id;
    categoriesMap[cat.id] = cat.id; // Also map by ID
});

// Session persistence - Load saved progress on page load
window.addEventListener('DOMContentLoaded', function() {
    loadSavedProgress();
});

// Save progress to sessionStorage
function saveProgress() {
    try {
        const progressData = {
            currentStep: currentStep,
            productsData: productsData,
            sheetsRawData: sheetsRawData,
            columnMappings: columnMappings,
            sheetHeaders: sheetHeaders,
            timestamp: Date.now()
        };
        sessionStorage.setItem('bulkImportProgress', JSON.stringify(progressData));
        console.log('✓ Progress saved at step', currentStep);
        
        // Show "Clear Progress" button if we're past step 1
        if (currentStep > 1) {
            const clearBtn = document.getElementById('clearProgressBtn');
            if (clearBtn) {
                clearBtn.style.display = 'inline-block';
            }
        }
    } catch (error) {
        console.error('Error saving progress:', error);
    }
}

// Load saved progress from sessionStorage
function loadSavedProgress() {
    try {
        const savedData = sessionStorage.getItem('bulkImportProgress');
        if (!savedData) {
            console.log('No saved progress found');
            return;
        }
        
        const progressData = JSON.parse(savedData);
        
        // Check if data is not too old (24 hours)
        const age = Date.now() - (progressData.timestamp || 0);
        const maxAge = 24 * 60 * 60 * 1000; // 24 hours
        
        if (age > maxAge) {
            console.log('Saved progress is too old, clearing...');
            sessionStorage.removeItem('bulkImportProgress');
            return;
        }
        
        // Restore data
        if (progressData.productsData) {
            productsData = progressData.productsData;
            console.log('✓ Restored productsData:', productsData.length, 'products');
        }
        
        if (progressData.sheetsRawData) {
            sheetsRawData = progressData.sheetsRawData;
            console.log('✓ Restored sheetsRawData');
        }
        
        if (progressData.columnMappings) {
            columnMappings = progressData.columnMappings;
            console.log('✓ Restored columnMappings');
        }
        
        if (progressData.sheetHeaders) {
            sheetHeaders = progressData.sheetHeaders;
            console.log('✓ Restored sheetHeaders');
        }
        
        // Restore step
        if (progressData.currentStep && progressData.currentStep > 1) {
            console.log('✓ Restoring to step', progressData.currentStep);
            
            // Show "Clear Progress" button
            const clearBtn = document.getElementById('clearProgressBtn');
            if (clearBtn) {
                clearBtn.style.display = 'inline-block';
            }
            
            // Show alert about restored progress
            showAlert(`Welcome back! Your progress has been restored. You were on Step ${progressData.currentStep}.`, 'info');
            
            // Navigate to saved step
            setTimeout(() => {
                goToStep(progressData.currentStep);
            }, 100);
        }
        
    } catch (error) {
        console.error('Error loading saved progress:', error);
        sessionStorage.removeItem('bulkImportProgress');
    }
}

// Clear saved progress
function clearProgress() {
    sessionStorage.removeItem('bulkImportProgress');
    console.log('✓ Progress cleared');
}

// Confirm and clear progress (start over)
async function confirmClearProgress() {
    if (await showConfirm('Are you sure you want to start over?\n\nThis will clear all your current progress including:\n• Uploaded data\n• Column mappings\n• Uploaded images\n• Product previews\n\nThis action cannot be undone.', 'Start Over', {confirmText: 'Yes, Start Over', cancelText: 'Cancel', type: 'danger'})) {
        clearProgress();
        
        // Reset all data
        productsData = [];
        sheetsRawData = {};
        columnMappings = {};
        sheetHeaders = {};
        currentStep = 1;
        
        // Reload page
        window.location.reload();
    }
}

// Field definitions for each sheet with tooltips
const sheetFieldDefinitions = {
    products: {
        icon: '📦',
        color: 'products',
        title: 'Products Sheet',
        fields: [
            { name: 'Product No', key: 'productNo', required: true, tooltip: 'Unique identifier for each product' },
            { name: 'Name', key: 'name', required: true, tooltip: 'Product name or title' },
            { name: 'Description', key: 'description', required: false, tooltip: 'Detailed product description' },
            { name: 'Short Description', key: 'short_description', required: false, tooltip: 'Brief product summary' },
            { name: 'Category', key: 'category', required: true, tooltip: 'Product category name or ID' },
            { name: 'Original Price', key: 'original_price', required: true, tooltip: 'Regular price before discount' },
            { name: 'Discounted Price', key: 'discounted_price', required: false, tooltip: 'Sale or discounted price' },
            { name: 'Commission', key: 'commission', required: false, tooltip: 'Commission amount in PKR' },
            { name: 'Delivery Charges', key: 'delivery_charges', required: false, tooltip: 'Shipping/delivery fee' },
            { name: 'Stock', key: 'stock', required: false, tooltip: 'Available stock quantity' },
            { name: 'Status', key: 'status', required: false, tooltip: 'Product status (In Stock, Out of Stock, etc.)' },
            { name: 'Display Location', key: 'display_location', required: false, tooltip: 'Where to display (Homepage, Shop Page, Both)' },
            { name: 'Keywords', key: 'keywords', required: false, tooltip: 'SEO keywords or tags' },
            { name: 'Image URL', key: 'image_url', required: false, tooltip: 'Product image URL or path' }
        ]
    },
    features: {
        icon: '⭐',
        color: 'features',
        title: 'Features Sheet',
        fields: [
            { name: 'Product No', key: 'productNo', required: true, tooltip: 'Must match Product No in Products sheet' },
            { name: 'Feature Name', key: 'feature_name', required: true, tooltip: 'Name of the feature (e.g., Material, Size)' },
            { name: 'Feature Description', key: 'feature_description', required: false, tooltip: 'Feature value or description' }
        ]
    },
    variants: {
        icon: '🎨',
        color: 'variants',
        title: 'Variants Sheet',
        subtitle: 'Supports TWO formats: Simple (old) OR Combination (new)',
        fields: [
            // Common field
            { name: 'Product No', key: 'productNo', required: true, tooltip: 'Must match Product No in Products sheet' },
            
            // Simple Variants Format (OLD - Still Supported)
            { name: 'Variant Type', key: 'variant_type', required: false, tooltip: '(Simple Format) Type of variant (Color, Size, etc.)' },
            { name: 'Variant Name', key: 'variant_name', required: false, tooltip: '(Simple Format) Variant option name (e.g., Red, Large)' },
            
            // Combination Variants Format (NEW)
            { name: 'Color', key: 'color', required: false, tooltip: '(Combination Format) Color attribute value' },
            { name: 'Size', key: 'size', required: false, tooltip: '(Combination Format) Size attribute value' },
            { name: 'Design', key: 'design', required: false, tooltip: '(Combination Format) Design/Style attribute value' },
            
            // Common price/stock fields
            { name: 'Variant Price', key: 'variant_price', required: false, tooltip: 'Sale/discounted price for this variant/combination' },
            { name: 'Variant Original Price', key: 'variant_original_price', required: false, tooltip: 'Original/regular price for this variant/combination' },
            { name: 'Stock', key: 'stock', required: false, tooltip: 'Stock quantity for this variant/combination' },
            { name: 'Image URL', key: 'image_url', required: false, tooltip: 'Variant-specific image URL' }
        ],
        formatNote: '📌 <strong>Format Detection:</strong> System auto-detects format. If sheet has Color/Size/Design columns = Combination Format. If it has Variant Type/Name = Simple Format.'
    },
    reviews: {
        icon: '💬',
        color: 'reviews',
        title: 'Reviews Sheet',
        fields: [
            { name: 'Product No', key: 'productNo', required: true, tooltip: 'Must match Product No in Products sheet' },
            { name: 'Reviewer Name', key: 'reviewer_name', required: true, tooltip: 'Name of the customer/reviewer' },
            { name: 'Rating', key: 'rating', required: true, tooltip: 'Rating score (1-5)' },
            { name: 'Review Text', key: 'review_text', required: false, tooltip: 'Review comment or feedback' }
        ]
    },
    soldInfo: {
        icon: '📊',
        color: 'soldinfo',
        title: 'Sold Info Sheet',
        fields: [
            { name: 'Product No', key: 'productNo', required: true, tooltip: 'Must match Product No in Products sheet' },
            { name: 'Units Sold', key: 'units_sold', required: false, tooltip: 'Number of units sold' },
            { name: 'Views', key: 'views', required: false, tooltip: 'Product view count' },
            { name: 'Stock Count', key: 'stock_count', required: false, tooltip: 'Current stock level' }
        ]
    }
};

// Auto-mapping keywords for intelligent field detection
const autoMapKeywords = {
    productNo: ['product no', 'product number', 'product id', 'productno', 'productid', 'id', 'no', 'product_no', 'product_id'],
    name: ['name', 'product name', 'title', 'product title', 'product_name'],
    description: ['description', 'desc', 'details', 'product description', 'long description'],
    short_description: ['short description', 'short desc', 'summary', 'brief', 'short_description'],
    category: ['category', 'cat', 'type', 'product category', 'category name', 'categoryname', 'product_category'],
    original_price: ['original price', 'price', 'mrp', 'regular price', 'original_price', 'base price'],
    discounted_price: ['discounted price', 'discount price', 'sale price', 'discounted_price', 'discount', 'sale_price'],
    commission: ['commission', 'commission rate', 'commission (pkr)', 'commission_pkr', 'fee'],
    delivery_charges: ['delivery charges', 'delivery', 'shipping', 'shipping charges', 'delivery_charges'],
    stock: ['stock', 'stock count', 'quantity', 'qty', 'stock_count', 'available'],
    status: ['status', 'product status', 'state', 'availability'],
    display_location: ['display location', 'display', 'location', 'show on', 'display_location'],
    keywords: ['keywords', 'tags', 'keyword', 'seo keywords', 'search terms'],
    image_url: ['image url', 'image', 'image path', 'image_url', 'img', 'picture'],
    feature_name: ['feature name', 'feature', 'feature_name', 'property'],
    feature_description: ['feature description', 'feature value', 'value', 'feature_description'],
    // Simple Variants (OLD)
    variant_type: ['variant type', 'variant_type', 'option type', 'color/size', 'variation type', 'type'],
    variant_name: ['variant name', 'variant_name', 'variant value', 'option name', 'variant', 'option', 'name'],
    
    // Combination Variants (NEW)
    color: ['color', 'colour', 'Color', 'Colour', 'COLOR', 'COLOUR'],
    size: ['size', 'Size', 'SIZE'],
    design: ['design', 'Design', 'DESIGN', 'style', 'Style', 'STYLE', 'pattern', 'Pattern'],
    
    // Common price/stock
    variant_price: ['variant price', 'variant_price', 'combination price', 'variant sale price', 'sale price', 'discounted price', 'price'],
    variant_original_price: ['variant original price', 'variant_original_price', 'variant mrp', 'original price', 'mrp', 'regular price'],
    reviewer_name: ['reviewer name', 'reviewer_name', 'customer name', 'reviewer', 'user name', 'user'],
    rating: ['rating', 'stars', 'score', 'review rating'],
    review_text: ['review text', 'review_text', 'review comment', 'review description', 'comment', 'feedback', 'review'],
    units_sold: ['units sold', 'sold', 'sales', 'units_sold', 'quantity sold'],
    views: ['views', 'view count', 'page views', 'impressions'],
    stock_count: ['stock count', 'stock', 'inventory', 'stock_count']
};

function validateAndGoToStep4() {
    console.log('validateAndGoToStep4 called');
    console.log('productsData exists:', !!productsData);
    console.log('productsData length:', productsData ? productsData.length : 0);
    
    if (!productsData || productsData.length === 0) {
        showAlert('⚠️ Please complete Steps 1 & 2 first!\n\nYou need to:\n1. Upload your Excel/CSV file in Step 1\n2. Map your data in Step 2\n\nThen you can upload images and proceed to preview.', 'warning', 'Missing Data');
        goToStep(1); // Send them back to Step 1
        return;
    }
    
    // Data exists, proceed to Step 4
    goToStep(4);
}

/**
 * Save products data to PHP session for use in Step 3 (dynamic variant image assignment)
 */
function saveProductsToSession(products) {
    console.log('Saving products to session for dynamic variant detection...');
    
    fetch('ajax/save_session_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            products: products
        }),
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✓ Session data saved: ${data.productCount} products with variant info`);
        } else {
            console.warn('Failed to save session data:', data.message);
        }
    })
    .catch(error => {
        console.error('Error saving session data:', error);
    });
}

function goToStep(step) {
    console.log('goToStep called with step:', step);
    console.log('Current productsData:', productsData);
    
    // Hide all steps
    document.querySelectorAll('.import-step').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.step-item').forEach(s => {
        s.classList.remove('active', 'completed');
    });
    
    // Show target step
    document.getElementById('step' + step).classList.add('active');
    document.getElementById('stepIndicator' + step).classList.add('active');
    
    // Mark previous steps as completed
    for (let i = 1; i < step; i++) {
        document.getElementById('stepIndicator' + i).classList.add('completed');
    }
    
    currentStep = step;
    
    // Save progress to sessionStorage
    saveProgress();
    
    // Execute step-specific functions
    if (step === 2) {
        console.log('Rendering Step 2 mapping interface');
        if (Object.keys(sheetHeaders).length > 0) {
            displayMappingInterface();
        }
    } else if (step === 4) {
        console.log('Rendering Step 4 preview');
        renderEditablePreview();
    } else if (step === 5) {
        console.log('Rendering Step 5 preview');
        renderFinalPreview();
        updateSummary();
    }
}

function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    document.getElementById('loadingIndicator').style.display = 'block';
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            
            processWorkbookForMapping(workbook);
            
            document.getElementById('loadingIndicator').style.display = 'none';
            goToStep(2);
        } catch (error) {
            document.getElementById('loadingIndicator').style.display = 'none';
            showAlert('Error reading file: ' + error.message, 'error');
        }
    };
    
    reader.readAsArrayBuffer(file);
}

function handleGoogleSheetImport() {
    const url = document.getElementById('googleSheetInput').value.trim();
    if (!url) {
        showAlert('Please enter a Google Sheets URL', 'warning');
        return;
    }
    
    const match = url.match(/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/);
    if (!match) {
        showAlert('Invalid Google Sheets URL', 'error');
        return;
    }
    
    const sheetId = match[1];
    const csvUrl = `https://docs.google.com/spreadsheets/d/${sheetId}/export?format=xlsx`;
    
    document.getElementById('loadingIndicator').style.display = 'block';
    
    fetch(csvUrl)
        .then(response => response.arrayBuffer())
        .then(data => {
            const workbook = XLSX.read(data, { type: 'array' });
            processWorkbookForMapping(workbook);
            document.getElementById('loadingIndicator').style.display = 'none';
            goToStep(2);
        })
        .catch(error => {
            document.getElementById('loadingIndicator').style.display = 'none';
            showAlert('Error loading Google Sheet. Make sure it is publicly accessible.', 'error');
        });
}

function processWorkbookForMapping(workbook) {
    // Show processing alert
    showAlert('Processing sheets...', 'info');
    
    try {
        // Initialize data storage
        sheetsRawData = {};
        columnMappings = {};
        sheetHeaders = {};
        
        // Process each sheet
        workbook.SheetNames.forEach((sheetName, index) => {
            const sheet = workbook.Sheets[sheetName];
            const data = XLSX.utils.sheet_to_json(sheet, { header: 1 });
            
            if (data.length < 2) return; // Skip empty sheets
            
            const headers = data[0];
            const rows = data.slice(1);
            
            // Convert rows to objects with column names
            const rowObjects = [];
            rows.forEach(row => {
                if (!row || row.length === 0) return;
                const rowObj = {};
                headers.forEach((header, idx) => {
                    if (header && row[idx] !== undefined) {
                        rowObj[String(header).trim()] = row[idx];
                    }
                });
                if (Object.keys(rowObj).length > 0) {
                    rowObjects.push(rowObj);
                }
            });
            
            // Store raw data as objects
            sheetsRawData[sheetName] = {
                headers: headers,
                rows: rowObjects
            };
            
            // Store headers
            sheetHeaders[sheetName] = headers;
            
            // Initialize column mappings
            columnMappings[sheetName] = {};
            
            // Auto-map columns
            headers.forEach((header, idx) => {
                const h = String(header).toLowerCase();
                Object.keys(autoMapKeywords).forEach(key => {
                    if (autoMapKeywords[key].includes(h)) {
                        columnMappings[sheetName][idx] = key;
                    }
                });
            });
        });
        
        // Show mapping interface
        displayMappingInterface();
        
    } catch (error) {
        console.error('Error processing workbook:', error);
        showAlert('Error processing sheets: ' + error.message, 'error');
    }
}

function displayMappingInterface() {
    console.log('Displaying mapping interface...');
    console.log('sheetHeaders:', sheetHeaders);
    
    const sheetCount = Object.keys(sheetHeaders).length;
    console.log('Sheet count:', sheetCount);
    
    if (sheetCount === 0) {
        console.error('No sheets found!');
        document.getElementById('mappingContent').innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><strong>Error:</strong> No sheets found in the uploaded file. Please check your file and try again.</div>';
        document.getElementById('mappingActions').style.display = 'none';
        return;
    }
    
    let html = '';
    
    // Success message
    html += '<div class="alert alert-success mb-4"><i class="fas fa-check-circle me-2"></i><strong>Success!</strong> Found <strong>' + sheetCount + '</strong> sheet(s) ready to map.</div>';
    
    // Mapping interface for each sheet
    let sheetIndex = 0;
    Object.keys(sheetHeaders).forEach(sheetName => {
        html += renderSheetMapping(sheetName, sheetIndex);
        sheetIndex++;
    });
    
    console.log('Setting HTML content...');
    document.getElementById('mappingContent').innerHTML = html;
    document.getElementById('mappingActions').style.display = 'block';
    document.getElementById('mappingActionsTop').style.display = 'block';
    
    // Save progress after displaying mapping interface
    saveProgress();
    
    console.log('Mapping interface displayed successfully');
}

function renderVariantFormatDetection(headers) {
    // Detect if it's combination format or simple format
    const headerLower = headers.map(h => String(h).toLowerCase());
    const hasColor = headerLower.some(h => h === 'color' || h === 'colour');
    const hasSize = headerLower.some(h => h === 'size');
    const hasDesign = headerLower.some(h => h === 'design' || h === 'style' || h === 'pattern');
    
    const isCombination = hasColor || hasSize || hasDesign;
    const hasVariantType = headerLower.some(h => h.includes('variant type') || h.includes('variant_type'));
    const hasVariantName = headerLower.some(h => h.includes('variant name') || h.includes('variant_name'));
    const isSimple = hasVariantType || hasVariantName;
    
    let detectedFormat = 'Unknown';
    let alertClass = 'alert-info';
    let icon = '🔍';
    let message = 'Cannot determine format. Please map columns manually.';
    
    if (isCombination) {
        detectedFormat = 'Combination Variants (NEW)';
        alertClass = 'alert-success';
        icon = '🎨';
        message = '<strong>Combination Format Detected!</strong> Your sheet has ';
        const detected = [];
        if (hasColor) detected.push('<strong>Color</strong>');
        if (hasSize) detected.push('<strong>Size</strong>');
        if (hasDesign) detected.push('<strong>Design/Style</strong>');
        message += detected.join(', ') + ' columns. Each row will be treated as one unique combination with its own price and stock.';
    } else if (isSimple) {
        detectedFormat = 'Simple Variants (OLD)';
        alertClass = 'alert-warning';
        icon = '📦';
        message = '<strong>Simple Format Detected!</strong> Your sheet has <strong>Variant Type</strong> and <strong>Variant Name</strong> columns. This is the old format (still supported).';
    }
    
    return `
        <div class="alert ${alertClass} mb-3" style="border-left: 4px solid currentColor;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.8em;">${icon}</span>
                <div style="flex: 1;">
                    <div style="font-size: 1.1em; margin-bottom: 5px;">${message}</div>
                    <div style="font-size: 0.9em; opacity: 0.9;">
                        <strong>Format:</strong> ${detectedFormat}
                    </div>
                </div>
            </div>
            ${isCombination ? `
                <div class="mt-3 p-3" style="background: rgba(255,255,255,0.3); border-radius: 8px;">
                    <strong>💡 Tip:</strong> Combination format example:<br>
                    <code style="background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 4px;">Product No | Color | Size | Design | Variant Price | Stock</code>
                </div>
            ` : isSimple ? `
                <div class="mt-3 p-3" style="background: rgba(255,255,255,0.3); border-radius: 8px;">
                    <strong>💡 Tip:</strong> Simple format example:<br>
                    <code style="background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 4px;">Product No | Variant Type | Variant Name | Variant Price</code>
                </div>
            ` : ''}
        </div>
    `;
}

function renderSheetMapping(sheetName, index) {
    const headers = sheetHeaders[sheetName];
    const sheetData = sheetsRawData[sheetName];
    const rowCount = sheetData && sheetData.rows ? sheetData.rows.length : 0;
    
    // Get field definitions based on sheet type
    let fieldDefs = getFieldDefinitionsForSheet(sheetName, index);
    let sheetColor = getSheetColor(sheetName, index);
    let sheetIcon = getSheetIcon(sheetName, index);
    let sheetTitle = getSheetTitle(sheetName, index);
    
    // Auto-map columns
    let mappings = autoMapColumns(headers, fieldDefs);
    
    // Store mappings in global columnMappings object
    if (!columnMappings[sheetName]) {
        columnMappings[sheetName] = {};
    }
    columnMappings[sheetName] = { ...columnMappings[sheetName], ...mappings };
    
    console.log('Stored mappings for', sheetName, ':', columnMappings[sheetName]);
    
    let html = `
        <div class="mapping-sheet-card mb-4">
            <div class="mapping-sheet-header ${sheetColor}" onclick="toggleMappingSheet('${sheetName}')">
                <div>
                    <span style="font-size: 1.2em; margin-right: 8px;">${sheetIcon}</span>
                    <strong>${sheetTitle}</strong>
                    <span class="badge bg-white text-dark ms-2">${headers.length} columns</span>
                    <span class="badge bg-white text-dark ms-1">${rowCount} rows</span>
                </div>
                <i class="fas fa-chevron-down chevron-icon" id="chevron-${sheetName}"></i>
            </div>
            <div class="mapping-sheet-body" id="mapping-body-${sheetName}">
                ${sheetTitle.includes('Variant') ? renderVariantFormatDetection(headers) : ''}
                <div class="table-responsive">
                    <table class="mapping-table">
                        <thead>
                            <tr>
                                <th style="width: 25%;">Database Field</th>
                                <th style="width: 30%;">Auto-Detected</th>
                                <th style="width: 30%;">Manual Selection</th>
                                <th style="width: 15%;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
    `;
    
    // Render each field
    fieldDefs.forEach(field => {
        const autoMapped = mappings[field.key];
        const isMapped = autoMapped !== null && autoMapped !== undefined && autoMapped !== '';
        const isRequired = field.required;
        
        html += `
            <tr>
                <td>
                    <strong>${field.name}</strong>
                    ${isRequired ? '<span class="text-danger">*</span>' : ''}
                    <span class="field-tooltip" title="${field.tooltip}">?</span>
                    <br>
                    <small class="text-muted">${field.key}</small>
                </td>
                <td>
                    ${isMapped ? '<span class="badge bg-success">' + autoMapped + '</span>' : '<span class="text-muted">Not detected</span>'}
                </td>
                <td>
                    <select class="mapping-select ${isMapped ? 'mapped' : 'unmapped'}" 
                            data-sheet="${sheetName}" 
                            data-field="${field.key}"
                            onchange="updateFieldMapping('${sheetName}', '${field.key}', this.value)">
                        <option value="">-- Select Column --</option>
                        ${headers.map(h => `<option value="${h}" ${h === autoMapped ? 'selected' : ''}>${h}</option>`).join('')}
                    </select>
                </td>
                <td class="text-center">
                    ${isMapped ? 
                        '<span class="status-badge mapped">✓ Mapped</span>' : 
                        (isRequired ? '<span class="status-badge required">⚠ Required</span>' : '<span class="status-badge unmapped">- Optional</span>')}
                </td>
            </tr>
        `;
    });
    
    html += `
                        </tbody>
                    </table>
                </div>
                
                <!-- Data Preview -->
                <div class="mt-3">
                    <h6 class="mb-2"><i class="fas fa-eye me-2"></i>Data Preview (First 3 Rows)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    ${headers.map(h => `<th class="text-nowrap" style="font-size: 11px;">${h}</th>`).join('')}
                                </tr>
                            </thead>
                            <tbody>
                                ${sheetData && sheetData.rows ? sheetData.rows.slice(0, 3).map(row => `
                                    <tr>
                                        ${headers.map(h => `<td style="font-size: 11px;">${row[h] || ''}</td>`).join('')}
                                    </tr>
                                `).join('') : '<tr><td colspan="' + headers.length + '" class="text-center">No data</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Mapping Stats -->
                <div class="mapping-stats mt-3">
                    <div class="mapping-stat">
                        <i class="fas fa-check-circle text-success me-1"></i>
                        <span id="mapped-count-${sheetName}">0</span> Mapped
                    </div>
                    <div class="mapping-stat">
                        <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                        <span id="unmapped-count-${sheetName}">0</span> Unmapped
                    </div>
                    <div class="mapping-stat">
                        <i class="fas fa-star text-danger me-1"></i>
                        <span id="required-count-${sheetName}">0</span> Required
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Update stats after rendering
    setTimeout(() => updateMappingStats(sheetName, fieldDefs, mappings), 100);
    
    return html;
}

function getFieldDefinitionsForSheet(sheetName, index) {
    const lowerName = sheetName.toLowerCase();
    
    // Try name-based detection first
    if (lowerName.includes('product') && !lowerName.includes('sold')) {
        return sheetFieldDefinitions.products.fields;
    } else if (lowerName.includes('feature')) {
        return sheetFieldDefinitions.features.fields;
    } else if (lowerName.includes('variant')) {
        return sheetFieldDefinitions.variants.fields;
    } else if (lowerName.includes('review')) {
        return sheetFieldDefinitions.reviews.fields;
    } else if (lowerName.includes('sold') || lowerName.includes('info')) {
        return sheetFieldDefinitions.soldInfo.fields;
    }
    
    // Fallback to index-based detection
    switch (index) {
        case 0: return sheetFieldDefinitions.products.fields;
        case 1: return sheetFieldDefinitions.features.fields;
        case 2: return sheetFieldDefinitions.variants.fields;
        case 3: return sheetFieldDefinitions.reviews.fields;
        case 4: return sheetFieldDefinitions.soldInfo.fields;
        default: return sheetFieldDefinitions.products.fields;
    }
}

function getSheetColor(sheetName, index) {
    const lowerName = sheetName.toLowerCase();
    
    if (lowerName.includes('product') && !lowerName.includes('sold')) return 'products';
    if (lowerName.includes('feature')) return 'features';
    if (lowerName.includes('variant')) return 'variants';
    if (lowerName.includes('review')) return 'reviews';
    if (lowerName.includes('sold') || lowerName.includes('info')) return 'soldinfo';
    
    // Fallback to index
    switch (index) {
        case 0: return 'products';
        case 1: return 'features';
        case 2: return 'variants';
        case 3: return 'reviews';
        case 4: return 'soldinfo';
        default: return 'products';
    }
}

function getSheetIcon(sheetName, index) {
    const lowerName = sheetName.toLowerCase();
    
    if (lowerName.includes('product') && !lowerName.includes('sold')) return '📦';
    if (lowerName.includes('feature')) return '⭐';
    if (lowerName.includes('variant')) return '🎨';
    if (lowerName.includes('review')) return '💬';
    if (lowerName.includes('sold') || lowerName.includes('info')) return '📊';
    
    // Fallback to index
    switch (index) {
        case 0: return '📦';
        case 1: return '⭐';
        case 2: return '🎨';
        case 3: return '💬';
        case 4: return '📊';
        default: return '📄';
    }
}

function getSheetTitle(sheetName, index) {
    const lowerName = sheetName.toLowerCase();
    
    if (lowerName.includes('product') && !lowerName.includes('sold')) return 'Products Sheet';
    if (lowerName.includes('feature')) return 'Features Sheet';
    if (lowerName.includes('variant')) return 'Variants Sheet';
    if (lowerName.includes('review')) return 'Reviews Sheet';
    if (lowerName.includes('sold') || lowerName.includes('info')) return 'Sold Info Sheet';
    
    // Fallback to index
    switch (index) {
        case 0: return 'Products Sheet';
        case 1: return 'Features Sheet';
        case 2: return 'Variants Sheet';
        case 3: return 'Reviews Sheet';
        case 4: return 'Sold Info Sheet';
        default: return sheetName;
    }
}

function autoMapColumns(headers, fieldDefs) {
    const mappings = {};
    const usedHeaders = new Set(); // Track which headers have been mapped
    
    // Sort field definitions to prioritize more specific fields first
    const sortedFieldDefs = [...fieldDefs].sort((a, b) => {
        // Prioritize required fields
        if (a.required !== b.required) return b.required ? 1 : -1;
        // Prioritize fields with longer, more specific keywords
        const aKeywords = autoMapKeywords[a.key] || [];
        const bKeywords = autoMapKeywords[b.key] || [];
        const aMaxLen = Math.max(...aKeywords.map(k => k.length), 0);
        const bMaxLen = Math.max(...bKeywords.map(k => k.length), 0);
        return bMaxLen - aMaxLen;
    });
    
    sortedFieldDefs.forEach(field => {
        const keywords = autoMapKeywords[field.key] || [];
        let matched = null;
        let bestMatchScore = 0;
        
        for (const header of headers) {
            // Skip if this header was already mapped
            if (usedHeaders.has(header)) continue;
            
            const h = String(header).toLowerCase().trim();
            
            for (const keyword of keywords) {
                let score = 0;
                
                // Exact match (highest priority)
                if (h === keyword) {
                    score = 1000;
                }
                // Starts with keyword (high priority)
                else if (h.startsWith(keyword)) {
                    score = 500;
                }
                // Ends with keyword (medium-high priority)
                else if (h.endsWith(keyword)) {
                    score = 300;
                }
                // Contains keyword (lowest priority)
                else if (h.includes(keyword)) {
                    score = 100;
                }
                
                // Bonus for longer keyword matches (more specific)
                score += keyword.length;
                
                if (score > bestMatchScore) {
                    bestMatchScore = score;
                    matched = header;
                }
            }
        }
        
        // Only store if a match was found and meets minimum threshold
        if (matched !== null && matched !== '' && bestMatchScore >= 100) {
            mappings[field.key] = matched;
            usedHeaders.add(matched); // Mark this header as used
        }
    });
    
    return mappings;
}

function toggleMappingSheet(sheetName) {
    const body = document.getElementById('mapping-body-' + sheetName);
    const chevron = document.getElementById('chevron-' + sheetName);
    
    if (body.classList.contains('active')) {
        body.classList.remove('active');
        chevron.classList.remove('rotated');
    } else {
        body.classList.add('active');
        chevron.classList.add('rotated');
    }
}

function updateFieldMapping(sheetName, fieldKey, columnName) {
    if (!columnMappings[sheetName]) {
        columnMappings[sheetName] = {};
    }
    
    columnMappings[sheetName][fieldKey] = columnName;
    
    console.log('Updated mapping:', sheetName, fieldKey, columnName);
    
    // Update stats
    const fieldDefs = getFieldDefinitionsForSheet(sheetName, 0); // Approximate index, could be improved
    updateMappingStats(sheetName, fieldDefs, columnMappings[sheetName]);
}

function updateMappingStats(sheetName, fieldDefs, mappings) {
    let mappedCount = 0;
    let unmappedCount = 0;
    let requiredCount = 0;
    
    fieldDefs.forEach(field => {
        const isMapped = mappings && mappings[field.key] && mappings[field.key] !== '' && mappings[field.key] !== null;
        
        if (isMapped) {
            mappedCount++;
        } else {
            unmappedCount++;
            if (field.required) {
                requiredCount++;
            }
        }
    });
    
    const mappedEl = document.getElementById('mapped-count-' + sheetName);
    const unmappedEl = document.getElementById('unmapped-count-' + sheetName);
    const requiredEl = document.getElementById('required-count-' + sheetName);
    
    if (mappedEl) mappedEl.textContent = mappedCount;
    if (unmappedEl) unmappedEl.textContent = unmappedCount;
    if (requiredEl) requiredEl.textContent = requiredCount;
}

function saveMappings() {
    // Validate that required fields are mapped for each sheet
    let allValid = true;
    let errors = [];
    
    Object.keys(sheetHeaders).forEach((sheetName, index) => {
        const fieldDefs = getFieldDefinitionsForSheet(sheetName, index);
        const mappings = columnMappings[sheetName] || {};
        
        fieldDefs.forEach(field => {
            if (field.required && (!mappings[field.key] || mappings[field.key] === '' || mappings[field.key] === null)) {
                allValid = false;
                errors.push(`"${field.name}" in ${getSheetTitle(sheetName, index)}`);
            }
        });
    });
    
    if (!allValid) {
        showAlert('Required fields are not mapped:\n\n' + errors.join('\n') + '\n\nPlease map all required fields before continuing.', 'error', 'Missing Required Fields');
        return;
    }
    
    console.log('All mappings validated successfully:', columnMappings);
    console.log('Processing data with mappings...');
    
    // Process the raw data with the column mappings
    processDataWithMappings();
    
    // Save progress after processing
    saveProgress();
    
    // Proceed to next step
    goToStep(3);
}

function processDataWithMappings() {
    console.log('processDataWithMappings called');
    console.log('sheetsRawData:', sheetsRawData);
    console.log('columnMappings:', columnMappings);
    
    try {
        // Initialize data storage
        const sheetsData = {
            products: [],
            features: [],
            variants: [],
            reviews: [],
            soldInfo: []
        };
        
        // Process each sheet with its mappings
        Object.keys(sheetsRawData).forEach((sheetName, sheetIndex) => {
            const rawData = sheetsRawData[sheetName];
            const mappings = columnMappings[sheetName] || {};
            
            console.log(`Processing ${sheetName} with ${rawData.rows.length} rows`);
            
            // Determine sheet type with improved detection
            let sheetType = sheetName;
            if (!sheetsData.hasOwnProperty(sheetType)) {
                // Try to match to known sheet types
                const lowerName = sheetName.toLowerCase();
                if (lowerName.includes('product') && !lowerName.includes('sold')) {
                    sheetType = 'products';
                } else if (lowerName.includes('feature')) {
                    sheetType = 'features';
                } else if (lowerName.includes('variant')) {
                    sheetType = 'variants';
                } else if (lowerName.includes('review')) {
                    sheetType = 'reviews';
                } else if (lowerName.includes('sold') || lowerName.includes('info') || lowerName.includes('sale')) {
                    sheetType = 'soldInfo';
                } else {
                    // Fall back to index-based detection
                    if (sheetIndex === 0) sheetType = 'products';
                    else if (sheetIndex === 1) sheetType = 'features';
                    else if (sheetIndex === 2) sheetType = 'variants';
                    else if (sheetIndex === 3) sheetType = 'reviews';
                    else if (sheetIndex === 4) sheetType = 'soldInfo';
                    else sheetType = 'products'; // Default to products
                }
            }
            
            console.log(`  Detected sheetType: ${sheetType}`);
            
            // Process each row
            rawData.rows.forEach((row, rowIdx) => {
                const mappedRow = {};
                
                // Apply column mappings
                Object.keys(mappings).forEach(fieldKey => {
                    const columnName = mappings[fieldKey];
                    if (columnName && row.hasOwnProperty(columnName)) {
                        mappedRow[fieldKey] = row[columnName];
                    }
                });
                
                // Debug first row with detailed information
                if (rowIdx === 0 && sheetType === 'products') {
                    console.log(`=== PRODUCTS SHEET DATA EXTRACTION DEBUG ===`);
                    console.log(`Sheet: ${sheetName}`);
                    console.log('All column mappings:', mappings);
                    console.log('Original row from sheet:', row);
                    console.log('Mapped row result:', mappedRow);
                    
                    // Check specific fields
                    console.log('\nField extraction:');
                    if (mappings.original_price) {
                        console.log(`  original_price -> "${mappings.original_price}" = "${row[mappings.original_price]}"`);
                    }
                    if (mappings.discounted_price) {
                        console.log(`  discounted_price -> "${mappings.discounted_price}" = "${row[mappings.discounted_price]}"`);
                    }
                    if (mappings.status) {
                        console.log(`  status -> "${mappings.status}" = "${row[mappings.status]}"`);
                    }
                    if (mappings.display_location) {
                        console.log(`  display_location -> "${mappings.display_location}" = "${row[mappings.display_location]}"`);
                    }
                    if (mappings.category) {
                        console.log(`  category -> "${mappings.category}" = "${row[mappings.category]}"`);
                    }
                    console.log('==========================================');
                }
                
                // Debug first variant row
                if (rowIdx === 0 && sheetType === 'variants') {
                    console.log(`=== VARIANTS SHEET DATA EXTRACTION DEBUG ===`);
                    console.log(`Sheet: ${sheetName}`);
                    console.log('Variant column mappings:', mappings);
                    console.log('Original variant row from sheet:', row);
                    console.log('Mapped variant row result:', mappedRow);
                    
                    // Check variant fields
                    console.log('\nVariant field extraction:');
                    if (mappings.variant_price) {
                        console.log(`  variant_price -> "${mappings.variant_price}" = "${row[mappings.variant_price]}"`);
                    }
                    if (mappings.variant_original_price) {
                        console.log(`  variant_original_price -> "${mappings.variant_original_price}" = "${row[mappings.variant_original_price]}"`);
                    }
                    console.log('==========================================');
                }
                
                // Debug first sold info row
                if (rowIdx === 0 && sheetType === 'soldInfo') {
                    console.log(`=== SOLD INFO SHEET DATA EXTRACTION DEBUG ===`);
                    console.log(`Sheet: ${sheetName}`);
                    console.log('Sold info column mappings:', mappings);
                    console.log('Original sold info row from sheet:', row);
                    console.log('Mapped sold info row result:', mappedRow);
                    
                    // Check sold info fields
                    console.log('\nSold info field extraction:');
                    if (mappings.units_sold) {
                        console.log(`  units_sold -> "${mappings.units_sold}" = "${row[mappings.units_sold]}"`);
                    }
                    if (mappings.stock_count) {
                        console.log(`  stock_count -> "${mappings.stock_count}" = "${row[mappings.stock_count]}"`);
                    }
                    if (mappings.views) {
                        console.log(`  views -> "${mappings.views}" = "${row[mappings.views]}"`);
                    }
                    console.log('==========================================');
                }
                
                // Ensure productNo exists
                if (mappedRow.productNo || mappedRow.Product_No || mappedRow['Product No']) {
                    mappedRow.productNo = mappedRow.productNo || mappedRow.Product_No || mappedRow['Product No'];
                    sheetsData[sheetType].push(mappedRow);
                }
            });
            
            console.log(`  ${sheetType}: processed ${sheetsData[sheetType].length} rows`);
        });
        
        // Log summary of all processed data
        console.log('=== PROCESSING SUMMARY ===');
        console.log('Products:', sheetsData.products.length, 'rows');
        console.log('Features:', sheetsData.features.length, 'rows');
        console.log('Variants:', sheetsData.variants.length, 'rows');
        console.log('Reviews:', sheetsData.reviews.length, 'rows');
        console.log('SoldInfo:', sheetsData.soldInfo.length, 'rows');
        console.log('==========================');
        
        // Merge data by Product Number
        productsData = mergeProductData(sheetsData);
        
        console.log('productsData after merge:', productsData);
        console.log('Total products:', productsData.length);
        
        if (productsData.length === 0) {
            showAlert('No products found after mapping. Please check your column mappings.', 'warning');
            return;
        }
        
        // Save products data to session for use in Step 3 (image upload with dynamic variant detection)
        saveProductsToSession(productsData);
        
        // Save progress to sessionStorage
        saveProgress();
        
        showAlert(`Successfully processed ${productsData.length} products!`, 'success');
        
    } catch (error) {
        console.error('Error processing data with mappings:', error);
        showAlert('Error processing data: ' + error.message, 'error');
    }
}

function processWorkbook(workbook) {
    try {
        // Initialize data storage
        const sheetsData = {
            products: [],
            features: [],
            variants: [],
            reviews: [],
            soldInfo: []
        };
        
        // Expected sheet names (case-insensitive)
        const sheetMapping = {
            'products': 0,
            'features': 1,
            'variants': 2,
            'reviews': 3,
            'sold info': 4,
            'soldinfo': 4
        };
        
        // Process each sheet
        workbook.SheetNames.forEach((sheetName, index) => {
            const sheet = workbook.Sheets[sheetName];
            const data = XLSX.utils.sheet_to_json(sheet, { header: 1 });
            
            if (data.length < 2) return; // Skip empty sheets
            
            const headers = data[0];
            const rows = data.slice(1);
            
            // Determine sheet type
            let sheetType = null;
            const lowerName = sheetName.toLowerCase();
            
            if (lowerName.includes('product') && !lowerName.includes('sold')) sheetType = 'products';
            else if (lowerName.includes('feature')) sheetType = 'features';
            else if (lowerName.includes('variant')) sheetType = 'variants';
            else if (lowerName.includes('review')) sheetType = 'reviews';
            else if (lowerName.includes('sold') || lowerName.includes('info')) sheetType = 'soldInfo';
            else if (index === 0) sheetType = 'products';
            else if (index === 1) sheetType = 'features';
            else if (index === 2) sheetType = 'variants';
            else if (index === 3) sheetType = 'reviews';
            else if (index === 4) sheetType = 'soldInfo';
            
            if (!sheetType) return;
            
            // Find Product Number column
            let productNoCol = -1;
            headers.forEach((header, idx) => {
                const h = String(header).toLowerCase();
                if (h.includes('product') && (h.includes('no') || h.includes('number') || h.includes('id'))) {
                    productNoCol = idx;
                }
            });
            
            // If no Product No column found, use first column
            if (productNoCol === -1) productNoCol = 0;
            
            // Process rows
            rows.forEach(row => {
                if (!row || !row[productNoCol]) return;
                
                const productNo = String(row[productNoCol]).trim();
                const rowData = { productNo };
                
                headers.forEach((header, idx) => {
                    if (idx !== productNoCol && header) {
                        rowData[String(header).trim()] = row[idx];
                    }
                });
                
                sheetsData[sheetType].push(rowData);
            });
        });
        
        // Merge data by Product Number
        productsData = mergeProductData(sheetsData);
        
        if (productsData.length === 0) {
            showAlert('No products found in the sheets. Please check your data.', 'warning');
            return;
        }
        
        // Save products data to session for use in Step 3 (image upload with dynamic variant detection)
        saveProductsToSession(productsData);
        
        showAlert(`Successfully loaded ${productsData.length} products!`, 'success');
        displayPreview();
        
    } catch (error) {
        console.error('Error processing workbook:', error);
        showAlert('Error processing sheets: ' + error.message, 'error');
    }
}

function mergeProductData(sheetsData) {
    const productMap = new Map();
    
    // Process products sheet
    sheetsData.products.forEach((row, idx) => {
        const productNo = row.productNo;
        
        // Debug first product
        if (idx === 0) {
            console.log('First product row in merge:', row);
        }
        
        // Map category name to ID
        let categoryId = 1; // Default category
        // Try multiple possible column name variations
        const categoryValue = row.category_id || row.category || row['Category'] || row['category'] || 
                             row['Category Name'] || row['category name'] || row['CategoryName'] || 
                             row['Product Category'] || row['product category'] || row.cat || row['Cat'] || 
                             row.type || row['Type'] || '';
        
        if (categoryValue) {
            const categoryKey = String(categoryValue).toLowerCase().trim();
            
            // Debug category mapping for first product
            if (idx === 0) {
                console.log('=== CATEGORY MAPPING DEBUG ===');
                console.log('Raw category value:', categoryValue);
                console.log('Category key (lowercase):', categoryKey);
                console.log('Available categories in map:', Object.keys(categoriesMap));
                console.log('Match found:', categoriesMap[categoryKey]);
            }
            
            if (categoriesMap[categoryKey]) {
                categoryId = categoriesMap[categoryKey];
            } else if (!isNaN(categoryValue)) {
                // If it's a number, use it directly if valid
                categoryId = parseInt(categoryValue);
            } else {
                // If no match found, still use default but log a warning
                console.warn(`Category "${categoryValue}" not found in categories map. Using default category ID: 1`);
            }
            
            if (idx === 0) {
                console.log('Final category ID:', categoryId);
                console.log('==============================');
            }
        }
        
        // Normalize display_location to match database ENUM
        // Use mapped field key first
        let displayLocation = row.display_location || row['Display Location'] || row['display'] || row['Display'] || row['Location'] || 'Shop Page';
        if (displayLocation) {
            displayLocation = String(displayLocation).trim();
            // Map common variants to database values
            if (/home.*page/i.test(displayLocation) && !/shop/i.test(displayLocation)) {
                displayLocation = 'Homepage';
            } else if (/both/i.test(displayLocation) || (/home/i.test(displayLocation) && /shop/i.test(displayLocation))) {
                displayLocation = 'Both';
            } else if (/shop/i.test(displayLocation)) {
                displayLocation = 'Shop Page';
            } else {
                displayLocation = 'Shop Page'; // Default
            }
        } else {
            displayLocation = 'Shop Page'; // Default
        }
        
        // Normalize status to match database ENUM
        // Use mapped field key first
        let status = row.status || row['Status'] || row['state'] || row['availability'] || 'In Stock';
        if (status) {
            status = String(status).trim();
            if (/out.*stock/i.test(status)) {
                status = 'Out of Stock';
            } else if (/limited/i.test(status)) {
                status = 'Limited';
            } else if (/in.*stock/i.test(status)) {
                status = 'In Stock';
            } else {
                status = 'In Stock'; // Default
            }
        } else {
            status = 'In Stock'; // Default
        }
        
        // Helper function to safely parse numbers
        const safeParseFloat = (value) => {
            if (value === null || value === undefined || value === '') return 0;
            const parsed = parseFloat(String(value).replace(/[^0-9.-]/g, ''));
            return isNaN(parsed) ? 0 : parsed;
        };
        
        const safeParseInt = (value) => {
            if (value === null || value === undefined || value === '') return 0;
            const parsed = parseInt(String(value).replace(/[^0-9]/g, ''));
            return isNaN(parsed) ? 0 : parsed;
        };
        
        // Use mapped field keys first, then fall back to common column names
        const productData = {
            productNo: productNo,
            name: row.name || row['Product Name'] || row['product name'] || row['Name'] || row['Title'] || row['title'] || '',
            description: row.description || row['Description'] || row['desc'] || row['Details'] || row['details'] || '',
            category_id: categoryId,
            original_price: safeParseFloat(row.original_price || row['Original Price'] || row['original price'] || row['Price'] || row['price'] || row['MRP'] || row['mrp']),
            discounted_price: safeParseFloat(row.discounted_price || row['Discounted Price'] || row['discounted price'] || row['Sale Price'] || row['sale price'] || row['discount']) || null,
            commission: safeParseFloat(row.commission || row['Commission'] || row['commission (pkr)'] || row['Commission (PKR)']),
            delivery_charges: safeParseFloat(row.delivery_charges || row['Delivery Charges'] || row['delivery charges'] || row['Shipping'] || row['shipping']),
            stock_count: safeParseInt(row.stock || row.stock_count || row['Stock'] || row['stock count'] || row['Stock Count'] || row['Quantity'] || row['quantity']),
            sales_count: 0, // Will be updated from soldInfo sheet
            status: status,
            display_location: displayLocation,
            keywords: row.keywords || row['Keywords'] || row['keywords'] || row['Tags'] || row['tags'] || '',
            features: [],
            variants: [],
            reviews: []
        };
        
        // Debug first product with detailed price info
        if (idx === 0) {
            console.log('=== FIRST PRODUCT DEBUG ===');
            console.log('Raw row data:', row);
            console.log('Price field values:');
            console.log('  row.original_price:', row.original_price);
            console.log('  row["Original Price"]:', row['Original Price']);
            console.log('  row.discounted_price:', row.discounted_price);
            console.log('  row["Discounted Price"]:', row['Discounted Price']);
            console.log('Final product data:', productData);
            console.log('=========================');
        }
        
        productMap.set(productNo, productData);
    });
    
    // Add features
    sheetsData.features.forEach(row => {
        const productNo = row.productNo;
        if (productMap.has(productNo)) {
            productMap.get(productNo).features.push({
                name: row.feature_name || row['Feature Name'] || row['Name'] || '',
                description: row.feature_description || row['Feature Value'] || row['Description'] || ''
            });
        }
    });
    
    // Helper function for safe number parsing
    const safeParseFloat = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const parsed = parseFloat(String(value).replace(/[^0-9.-]/g, ''));
        return isNaN(parsed) ? null : parsed;
    };
    
    // Add variants - DETECT COMBINATION FORMAT
    // Check if we have combination format (Color, Size, Design columns)
    const hasCombinationFormat = sheetsData.variants.length > 0 && (
        sheetsData.variants[0].hasOwnProperty('Color') || 
        sheetsData.variants[0].hasOwnProperty('color') || 
        sheetsData.variants[0].hasOwnProperty('Size') || 
        sheetsData.variants[0].hasOwnProperty('size') ||
        sheetsData.variants[0].hasOwnProperty('Design') || 
        sheetsData.variants[0].hasOwnProperty('design')
    );
    
    if (hasCombinationFormat) {
        // NEW FORMAT: Combination Variants (Color + Size + Design)
        console.log('🎨 COMBINATION VARIANT FORMAT DETECTED');
        
        sheetsData.variants.forEach((row, idx) => {
            const productNo = row.productNo;
            if (productMap.has(productNo)) {
                // Extract combination attributes
                const combinationData = {};
                
                // Check for Color
                const color = row.Color || row.color || row.COLOR || row['Color'] || null;
                if (color) combinationData.Color = String(color).trim();
                
                // Check for Size
                const size = row.Size || row.size || row.SIZE || row['Size'] || null;
                if (size) combinationData.Size = String(size).trim();
                
                // Check for Design
                const design = row.Design || row.design || row.DESIGN || row['Design'] || 
                              row.Style || row.style || row.STYLE || row['Style'] || null;
                if (design) combinationData.Design = String(design).trim();
                
                // Get price and stock for this combination
                const variantPrice = safeParseFloat(row.variant_price || row['Variant Price'] || row['Combination Price'] || row['Price'] || row.price);
                const variantOriginalPrice = safeParseFloat(row.variant_original_price || row['Variant Original Price'] || row['Original Price']);
                const stock = parseInt(row.stock || row.Stock || row.stock_quantity || row['Stock Quantity'] || 0) || 0;
                
                // Debug first combination
                if (idx === 0) {
                    console.log('=== COMBINATION VARIANT DEBUG ===');
                    console.log('First combination row:', row);
                    console.log('Extracted combination:', combinationData);
                    console.log('Price:', variantPrice);
                    console.log('Original Price:', variantOriginalPrice);
                    console.log('Stock:', stock);
                    console.log('=================================');
                }
                
                productMap.get(productNo).variants.push({
                    combination_data: combinationData,
                    price: variantPrice,
                    original_price: variantOriginalPrice,
                    stock: stock
                });
            }
        });
    } else {
        // OLD FORMAT: Simple Variants (backward compatible)
        console.log('📦 SIMPLE VARIANT FORMAT DETECTED');
        
        sheetsData.variants.forEach((row, idx) => {
            const productNo = row.productNo;
            if (productMap.has(productNo)) {
                // Use mapped field keys first (variant_price, variant_original_price)
                const variantPrice = safeParseFloat(row.variant_price || row['Variant Price'] || row['Price']);
                const variantOriginalPrice = safeParseFloat(row.variant_original_price || row['Variant Original Price'] || row['Original Price']);
                
                // Debug first variant
                if (idx === 0) {
                    console.log('=== SIMPLE VARIANT DEBUG ===');
                    console.log('First variant row:', row);
                    console.log('Parsed variantPrice:', variantPrice);
                    console.log('Parsed variantOriginalPrice:', variantOriginalPrice);
                    console.log('============================');
                }
                
                // Get and validate variant type (preserve custom types)
                let variantType = row.variant_type || row['Variant Type'] || row['Type'] || '';
                
                // Trim and validate - use exactly what's in the sheet
                variantType = variantType ? String(variantType).trim() : 'Color';
                
                // Debug custom variant types
                if (idx === 0 && variantType !== 'Color' && variantType !== 'Size') {
                    console.log('🎨 Custom Variant Type Detected:', variantType);
                }
                
                productMap.get(productNo).variants.push({
                    type: variantType,
                    name: row.variant_name || row['Variant Name'] || row['Name'] || '',
                    price: variantPrice,
                    original_price: variantOriginalPrice
                });
            }
        });
    }
    
    // Add reviews
    sheetsData.reviews.forEach(row => {
        const productNo = row.productNo;
        if (productMap.has(productNo)) {
            productMap.get(productNo).reviews.push({
                reviewer_name: row.reviewer_name || row['Reviewer Name'] || row['Name'] || '',
                rating: parseInt(row.rating || row['Rating'] || 5),
                review_text: row.review_text || row['Review'] || row['Comment'] || ''
            });
        }
    });
    
    // Add sold info
    sheetsData.soldInfo.forEach((row, idx) => {
        const productNo = row.productNo;
        if (productMap.has(productNo)) {
            const product = productMap.get(productNo);
            
            // Debug first sold info row
            if (idx === 0) {
                console.log('=== SOLD INFO DEBUG ===');
                console.log('First sold info row:', row);
                console.log('Row keys:', Object.keys(row));
                console.log('Checking fields:');
                console.log('  row.units_sold:', row.units_sold);
                console.log('  row["Units Sold"]:', row['Units Sold']);
                console.log('  row.sold:', row.sold);
                console.log('  row["Sold"]:', row['Sold']);
                console.log('  row.sales:', row.sales);
                console.log('  row.stock_count:', row.stock_count);
                console.log('  row["Stock Count"]:', row['Stock Count']);
            }
            
            // Use mapped field keys with comprehensive fallbacks
            const unitsSold = row.units_sold || row['Units Sold'] || row['units sold'] || row.sold || row['Sold'] || row.sales || row['Sales'] || 0;
            const stockCount = row.stock_count || row['Stock Count'] || row['stock count'] || row.stock || row['Stock'] || row.inventory || row['Inventory'] || product.stock_count;
            const views = row.views || row['Views'] || row['Page Views'] || row['page views'] || 0;
            
            product.sales_count = parseInt(unitsSold) || 0;
            product.stock_count = parseInt(stockCount) || product.stock_count || 0;
            
            // Store views if available (though not used in DB schema currently)
            if (views) {
                product.views = parseInt(views) || 0;
            }
            
            // Debug parsed values
            if (idx === 0) {
                console.log('Parsed values:');
                console.log('  sales_count:', product.sales_count);
                console.log('  stock_count:', product.stock_count);
                console.log('======================');
            }
        }
    });
    
    return Array.from(productMap.values());
}

function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.bulk-import-container');
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
        setTimeout(() => alertDiv.remove(), 5000);
    }
}

function displayPreview() {
    let html = `
        <div class="bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 p-4 rounded-2xl shadow-sm mb-4">
            <div class="flex items-center gap-3">
                <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                <div>
                    <h6 class="font-bold text-green-900 mb-1">Data Loaded Successfully!</h6>
                    <p class="text-sm text-gray-700">Found ${productsData.length} products with all related data merged by Product Number</p>
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    `;
    
    productsData.forEach((product, index) => {
        html += `
            <div class="bg-white rounded-2xl shadow-lg hover:shadow-xl transition-all duration-300 overflow-hidden border-2 border-gray-200 hover:border-blue-400">
                <!-- Product Header -->
                <div class="bg-gradient-to-r from-blue-500 to-indigo-600 p-4 text-white">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <span class="text-xs font-semibold bg-white/20 px-2 py-1 rounded-full">Product #${product.productNo}</span>
                            <h6 class="font-bold text-lg mt-2 line-clamp-2">${product.name || 'Unnamed Product'}</h6>
                        </div>
                        <button onclick="toggleProduct(${index})" class="ml-2 bg-white/20 hover:bg-white/30 rounded-full p-2 transition">
                            <i class="fas fa-chevron-down" id="chevron-${index}"></i>
                        </button>
                    </div>
                    <div class="flex gap-2 mt-3 text-xs">
                        <span class="bg-white/20 px-2 py-1 rounded-full">PKR ${product.original_price}</span>
                        <span class="bg-white/20 px-2 py-1 rounded-full">Features: ${product.features.length}</span>
                        <span class="bg-white/20 px-2 py-1 rounded-full">Variants: ${product.variants.length}</span>
                    </div>
                </div>
                
                <!-- Product Details (Collapsible) -->
                <div id="product-details-${index}" class="hidden p-4 space-y-3">
                    <!-- Product Info -->
                    <div class="bg-gray-50 p-3 rounded-xl">
                        <h6 class="font-bold text-sm text-gray-800 mb-2 flex items-center gap-2">
                            <i class="fas fa-info-circle text-blue-500"></i>Product Information
                        </h6>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div><span class="text-gray-600">Price:</span> <strong>PKR ${product.original_price}</strong></div>
                            <div><span class="text-gray-600">Discount:</span> <strong>PKR ${product.discounted_price || 0}</strong></div>
                            <div><span class="text-gray-600">Stock:</span> <strong>${product.stock_count}</strong></div>
                            <div><span class="text-gray-600">Sold:</span> <strong>${product.sales_count}</strong></div>
                            <div class="col-span-2"><span class="text-gray-600">Status:</span> <span class="px-2 py-1 rounded-full text-xs ${product.status === 'In Stock' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}">${product.status}</span></div>
                        </div>
                    </div>
                    
                    <!-- Features -->
                    ${product.features.length > 0 ? `
                        <div class="bg-green-50 p-3 rounded-xl">
                            <h6 class="font-bold text-sm text-gray-800 mb-2 flex items-center gap-2">
                                <i class="fas fa-list-ul text-green-500"></i>Features (${product.features.length})
                            </h6>
                            <div class="space-y-1 max-h-32 overflow-y-auto">
                                ${product.features.map(f => `
                                    <div class="text-xs bg-white p-2 rounded">
                                        <strong>${f.name}:</strong> ${f.description}
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                    
                    <!-- Variants -->
                    ${product.variants.length > 0 ? `
                        <div class="bg-purple-50 p-3 rounded-xl">
                            <h6 class="font-bold text-sm text-gray-800 mb-2 flex items-center gap-2">
                                <i class="fas fa-palette text-purple-500"></i>Variants (${product.variants.length})
                            </h6>
                            <div class="space-y-1 max-h-32 overflow-y-auto">
                                ${product.variants.map(v => `
                                    <div class="text-xs bg-white p-2 rounded flex justify-between">
                                        <span><strong>${v.name}</strong> (${v.type})</span>
                                        <span class="text-gray-600">PKR ${v.price || v.original_price || 0}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                    
                    <!-- Reviews -->
                    ${product.reviews.length > 0 ? `
                        <div class="bg-yellow-50 p-3 rounded-xl">
                            <h6 class="font-bold text-sm text-gray-800 mb-2 flex items-center gap-2">
                                <i class="fas fa-star text-yellow-500"></i>Reviews (${product.reviews.length})
                            </h6>
                            <div class="space-y-1 max-h-32 overflow-y-auto">
                                ${product.reviews.slice(0, 3).map(r => `
                                    <div class="text-xs bg-white p-2 rounded">
                                        <div class="flex justify-between items-center mb-1">
                                            <strong>${r.reviewer_name}</strong>
                                            <span class="text-yellow-500">${'★'.repeat(r.rating)}</span>
                                        </div>
                                        <p class="text-gray-600 line-clamp-2">${r.review_text}</p>
                                    </div>
                                `).join('')}
                                ${product.reviews.length > 3 ? `<p class="text-xs text-gray-500 text-center mt-1">+${product.reviews.length - 3} more reviews</p>` : ''}
                            </div>
                        </div>
                    ` : ''}
                    
                    <!-- Edit Button -->
                    <button onclick="editProduct(${index})" class="w-full bg-gradient-to-r from-blue-500 to-indigo-600 text-white py-2 rounded-xl font-semibold hover:from-blue-600 hover:to-indigo-700 transition">
                        <i class="fas fa-edit me-2"></i>Edit Product
                    </button>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    document.getElementById('previewContent').innerHTML = html;
}

function toggleProduct(index) {
    const details = document.getElementById(`product-details-${index}`);
    const chevron = document.getElementById(`chevron-${index}`);
    
    if (details.classList.contains('hidden')) {
        details.classList.remove('hidden');
        chevron.classList.add('fa-chevron-up');
        chevron.classList.remove('fa-chevron-down');
    } else {
        details.classList.add('hidden');
        chevron.classList.add('fa-chevron-down');
        chevron.classList.remove('fa-chevron-up');
    }
}

function editProduct(index) {
    // Placeholder for edit functionality
    showAlert(`Edit functionality for product: ${productsData[index].name}\n\nThis will open an editable form in the next step.`, 'info', 'Edit Product');
}

/**
 * Render Step 4: Editable Preview with Image Assignments
 */
function renderEditablePreview() {
    console.log('renderEditablePreview called');
    console.log('productsData:', productsData);
    console.log('productsData length:', productsData ? productsData.length : 0);
    
    // Merge image assignments with product data first
    if (typeof mergeImagesWithProducts === 'function') {
        console.log('Calling mergeImagesWithProducts');
        mergeImagesWithProducts();
    } else {
        console.warn('mergeImagesWithProducts function not found');
    }
    
    const container = document.getElementById('editablePreviewContent');
    
    if (!container) {
        console.error('editablePreviewContent container not found!');
        return;
    }
    
    let html = '';
    
    if (!productsData || productsData.length === 0) {
        html = `
            <div class="alert alert-warning">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>No Products Found</h5>
                <p class="mb-2">You need to complete Steps 1 & 2 first before uploading images.</p>
                <hr>
                <p class="mb-0"><strong>Required Steps:</strong></p>
                <ol class="mb-0">
                    <li>Go to <strong>Step 1: Upload Data</strong> - Upload your Excel/CSV file</li>
                    <li>Complete <strong>Step 2: Map Data</strong> - Map your columns</li>
                    <li>Then proceed to <strong>Step 3: Upload Images</strong></li>
                    <li>Finally review here in <strong>Step 4</strong></li>
                </ol>
            </div>
        `;
        container.innerHTML = html;
        return;
    }
    
    productsData.forEach((product, index) => {
        const images = product.assignedImages || { primary: null, variants: [], additional: [] };
        
        html += `
            <div class="card mb-4" id="product-edit-${index}">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center" 
                     style="cursor: pointer; user-select: none;" 
                     onclick="toggleProductPreview(${index})">
                    <h6 class="mb-0">
                        <i class="fas fa-box me-2"></i>Product #${product.productNo}: ${product.name || 'Unnamed Product'}
                    </h6>
                    <i class="fas fa-chevron-down" id="chevron-product-${index}" style="transition: transform 0.3s ease;"></i>
                </div>
                <div class="card-body" id="product-body-${index}" style="display: none;">
                    <div class="row">
                        <!-- Product Info -->
                        <div class="col-md-4">
                            <h6 class="text-muted">Product Information</h6>
                            <table class="table table-sm">
                                <tr><td><strong>Name:</strong></td><td>${product.name || 'N/A'}</td></tr>
                                <tr><td><strong>Price:</strong></td><td>PKR ${product.original_price || 0}</td></tr>
                                <tr><td><strong>Discount:</strong></td><td>PKR ${product.discounted_price || 0}</td></tr>
                                <tr><td><strong>Stock:</strong></td><td>${product.stock_count || 0}</td></tr>
                                <tr><td><strong>Category:</strong></td><td>${product.category_id || 'N/A'}</td></tr>
                            </table>
                        </div>
                        
                        <!-- Primary Image -->
                        <div class="col-md-4">
                            <h6 class="text-muted">Primary Image</h6>
                            <div class="image-slot primary-image-slot" data-product="${index}" data-type="primary">
                                ${images.primary ? `
                                    <img src="../${images.primary}" class="img-fluid rounded mb-2" style="max-height: 200px; object-fit: cover;" alt="Primary">
                                    <div class="text-center">
                                        <span class="badge bg-success"><i class="fas fa-star me-1"></i>Primary</span>
                                        <button class="btn btn-sm btn-outline-danger mt-2" onclick="removeImageAssignment(${index}, 'primary', 0)">
                                            <i class="fas fa-trash me-1"></i>Remove
                                        </button>
                                    </div>
                                ` : `
                                    <div class="text-center p-4 border border-dashed rounded">
                                        <i class="fas fa-image fa-3x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">No primary image</p>
                                    </div>
                                `}
                            </div>
                        </div>
                        
                        <!-- Variant Images -->
                        <div class="col-md-4">
                            <h6 class="text-muted">Variant Images (${product.variants ? product.variants.length : 0} variants)</h6>
                            <div class="variant-images-container">
                                ${renderVariantImages(product, index)}
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Images -->
                    ${images.additional && images.additional.length > 0 ? `
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6 class="text-muted">Additional Images</h6>
                                <div class="row g-2">
                                    ${images.additional.map((img, imgIdx) => `
                                        <div class="col-md-2">
                                            <div class="image-slot">
                                                <img src="../${img}" class="img-fluid rounded" style="height: 100px; object-fit: cover;" alt="Additional ${imgIdx + 1}">
                                                <button class="btn btn-sm btn-outline-danger mt-1 w-100" onclick="removeImageAssignment(${index}, 'additional', ${imgIdx})">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

/**
 * Render variant images for a product
 */
function renderVariantImages(product, productIndex) {
    if (!product.variants || product.variants.length === 0) {
        return '<p class="text-muted small">No variants defined</p>';
    }
    
    const images = product.assignedImages || {};
    let html = '<div class="row g-2">';
    
    product.variants.forEach((variant, variantIdx) => {
        const variantImage = images.variants && images.variants[variantIdx] ? images.variants[variantIdx] : null;
        
        // Handle both simple variants and combination variants
        let variantLabel = '';
        let variantPrice = variant.price || variant.original_price || 0;
        
        if (variant.combination_data) {
            // Combination variant - format as "Color: Red | Size: Large"
            const parts = [];
            for (const [attr, value] of Object.entries(variant.combination_data)) {
                parts.push(`${attr}: ${value}`);
            }
            variantLabel = parts.join(' | ');
        } else if (variant.type && variant.name) {
            // Simple variant - format as "Type (Name)"
            variantLabel = `${variant.name} (${variant.type})`;
        } else {
            // Fallback
            variantLabel = 'Variant ' + (variantIdx + 1);
        }
        
        html += `
            <div class="col-12 mb-2">
                <div class="card border-light">
                    <div class="card-body p-2">
                        <div class="d-flex align-items-center gap-2">
                            ${variantImage ? `
                                <img src="../${variantImage}" style="width: 60px; height: 60px; object-fit: cover;" class="rounded" alt="${variantLabel}">
                            ` : `
                                <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                    <i class="fas fa-image text-muted"></i>
                                </div>
                            `}
                            <div class="flex-grow-1">
                                <small class="d-block"><strong>${variantLabel}</strong></small>
                                <small class="text-muted">Price: PKR ${variantPrice}</small>
                            </div>
                            ${variantImage ? `
                                <button class="btn btn-sm btn-outline-danger" onclick="removeImageAssignment(${productIndex}, 'variant', ${variantIdx})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    return html;
}

/**
 * Remove an image assignment
 */
function removeImageAssignment(productIndex, type, imageIndex) {
    if (!productsData[productIndex] || !productsData[productIndex].assignedImages) return;
    
    const images = productsData[productIndex].assignedImages;
    
    switch (type) {
        case 'primary':
            images.primary = null;
            break;
        case 'variant':
            if (images.variants && images.variants[imageIndex]) {
                images.variants[imageIndex] = null;
            }
            break;
        case 'additional':
            if (images.additional && images.additional[imageIndex] !== undefined) {
                images.additional.splice(imageIndex, 1);
            }
            break;
    }
    
    // Re-render the preview
    renderEditablePreview();
    showAlert('Image removed successfully', 'success');
}

/**
 * Render Step 5: Final Preview before Import
 */
function renderFinalPreview() {
    const container = document.getElementById('finalPreviewContent');
    let html = '<div class="row g-3">';
    
    if (!productsData || productsData.length === 0) {
        html = '<div class="alert alert-warning">No products to preview.</div>';
        container.innerHTML = html;
        return;
    }
    
    productsData.forEach((product, index) => {
        const images = product.assignedImages || { primary: null, variants: [], additional: [] };
        const primaryImg = images.primary || 'uploads/products/placeholder.jpg';
        const totalImages = (images.primary ? 1 : 0) + 
                          (images.variants ? images.variants.filter(v => v).length : 0) + 
                          (images.additional ? images.additional.length : 0);
        
        html += `
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm hover-shadow">
                    <div class="position-relative">
                        <img src="../${primaryImg}" class="card-img-top" style="height: 200px; object-fit: cover;" alt="${product.name}">
                        <div class="position-absolute top-0 end-0 m-2">
                            <span class="badge bg-primary">#${product.productNo}</span>
                        </div>
                        ${totalImages > 1 ? `
                            <div class="position-absolute bottom-0 start-0 m-2">
                                <span class="badge bg-dark"><i class="fas fa-images me-1"></i>${totalImages} images</span>
                            </div>
                        ` : ''}
                    </div>
                    <div class="card-body">
                        <h6 class="card-title text-truncate" title="${product.name}">${product.name || 'Unnamed Product'}</h6>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <span class="text-muted small">Price:</span>
                                <strong class="text-primary">PKR ${product.original_price || 0}</strong>
                            </div>
                            ${product.discounted_price ? `
                                <span class="badge bg-danger">-${Math.round(((product.original_price - product.discounted_price) / product.original_price) * 100)}%</span>
                            ` : ''}
                        </div>
                        <div class="small text-muted">
                            <div><i class="fas fa-box me-2"></i>Stock: ${product.stock_count || 0}</div>
                            <div><i class="fas fa-palette me-2"></i>Variants: ${product.variants ? product.variants.length : 0}</div>
                            <div><i class="fas fa-star me-2"></i>Reviews: ${product.reviews ? product.reviews.length : 0}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

function updateSummary() {
    document.getElementById('totalProducts').textContent = productsData.length;
    
    let totalVariants = 0;
    let totalReviews = 0;
    let totalImages = 0;
    
    productsData.forEach(product => {
        totalVariants += product.variants ? product.variants.length : 0;
        totalReviews += product.reviews ? product.reviews.length : 0;
        
        // Count images
        const images = product.assignedImages || {};
        totalImages += (images.primary ? 1 : 0);
        totalImages += (images.variants ? images.variants.filter(v => v).length : 0);
        totalImages += (images.additional ? images.additional.length : 0);
    });
    
    document.getElementById('totalVariants').textContent = totalVariants;
    document.getElementById('totalReviews').textContent = totalReviews;
    if (document.getElementById('totalImages')) {
        document.getElementById('totalImages').textContent = totalImages;
    }
    
    document.getElementById('productsDataInput').value = JSON.stringify(productsData);
}

// Improved auto-mapping function with better detection for specific fields
function autoMapFields(headers) {
    const map = {};
    const fieldMappings = {
        'Product Name': ['name', 'product name', 'product_name', 'title'],
        'Category': ['category', 'cat', 'type'],
        'Original Price': ['price', 'original price', 'original_price', 'cost'],
        'Discounted Price': ['discounted price', 'discounted_price', 'sale price', 'sale_price', 'discount'],
        'Commission (PKR)': ['commission', 'commission (pkr)', 'commission_pkr', 'fee'],
        'Delivery Charges': ['delivery', 'delivery charges', 'delivery_charges', 'shipping'],
        'Stock': ['stock', 'quantity', 'qty'],
        'Sold': ['sold', 'sales', 'sales_count'],
        'Status': ['status', 'state'],
        'Display Location': ['display', 'display location', 'display_location', 'location'],
        'Description': ['description', 'desc', 'details'],
        'Keywords': ['keywords', 'tags', 'keyword'],
        'Variants': ['variants', 'variant', 'options'],
        'Features': ['features', 'feature', 'specs'],
        'Reviews': ['reviews', 'review', 'feedback']
    };

    systemFields.forEach(field => {
        const possibleNames = fieldMappings[field] || [field.toLowerCase().replace(/[^a-z]/g, '')];
        let match = null;
        for (const name of possibleNames) {
            match = headers.find(header => header.toLowerCase().includes(name));
            if (match) break;
        }
        map[field] = match || 'Unmapped';
    });
    return map;
}

// Render mapping table with manual correction dropdowns for unmapped fields
function renderMappingTable(headers) {
    const table = document.getElementById('mappingTable');
    table.innerHTML = `
        <table class="mapping-table">
            <thead>
                <tr>
                    <th>System Field</th>
                    <th>Detected Column</th>
                    <th>Manual Correction</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                ${systemFields.map(field => `
                    <tr>
                        <td>${field}</td>
                        <td>${mappings[field] !== 'Unmapped' ? mappings[field] : 'Not detected'}</td>
                        <td>
                            <select class="mapping-select" data-field="${field}" ${mappings[field] !== 'Unmapped' ? 'disabled' : ''}>
                                <option value="Unmapped" ${mappings[field] === 'Unmapped' ? 'selected' : ''}>Unmapped</option>
                                ${headers.map(header => `<option value="${header}" ${mappings[field] === header ? 'selected' : ''}>${header}</option>`).join('')}
                            </select>
                        </td>
                        <td><span class="status-indicator ${mappings[field] !== 'Unmapped' ? 'mapped' : 'unmapped'}">${mappings[field] !== 'Unmapped' ? '✓' : '⚠'}</span></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    // Update mappings on change for manual corrections
    document.querySelectorAll('.mapping-select').forEach(select => {
        select.addEventListener('change', (e) => {
            mappings[e.target.dataset.field] = e.target.value;
            const row = e.target.closest('tr');
            row.querySelector('td:nth-child(2)').textContent = e.target.value !== 'Unmapped' ? e.target.value : 'Not detected';
            row.querySelector('.status-indicator').className = `status-indicator ${e.target.value !== 'Unmapped' ? 'mapped' : 'unmapped'}`;
        });
    });
}

// Enhanced image upload with auto-assignment based on filenames
function handleImageUpload(event) {
    const files = Array.from(event.target.files);
    uploadedImages = files.map(file => {
        const match = file.name.match(/^(\d+)/);
        const productId = match ? parseInt(match[1], 10) : null;
        return { file, productId, variantIndex: file.name.match(/\(\d+\)/) ? parseInt(file.name.match(/\(\d+\)/)[0].slice(1, -1), 10) : 0 };
    }).sort((a, b) => (a.productId || 0) - (b.productId || 0) || a.variantIndex - b.variantIndex);
    
    const list = document.getElementById('uploadedImagesList');
    list.innerHTML = uploadedImages.map((img, index) => `
        <div class="uploaded-file-item">
            <i class="fas fa-image me-2"></i>${img.file.name} → Product ${img.productId || 'Unknown'}
            <span class="file-size">${(img.file.size / 1024 / 1024).toFixed(2)} MB</span>
        </div>
    `).join('');
}

// Enhanced product preview with highlighting and inline editing
function renderProductPreview() {
    const preview = document.getElementById('productsPreview');
    preview.innerHTML = sheetData.slice(1).map((row, index) => {
        const product = mapRowToProduct(row, index);
        const hasIssues = !product.name || !product.price || !product.category;
        return `
            <div class="product-preview-card ${hasIssues ? 'has-issues' : ''}" style="border: 1px solid ${hasIssues ? '#f59e0b' : '#e9ecef'}; background: ${hasIssues ? '#fefce8' : 'white'};">
                <div class="product-preview-header">
                    <h4>Product ${index + 1}</h4>
                    ${hasIssues ? '<span class="issue-badge">⚠ Missing Data</span>' : ''}
                </div>
                <div class="product-preview-content">
                    <div class="preview-field">
                        <label>Name</label>
                        <input type="text" value="${product.name || ''}" data-product="${index}" data-field="name">
                    </div>
                    <div class="preview-field">
                        <label>Price</label>
                        <input type="number" value="${product.price || ''}" data-product="${index}" data-field="price">
                    </div>
                    <div class="preview-field">
                        <label>Discounted Price</label>
                        <input type="number" value="${product.discountedprice || ''}" data-product="${index}" data-field="discountedprice">
                    </div>
                    <div class="preview-field">
                        <label>Stock</label>
                        <input type="number" value="${product.stock || ''}" data-product="${index}" data-field="stock">
                    </div>
                    <div class="preview-field">
                        <label>Category</label>
                        <input type="text" value="${product.category || ''}" data-product="${index}" data-field="category">
                    </div>
                    <div class="preview-field">
                        <label>Description</label>
                        <textarea data-product="${index}" data-field="description">${product.description || ''}</textarea>
                    </div>
                    <div class="preview-field">
                        <label>Variants</label>
                        <textarea data-product="${index}" data-field="variants">${product.variants || ''}</textarea>
                    </div>
                    <div class="preview-field">
                        <label>Features</label>
                        <textarea data-product="${index}" data-field="features">${product.features || ''}</textarea>
                    </div>
                    <div class="preview-field">
                        <label>Reviews</label>
                        <textarea data-product="${index}" data-field="reviews">${product.reviews || ''}</textarea>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// Updated mapRowToProduct to handle all fields
function mapRowToProduct(row, index) {
    const product = {};
    Object.keys(mappings).forEach(field => {
        const colIndex = sheetData[0].indexOf(mappings[field]);
        const key = field.toLowerCase().replace(/[^a-z]/g, '');
        product[key] = colIndex >= 0 ? row[colIndex] : '';
    });
    // Assign images
    product.images = uploadedImages.filter(img => img.productId === index + 1);
    if (product.images.length > 0) {
        product.primaryImage = product.images[0];
        product.variantImages = product.images.slice(1);
    }
    return product;
}

// Handle form submission for bulk save
document.addEventListener('DOMContentLoaded', function() {
    const bulkSaveForm = document.getElementById('bulkSaveForm');
    if (bulkSaveForm) {
        bulkSaveForm.addEventListener('submit', function(e) {
            // Clear saved progress on successful submission
            // The form will submit normally and PHP will handle the redirect
            // After redirect, the progress will be cleared
            console.log('Form submitting - clearing progress will happen after successful save');
        });
    }
});

// Clear progress after successful import (called from PHP redirect)
if (window.location.search.includes('imported=success')) {
    clearProgress();
    console.log('Import successful - progress cleared');
}

/**
 * Toggle collapsible sections (auto-matched, unmatched, all images)
 */
function toggleSection(sectionName) {
    const chevron = document.getElementById(`chevron-${sectionName}`);
    let contentElement;
    
    if (sectionName === 'autoMatched') {
        contentElement = document.getElementById('autoMappedGrid');
    } else if (sectionName === 'unmatched') {
        contentElement = document.querySelector('#unmappedSection .table-responsive');
    } else if (sectionName === 'allImages') {
        contentElement = document.getElementById('uploadedImagesContent');
    }
    
    if (contentElement) {
        const isVisible = contentElement.style.display !== 'none';
        contentElement.style.display = isVisible ? 'none' : 'block';
        
        // Rotate chevron
        if (chevron) {
            chevron.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(180deg)';
        }
    }
}

/**
 * Toggle product preview in Step 4 (collapsed by default)
 */
function toggleProductPreview(productIndex) {
    const body = document.getElementById(`product-body-${productIndex}`);
    const chevron = document.getElementById(`chevron-product-${productIndex}`);
    
    if (!body || !chevron) return;
    
    const isHidden = body.style.display === 'none';
    
    if (isHidden) {
        // Expand
        body.style.display = 'block';
        chevron.style.transform = 'rotate(180deg)';
        chevron.classList.remove('fa-chevron-down');
        chevron.classList.add('fa-chevron-up');
    } else {
        // Collapse
        body.style.display = 'none';
        chevron.style.transform = 'rotate(0deg)';
        chevron.classList.remove('fa-chevron-up');
        chevron.classList.add('fa-chevron-down');
    }
}

/**
 * Toggle final preview section in Step 5 (collapsed by default)
 */
function toggleFinalPreview() {
    const body = document.getElementById('final-preview-body');
    const chevron = document.getElementById('chevron-final-preview');
    
    if (!body || !chevron) return;
    
    const isHidden = body.style.display === 'none';
    
    if (isHidden) {
        // Expand
        body.style.display = 'block';
        chevron.style.transform = 'rotate(180deg)';
        chevron.classList.remove('fa-chevron-down');
        chevron.classList.add('fa-chevron-up');
    } else {
        // Collapse
        body.style.display = 'none';
        chevron.style.transform = 'rotate(0deg)';
        chevron.classList.remove('fa-chevron-up');
        chevron.classList.add('fa-chevron-down');
    }
}
</script>

<!-- Load bulk upload handler -->
<script src="js/bulk_upload_handler.js?v=<?php echo time(); ?>"></script>
<!-- Load bulk image upload handler -->
<script src="js/bulk_image_handler.js?v=<?php echo time(); ?>"></script>

<?php require_once 'includes/footer.php'; ?>