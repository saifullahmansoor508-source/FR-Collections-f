<?php
/**
 * FR Collections - Database Connection Class
 * 
 * This class handles database connections and automatic schema validation.
 * It automatically checks and creates missing tables/columns to prevent PDO errors
 * and ensures database consistency across all environments.
 * 
 * @package    FR Collections
 * @version    1.0
 * @author     FR Collections Team
 */
class Database {
    // Database credentials
    private $host = 'localhost';
    private $db_name = 'newsite';
    private $username = 'root';
    private $password = '';
    
    // Connection object
    public $conn;
    
    // Schema validation flag (static to validate only once per request)
    private static $schemaValidated = false;

    /**
     * Get database connection with automatic schema validation
     * 
     * @return PDO Database connection object
     */
    public function getConnection() {
        $this->conn = null;
        
        try {
            // Create PDO connection
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                $this->username, 
                $this->password
            );
            
            // Set UTF-8 encoding
            $this->conn->exec("set names utf8mb4");
            
            // Set error mode to exceptions
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Validate schema once per request
            if (!self::$schemaValidated) {
                $this->validateSchema();
                self::$schemaValidated = true;
            }
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
    
    /**
     * Validate and auto-fix database schema
     * Checks for missing tables and columns and creates them automatically
     * 
     * @return void
     */
    private function validateSchema() {
        try {
            // Check and create missing tables
            $this->checkShopSlidersTable();
            $this->checkCouponsTable();
            $this->checkAffiliateEarningsTable();
            $this->checkFavoritesTable();
            $this->checkWithdrawalsTable();
            $this->checkVariantTypesTable();
            $this->checkVariantAttributesTable();
            $this->checkVariantAttributeValuesTable();
            $this->checkProductVariantCombinationsTable();
            $this->checkCombinationAttributeMapTable();
            
            // Check and add missing columns
            $this->checkProductsColumns();
            $this->checkProductVariantsColumns();
            $this->checkAffiliatesColumns();
            $this->checkCartColumns();
            $this->checkOrderItemsColumns();
            $this->checkOrdersColumns();
            $this->checkUsersColumns();
        } catch (Exception $e) {
            error_log("Schema validation error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if table exists
     */
    private function tableExists($tableName) {
        try {
            $result = $this->conn->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
            return $result !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Check if a column exists in a table
     */
    private function columnExists($table, $column) {
        try {
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    
    /**
     * Create shop_sliders table if not exists
     */
    private function checkShopSlidersTable() {
        if (!$this->tableExists('shop_sliders')) {
            $sql = "CREATE TABLE IF NOT EXISTS `shop_sliders` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `image` VARCHAR(255) NOT NULL,
                `title` VARCHAR(255) DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `status` ENUM('active', 'inactive') DEFAULT 'active',
                `sort_order` INT DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Create coupons table if not exists
     */
    private function checkCouponsTable() {
        if (!$this->tableExists('coupons')) {
            $sql = "CREATE TABLE IF NOT EXISTS `coupons` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `code` VARCHAR(50) UNIQUE NOT NULL,
                `discount_type` ENUM('percentage', 'fixed') DEFAULT 'percentage',
                `discount_value` DECIMAL(10,2) NOT NULL,
                `min_order_amount` DECIMAL(10,2) DEFAULT 0.00,
                `max_discount_amount` DECIMAL(10,2) DEFAULT NULL,
                `usage_limit` INT DEFAULT NULL,
                `used_count` INT DEFAULT 0,
                `expiry_date` DATE DEFAULT NULL,
                `status` ENUM('active', 'inactive') DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Create affiliate_earnings table if not exists
     */
    private function checkAffiliateEarningsTable() {
        if (!$this->tableExists('affiliate_earnings')) {
            $sql = "CREATE TABLE IF NOT EXISTS `affiliate_earnings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `affiliate_id` INT NOT NULL,
                `order_id` INT NOT NULL,
                `order_item_id` INT NOT NULL,
                `product_id` INT NOT NULL,
                `commission_amount` DECIMAL(10,2) NOT NULL,
                `status` ENUM('Pending', 'Confirmed', 'Paid') DEFAULT 'Pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_affiliate_id` (`affiliate_id`),
                INDEX `idx_order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Create favorites table if not exists
     */
    private function checkFavoritesTable() {
        if (!$this->tableExists('favorites')) {
            $sql = "CREATE TABLE IF NOT EXISTS `favorites` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `product_id` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_favorite` (`user_id`, `product_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Create withdrawals table if not exists
     */
    private function checkWithdrawalsTable() {
        if (!$this->tableExists('withdrawals')) {
            $sql = "CREATE TABLE IF NOT EXISTS `withdrawals` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `method` ENUM('JazzCash', 'Easypaisa', 'Upaisa') NOT NULL,
                `account_number` VARCHAR(20) NOT NULL,
                `status` ENUM('Pending', 'Completed', 'Rejected') DEFAULT 'Pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                INDEX `idx_user_id` (`user_id`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    
    /**
     * Create variant_types table if not exists
     */
    private function checkVariantTypesTable() {
        if (!$this->tableExists('variant_types')) {
            $sql = "CREATE TABLE IF NOT EXISTS `variant_types` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `type_name` VARCHAR(100) UNIQUE NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
            
            // Insert default variant types
            $this->conn->exec("INSERT IGNORE INTO `variant_types` (`type_name`) VALUES 
                ('Color'), ('Design'), ('Size'), ('Style'), ('Material'), ('Brand')");
        }
    }
    
    /**
     * Create variant_attributes table if not exists
     */
    private function checkVariantAttributesTable() {
        if (!$this->tableExists('variant_attributes')) {
            $sql = "CREATE TABLE IF NOT EXISTS `variant_attributes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `attribute_name` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
                INDEX `idx_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Create variant_attribute_values table if not exists
     */
    private function checkVariantAttributeValuesTable() {
        if (!$this->tableExists('variant_attribute_values')) {
            $sql = "CREATE TABLE IF NOT EXISTS `variant_attribute_values` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `attribute_id` INT NOT NULL,
                `value_name` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`attribute_id`) REFERENCES `variant_attributes`(`id`) ON DELETE CASCADE,
                INDEX `idx_attribute` (`attribute_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Create product_variant_combinations table if not exists
     */
    private function checkProductVariantCombinationsTable() {
        if (!$this->tableExists('product_variant_combinations')) {
            $sql = "CREATE TABLE IF NOT EXISTS `product_variant_combinations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `sku` VARCHAR(255) UNIQUE,
                `price` DECIMAL(10,2),
                `stock_quantity` INT DEFAULT 0,
                `image_path` VARCHAR(255),
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
                INDEX `idx_product` (`product_id`),
                INDEX `idx_sku` (`sku`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Create combination_attribute_map table if not exists
     */
    private function checkCombinationAttributeMapTable() {
        if (!$this->tableExists('combination_attribute_map')) {
            $sql = "CREATE TABLE IF NOT EXISTS `combination_attribute_map` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `combination_id` INT NOT NULL,
                `attribute_value_id` INT NOT NULL,
                FOREIGN KEY (`combination_id`) REFERENCES `product_variant_combinations`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`attribute_value_id`) REFERENCES `variant_attribute_values`(`id`) ON DELETE CASCADE,
                UNIQUE KEY `unique_mapping` (`combination_id`, `attribute_value_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($sql);
        }
    }
    
    /**
     * Check and add missing columns in products table
     * 
     * @return void
     */
    private function checkProductsColumns() {
        if ($this->tableExists('products')) {
            // Get all existing columns
            $columns = $this->getTableColumns('products');
            
            // Add delivery_charges column
            if (!$this->columnExists('products', 'delivery_charges')) {
                $sql = "ALTER TABLE `products` ADD COLUMN `delivery_charges` DECIMAL(10,2) DEFAULT 0.00";
                $this->conn->exec($sql);
            }
            
            // Add home_page_image column
            if (!in_array('home_page_image', $columns)) {
                $sql = "ALTER TABLE `products` ADD COLUMN `home_page_image` VARCHAR(255) DEFAULT NULL AFTER `display_location`";
                $this->conn->exec($sql);
            }
            
            // Add shop_page_image column
            if (!in_array('shop_page_image', $columns)) {
                $sql = "ALTER TABLE `products` ADD COLUMN `shop_page_image` VARCHAR(255) DEFAULT NULL AFTER `home_page_image`";
                $this->conn->exec($sql);
            }
            
            // Add sales_count column
            if (!$this->columnExists('products', 'sales_count')) {
                $sql = "ALTER TABLE `products` ADD COLUMN `sales_count` INT DEFAULT 0";
                $this->conn->exec($sql);
            }
            
            // Add stock_count column
            if (!$this->columnExists('products', 'stock_count')) {
                $sql = "ALTER TABLE `products` ADD COLUMN `stock_count` INT DEFAULT 0";
                $this->conn->exec($sql);
            }
            
            // Migrate description fields if needed
            // Rename short_description to description, backup old description
            if (in_array('short_description', $columns) && in_array('description', $columns)) {
                // Backup old description to temp column if it has data
                if (!in_array('old_description_backup', $columns)) {
                    $this->conn->exec("ALTER TABLE `products` ADD COLUMN `old_description_backup` TEXT AFTER `description`");
                    $this->conn->exec("UPDATE `products` SET `old_description_backup` = `description` WHERE `description` IS NOT NULL AND `description` != ''");
                }
                
                // Drop old description column
                $this->conn->exec("ALTER TABLE `products` DROP COLUMN `description`");
                
                // Rename short_description to description
                $this->conn->exec("ALTER TABLE `products` CHANGE COLUMN `short_description` `description` TEXT");
            } elseif (in_array('short_description', $columns)) {
                // Just rename if description doesn't exist
                $this->conn->exec("ALTER TABLE `products` CHANGE COLUMN `short_description` `description` TEXT");
            }
        }
    }
    
    /**
     * Check and add missing columns in product_variants table
     * 
     * @return void
     */
    private function checkProductVariantsColumns() {
        if ($this->tableExists('product_variants')) {
            // Add stock_count column
            if (!$this->columnExists('product_variants', 'stock_count')) {
                $sql = "ALTER TABLE `product_variants` ADD COLUMN `stock_count` INT DEFAULT 0";
                $this->conn->exec($sql);
            }
        }
    }
    
    /**
     * Get all column names from a table
     * 
     * @param string $tableName Table name to get columns from
     * @return array Array of column names
     */
    private function getTableColumns($tableName) {
        try {
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM `{$tableName}`");
            $stmt->execute();
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            return $columns;
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Check and add missing columns in affiliates table
     */
    private function checkAffiliatesColumns() {
        if ($this->tableExists('affiliates')) {
            // Add total_earnings column
            if (!$this->columnExists('affiliates', 'total_earnings')) {
                $sql = "ALTER TABLE `affiliates` ADD COLUMN `total_earnings` DECIMAL(10,2) DEFAULT 0.00";
                $this->conn->exec($sql);
            }
        }
    }
    
    /**
     * Check and add missing columns in cart table
     */
    private function checkCartColumns() {
        if ($this->tableExists('cart')) {
            // Add variant_combination_id column
            if (!$this->columnExists('cart', 'variant_combination_id')) {
                $sql = "ALTER TABLE `cart` ADD COLUMN `variant_combination_id` INT NULL COMMENT 'References product_variant_combinations.id' AFTER `variant_id`";
                $this->conn->exec($sql);
            }
            
            // Add variant_selections column
            if (!$this->columnExists('cart', 'variant_selections')) {
                $sql = "ALTER TABLE `cart` ADD COLUMN `variant_selections` TEXT NULL COMMENT 'JSON string of selected variants' AFTER `variant_combination_id`";
                $this->conn->exec($sql);
            }
        }
    }
    
    /**
     * Check and add missing columns in order_items table
     */
    private function checkOrderItemsColumns() {
        if ($this->tableExists('order_items')) {
            // Add commission_amount column
            if (!$this->columnExists('order_items', 'commission_amount')) {
                $sql = "ALTER TABLE `order_items` ADD COLUMN `commission_amount` DECIMAL(10,2) DEFAULT 0.00";
                $this->conn->exec($sql);
            }
            
            // Add variant_combination_id column
            if (!$this->columnExists('order_items', 'variant_combination_id')) {
                $sql = "ALTER TABLE `order_items` ADD COLUMN `variant_combination_id` INT NULL COMMENT 'References product_variant_combinations.id' AFTER `variant_id`";
                $this->conn->exec($sql);
            }
            
            // Add variant_selections column
            if (!$this->columnExists('order_items', 'variant_selections')) {
                $sql = "ALTER TABLE `order_items` ADD COLUMN `variant_selections` TEXT NULL COMMENT 'JSON string of selected variants' AFTER `variant_combination_id`";
                $this->conn->exec($sql);
            }
        }
    }
    
    /**
     * Check and add missing columns in orders table
     */
    private function checkOrdersColumns() {
        if ($this->tableExists('orders')) {
            // Add coupon_code column if missing
            if (!$this->columnExists('orders', 'coupon_code')) {
                $sql = "ALTER TABLE `orders` ADD COLUMN `coupon_code` VARCHAR(50) DEFAULT NULL";
                $this->conn->exec($sql);
            }
            
            // Add payment_method column if missing
            if (!$this->columnExists('orders', 'payment_method')) {
                $sql = "ALTER TABLE `orders` ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL";
                $this->conn->exec($sql);
            }
            
            // Add payment_account_name column if missing
            if (!$this->columnExists('orders', 'payment_account_name')) {
                $sql = "ALTER TABLE `orders` ADD COLUMN `payment_account_name` VARCHAR(255) DEFAULT NULL";
                $this->conn->exec($sql);
            }
            
            // Add payment_account_number column if missing
            if (!$this->columnExists('orders', 'payment_account_number')) {
                $sql = "ALTER TABLE `orders` ADD COLUMN `payment_account_number` VARCHAR(50) DEFAULT NULL";
                $this->conn->exec($sql);
            }
            
            // Add partner_id column for affiliate tracking
            if (!$this->columnExists('orders', 'partner_id')) {
                $sql = "ALTER TABLE `orders` ADD COLUMN `partner_id` VARCHAR(20) DEFAULT NULL COMMENT 'Affiliate partner ID used for this order'";
                $this->conn->exec($sql);
            }
            
        }
    }
    
    
    /**
     * Check and add missing columns in users table
     */
    private function checkUsersColumns() {
        if ($this->tableExists('users')) {
            // Add role column if missing
            if (!$this->columnExists('users', 'role')) {
                $sql = "ALTER TABLE `users` ADD COLUMN `role` ENUM('user','partner') DEFAULT 'user'";
                $this->conn->exec($sql);
            }
            
        }
    }
}
