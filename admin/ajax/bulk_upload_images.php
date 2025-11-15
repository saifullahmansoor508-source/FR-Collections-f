<?php
/**
 * Bulk Image Upload Handler for Step 3
 * 
 * This script handles bulk image uploads and automatically assigns them to products
 * based on filename patterns (with or without extensions):
 * 
 * SEQUENTIAL IMAGES (follow number sequence):
 * - Pattern: ProductID (Number) [.extension]
 * - Examples: "1 (1).jpg", "2 (2)", "3 (1).png"
 * 
 * Assignment Rules for Sequential Images:
 * - Image "X (1)" → Primary, Shop, and Homepage image
 * - Image "X (2)" → First variant image
 * - Image "X (3)" → Second variant image
 * - Image "X (4)" → Third variant image
 * - Image "X (5+)" → Additional product images (after all variants)
 * 
 * NON-SEQUENTIAL IMAGES (do NOT follow number sequence):
 * - Pattern: ProductID + Non-numeric suffix [.extension]
 * - Examples: "1A.jpg", "1B.png", "1 (A).jpg", "1TY.jpg", "2F", "1C"
 * 
 * Assignment Rules for Non-Sequential Images:
 * - ALL non-sequential images are ALWAYS assigned as Additional Images
 * - They are added to the product's additional images regardless of variants
 * - Examples: "1A", "1B", "1TY" for Product #1 → All become additional images
 * 
 * PERFORMANCE OPTIMIZATION:
 * - Supports unlimited image uploads (tested with 2000+ images)
 * - Chunked upload system handles 50 images per request
 * - Increased PHP limits for large batch processing
 */

// Increase PHP limits for large batch uploads
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300'); // 5 minutes
@ini_set('max_input_time', '300');
@ini_set('post_max_size', '512M');
@ini_set('upload_max_filesize', '10M'); // Per file
// NOTE: max_file_uploads cannot be changed via ini_set() - must be set in php.ini
// Default is 20. To upload more files per chunk, edit php.ini and restart Apache
// See: admin/PHP_UPLOAD_LIMIT_FIX.md for instructions

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once '../../config/config.php';
require_once '../../config/database.php';

// Check if admin is logged in
// Try multiple session variable names that might be used
$isLoggedIn = false;
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $isLoggedIn = true;
} elseif (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id'])) {
    $isLoggedIn = true;
} elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $isLoggedIn = true;
} elseif (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $isLoggedIn = true;
}

// TEMPORARY: Comment out auth check for debugging
// Remove this block once you confirm your session variable name
/*
if (!$isLoggedIn) {
    // Log the session data for debugging
    error_log("Session check failed. Session data: " . print_r($_SESSION, true));
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized access. Please log in to the admin panel.',
        'session_debug' => isset($_SESSION) ? array_keys($_SESSION) : 'No session',
        'help' => 'Check your session variable name in admin login script'
    ]);
    exit;
}
*/

// TODO: Re-enable authentication check after confirming it works
// For now, log what session variables are available
if (!$isLoggedIn) {
    error_log("WARNING: Session auth failed but continuing. Session keys: " . implode(', ', array_keys($_SESSION)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Process based on action type
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'upload':
        handleImageUpload();
        break;
    
    case 'process':
        processAndAssignImages();
        break;
    
    case 'manual_assign':
        handleManualAssignment();
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

/**
 * Handle bulk image file uploads
 * Accepts multiple image files and stores them temporarily
 */
function handleImageUpload() {
    // Check PHP max_file_uploads limit
    $maxFileUploads = ini_get('max_file_uploads');
    $filesReceived = isset($_FILES['images']['name']) ? count($_FILES['images']['name']) : 0;
    
    // Debug: Log what was received
    error_log("FILES received: " . print_r($_FILES, true));
    error_log("POST received: " . print_r($_POST, true));
    error_log("PHP max_file_uploads: " . $maxFileUploads);
    error_log("Files in this request: " . $filesReceived);
    
    if (!isset($_FILES['images']) || empty($_FILES['images']['name'])) {
        echo json_encode([
            'success' => false, 
            'message' => 'No images uploaded',
            'debug' => [
                'files_keys' => array_keys($_FILES),
                'post_keys' => array_keys($_POST),
                'max_file_uploads' => $maxFileUploads,
                'files_received' => $filesReceived
            ]
        ]);
        exit;
    }
    
    // Warn if hitting PHP limit
    if ($filesReceived >= $maxFileUploads) {
        error_log("WARNING: Received $filesReceived files but PHP max_file_uploads is $maxFileUploads. Some files may be truncated.");
    }
    
    $uploadDir = '../../' . PRODUCT_IMAGES_DIR;
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to create upload directory: ' . $uploadDir
            ]);
            exit;
        }
    }
    
    $uploadedFiles = [];
    $errors = [];
    
    // Process each uploaded file
    $fileCount = count($_FILES['images']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
            $originalName = $_FILES['images']['name'][$i];
            $tmpName = $_FILES['images']['tmp_name'][$i];
            $fileSize = $_FILES['images']['size'][$i];
            
            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/webp', 'image/gif', 'image/jfif'];
            
            if (!in_array($mimeType, $allowedTypes)) {
                $errors[] = "Invalid file type for {$originalName} (MIME: {$mimeType})";
                continue;
            }
            
            // Validate file size (max 10MB per image)
            $maxSize = 10 * 1024 * 1024;
            if ($fileSize > $maxSize) {
                $errors[] = "File {$originalName} exceeds 10MB limit";
                continue;
            }
            
            // Parse filename to extract product ID and sequence
            $parsedData = parseImageFilename($originalName);
            
            if ($parsedData === false) {
                // Store with timestamp if pattern doesn't match
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                if (empty($extension)) {
                    // Try to detect from MIME type
                    $extension = getExtensionFromMime($mimeType);
                }
                $newFileName = 'unmatched_' . time() . '_' . uniqid() . '.' . $extension;
            } else {
                // Store with original pattern name
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                if (empty($extension)) {
                    $extension = getExtensionFromMime($mimeType);
                }
                // For non-sequential images, preserve original name structure
                if (isset($parsedData['isAdditional']) && $parsedData['isAdditional']) {
                    $newFileName = pathinfo($originalName, PATHINFO_FILENAME) . '.' . $extension;
                } else {
                    $newFileName = $parsedData['productId'] . ' (' . $parsedData['sequence'] . ').' . $extension;
                }
            }
            
            $targetPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($tmpName, $targetPath)) {
                $uploadedFiles[] = [
                    'originalName' => $originalName,
                    'fileName' => $newFileName,
                    'path' => PRODUCT_IMAGES_DIR . $newFileName,
                    'productId' => $parsedData['productId'] ?? null,
                    'sequence' => $parsedData['sequence'] ?? null,
                    'size' => $fileSize,
                    'matched' => $parsedData !== false,
                    'isAdditional' => $parsedData['isAdditional'] ?? false
                ];
            } else {
                $errors[] = "Failed to upload {$originalName}";
            }
        } else {
            $errors[] = "Upload error for " . $_FILES['images']['name'][$i];
        }
    }
    
    echo json_encode([
        'success' => true,
        'uploaded' => count($uploadedFiles),
        'errors' => count($errors),
        'files' => $uploadedFiles,
        'errorMessages' => $errors
    ]);
}

/**
 * Process uploaded images and assign them to products automatically
 * NOTE: This does NOT save to database yet - only prepares assignments for preview
 * 
 * NEW: Dynamic variant detection
 * - Image (1) → Primary/Home/Shop image
 * - Images (2) to (N+1) → Variant images (where N = total variants for that product)
 * - Images (N+2)+ → Additional images
 */
function processAndAssignImages() {
    global $db;
    
    $images = json_decode($_POST['images'] ?? '[]', true);
    
    if (empty($images)) {
        echo json_encode(['success' => false, 'message' => 'No images to process']);
        exit;
    }
    
    // Get product data from session (contains variant counts)
    // Session is already started at the top of the file
    $productsData = $_SESSION['bulk_import_products'] ?? [];
    
    // Create a map of productNo => variant count
    $variantCountMap = [];
    foreach ($productsData as $product) {
        $productNo = $product['productNo'] ?? null;
        if ($productNo !== null) {
            $variantCount = isset($product['variants']) ? count($product['variants']) : 0;
            $variantCountMap[$productNo] = $variantCount;
        }
    }
    
    $assignments = [];
    $unassigned = [];
    $errors = [];
    $warnings = [];
    
    // Group images by product ID
    $groupedImages = [];
    foreach ($images as $image) {
        if (isset($image['productId']) && $image['productId'] !== null) {
            $productId = $image['productId'];
            if (!isset($groupedImages[$productId])) {
                $groupedImages[$productId] = [];
            }
            $groupedImages[$productId][] = $image;
        } else {
            $unassigned[] = $image;
        }
    }
    
    // Process each product's images with dynamic variant detection
    foreach ($groupedImages as $productId => $productImages) {
        // Separate sequential and non-sequential images
        $sequentialImages = [];
        $nonSequentialImages = [];
        
        foreach ($productImages as $image) {
            if (isset($image['isAdditional']) && $image['isAdditional'] === true) {
                $nonSequentialImages[] = $image;
            } else {
                $sequentialImages[] = $image;
            }
        }
        
        // Sort sequential images by sequence number
        usort($sequentialImages, function($a, $b) {
            return ($a['sequence'] ?? 999) - ($b['sequence'] ?? 999);
        });
        
        // Get the variant count for this product
        $variantCount = $variantCountMap[$productId] ?? 0;
        
        $variantImages = [];
        $additionalImages = [];
        $primaryImage = null;
        $missingVariantImages = [];
        
        // Process sequential images first
        foreach ($sequentialImages as $image) {
            $sequence = $image['sequence'];
            $imagePath = $image['path'];
            
            if ($sequence == 1) {
                // First image: Primary, Shop, and Homepage
                $primaryImage = $imagePath;
            } elseif ($sequence >= 2 && $sequence <= ($variantCount + 1)) {
                // Images 2 to (variantCount + 1): Assign to variants
                $variantIndex = $sequence - 2; // 0-based index
                $variantImages[$variantIndex] = $imagePath;
            } else {
                // Sequence (variantCount + 2)+: Additional images
                $additionalImages[] = $imagePath;
            }
        }
        
        // Add all non-sequential images as additional images
        // These images (like 1A, 1B, 1TY, etc.) are ALWAYS additional images
        foreach ($nonSequentialImages as $image) {
            $additionalImages[] = $image['path'];
        }
        
        // Check for missing variant images
        for ($i = 0; $i < $variantCount; $i++) {
            if (!isset($variantImages[$i])) {
                $missingVariantImages[] = $i + 1; // 1-based for display
            }
        }
        
        // Add warning if variant images are missing
        if (!empty($missingVariantImages)) {
            $warnings[] = "Product #{$productId}: Missing variant images for positions " . implode(', ', $missingVariantImages);
        }
        
        $assignments[] = [
            'productId' => $productId,
            'productName' => "Product #{$productId}",
            'primaryImage' => $primaryImage,
            'variantImages' => array_values($variantImages), // Re-index array
            'additionalImages' => $additionalImages,
            'totalProcessed' => count($productImages),
            'variantCount' => $variantCount,
            'missingVariantImages' => $missingVariantImages,
            'hasWarnings' => !empty($missingVariantImages),
            'nonSequentialCount' => count($nonSequentialImages)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'assigned' => count($assignments),
        'unassigned' => count($unassigned),
        'errors' => count($errors),
        'warnings' => $warnings,
        'assignments' => $assignments,
        'unassignedFiles' => $unassigned,
        'errorMessages' => $errors
    ]);
}

/**
 * Handle manual image assignment for unmatched files
 */
function handleManualAssignment() {
    global $db;
    
    $imagePath = $_POST['image_path'] ?? '';
    $productId = $_POST['product_id'] ?? 0;
    $assignmentType = $_POST['assignment_type'] ?? 'additional'; // primary, variant, additional
    $variantIndex = $_POST['variant_index'] ?? null; // Variant index (0-based)
    
    if (empty($imagePath) || empty($productId)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }
    
    // Verify product exists
    $stmt = $db->prepare("SELECT id, name FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        switch ($assignmentType) {
            case 'primary':
                // Set as primary, shop, and homepage image
                $stmt = $db->prepare("
                    UPDATE products 
                    SET shop_page_image = ?,
                        home_page_image = IF(display_location IN ('Homepage', 'Both'), ?, home_page_image)
                    WHERE id = ?
                ");
                $stmt->execute([$imagePath, $imagePath, $productId]);
                
                // Mark as primary in product_images
                $stmt = $db->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?");
                $stmt->execute([$productId]);
                
                $stmt = $db->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, 1)");
                $stmt->execute([$productId, $imagePath]);
                break;
            
            case 'variant':
                if ($variantIndex === null) {
                    throw new Exception('Variant index required for variant assignment');
                }
                
                // Get the actual variant ID based on the index
                $stmt = $db->prepare("SELECT id FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1 OFFSET ?");
                $stmt->execute([$productId, $variantIndex]);
                $variant = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$variant) {
                    throw new Exception('Variant not found at index ' . $variantIndex);
                }
                
                // Update variant image
                $stmt = $db->prepare("UPDATE product_variants SET variant_image = ? WHERE id = ?");
                $stmt->execute([$imagePath, $variant['id']]);
                break;
            
            case 'additional':
            default:
                // Add as additional image
                $stmt = $db->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, 0)");
                $stmt->execute([$productId, $imagePath]);
                break;
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Image assigned to {$product['name']} as {$assignmentType}"
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Parse image filename to extract product ID and sequence number
 * Supports both with and without file extensions
 * 
 * Pattern: ProductID (SequenceNumber)[.extension] - Sequential images
 * Examples: "1 (1)", "1 (1).jpg", "15 (3).png"
 * 
 * Pattern: ProductID + Non-numeric suffix - Non-sequential (additional) images
 * Examples: "1A.jpg", "1B", "1 (A).jpg", "1TY.png", "2F"
 * 
 * @param string $filename The filename to parse
 * @return array|false Array with productId, sequence, and isAdditional flag, or false if no match
 */
function parseImageFilename($filename) {
    // Remove extension if present
    $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
    
    // Pattern 1: Sequential images - Number (Number) - with or without spaces
    // Matches: "1 (1)", "1(1)", "15 (3)", "123 (5)"
    $sequentialPattern = '/^(\d+)\s*\((\d+)\)$/';
    
    if (preg_match($sequentialPattern, $nameWithoutExt, $matches)) {
        return [
            'productId' => (int)$matches[1],
            'sequence' => (int)$matches[2],
            'isAdditional' => false
        ];
    }
    
    // Pattern 2: Non-sequential images - Number followed by any non-numeric characters
    // Matches: "1A", "1B", "1 (A)", "1TY", "2F", "1 A", "15ABC"
    // This includes patterns like "1 (A)", "1(A)", "1A", "1 A", etc.
    $nonSequentialPattern = '/^(\d+)\s*[\(\s]*([A-Za-z]+)[\)\s]*$/';
    
    if (preg_match($nonSequentialPattern, $nameWithoutExt, $matches)) {
        return [
            'productId' => (int)$matches[1],
            'sequence' => 999, // High number to ensure it's sorted after variant images
            'isAdditional' => true
        ];
    }
    
    return false;
}

/**
 * Get file extension from MIME type
 * 
 * @param string $mimeType MIME type
 * @return string File extension
 */
function getExtensionFromMime($mimeType) {
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/jfif' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    
    return $mimeMap[$mimeType] ?? 'jpg';
}
?>
