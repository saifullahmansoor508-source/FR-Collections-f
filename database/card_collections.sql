-- Card Collections System Database Schema

-- User card collections table
CREATE TABLE IF NOT EXISTS user_card_collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    card_type ENUM('user_order', 'partner_sale') NOT NULL DEFAULT 'user_order',
    phase_number INT NOT NULL DEFAULT 1,
    card_position INT NOT NULL DEFAULT 1,
    card_gradient_type ENUM('black', 'blue', 'silver', 'golden') NOT NULL,
    is_collected BOOLEAN DEFAULT FALSE,
    collected_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_order_id (order_id),
    INDEX idx_phase (phase_number),
    INDEX idx_card_type (card_type),
    INDEX idx_collected (is_collected),
    UNIQUE KEY unique_user_order_card (user_id, order_id, card_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User phase progress table
CREATE TABLE IF NOT EXISTS user_phase_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    phase_number INT NOT NULL,
    card_type ENUM('user_order', 'partner_sale') NOT NULL DEFAULT 'user_order',
    cards_collected INT DEFAULT 0,
    total_cards INT DEFAULT 10,
    is_phase_completed BOOLEAN DEFAULT FALSE,
    phase_completed_at TIMESTAMP NULL,
    is_unlocked BOOLEAN DEFAULT FALSE,
    unlocked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_phase (phase_number),
    INDEX idx_card_type (card_type),
    INDEX idx_completed (is_phase_completed),
    INDEX idx_unlocked (is_unlocked),
    UNIQUE KEY unique_user_phase_type (user_id, phase_number, card_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize phase 1 as unlocked for all existing users
INSERT IGNORE INTO user_phase_progress (user_id, phase_number, card_type, is_unlocked, unlocked_at)
SELECT id, 1, 'user_order', TRUE, NOW() FROM users;

-- Initialize phase 1 as unlocked for affiliates (partner_sale type)
INSERT IGNORE INTO user_phase_progress (user_id, phase_number, card_type, is_unlocked, unlocked_at)
SELECT u.id, 1, 'partner_sale', TRUE, NOW() 
FROM users u 
INNER JOIN affiliates a ON u.id = a.user_id;
