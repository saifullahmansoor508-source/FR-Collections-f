<?php
/**
 * Session Management Helper
 * 
 * Functions to manage user sessions, including force logout of specific users
 * 
 * @package    FR Collections
 * @version    1.0
 */

/**
 * Clear all session files for a specific user
 * This function removes session data from the session storage
 * 
 * @param int $user_id User ID to clear sessions for
 * @return bool True if successful, false otherwise
 */
function clearUserSessions($user_id) {
    $session_path = session_save_path();
    
    // If session path is empty, use default tmp directory
    if (empty($session_path)) {
        $session_path = sys_get_temp_dir();
    }
    
    // Get all session files
    $session_files = glob($session_path . '/sess_*');
    
    if ($session_files === false) {
        return false;
    }
    
    $cleared = false;
    
    foreach ($session_files as $file) {
        // Read session file content
        $session_data = @file_get_contents($file);
        
        if ($session_data === false) {
            continue;
        }
        
        // Check if this session belongs to the user
        // Session data format: variable_name|serialized_value
        if (strpos($session_data, 'user_id') !== false && strpos($session_data, '"' . $user_id . '"') !== false) {
            // Delete the session file
            @unlink($file);
            $cleared = true;
        }
    }
    
    return $cleared;
}

/**
 * Create a flag file to indicate user should be logged out
 * This is an alternative method that works with session checking
 * 
 * @param int $user_id User ID to flag for logout
 * @return bool True if successful, false otherwise
 */
function flagUserForLogout($user_id) {
    $flag_dir = __DIR__ . '/../uploads/temp/';
    
    // Create directory if it doesn't exist
    if (!is_dir($flag_dir)) {
        @mkdir($flag_dir, 0755, true);
    }
    
    $flag_file = $flag_dir . 'logout_user_' . $user_id . '.flag';
    
    return file_put_contents($flag_file, time()) !== false;
}

/**
 * Check if user has a logout flag and clear it
 * 
 * @param int $user_id User ID to check
 * @return bool True if flag exists (user should be logged out), false otherwise
 */
function checkLogoutFlag($user_id) {
    $flag_file = __DIR__ . '/../uploads/temp/logout_user_' . $user_id . '.flag';
    
    if (file_exists($flag_file)) {
        @unlink($flag_file);
        return true;
    }
    
    return false;
}
