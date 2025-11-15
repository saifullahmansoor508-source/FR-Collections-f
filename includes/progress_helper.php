<?php
/**
 * Progress Helper Functions
 * Check user progress for modules, lectures, and quizzes
 */

/**
 * Check if user has completed all lectures and quizzes for Module 1
 * @param PDO $db Database connection
 * @param int $user_id User ID
 * @return array ['completed' => bool, 'details' => array]
 */
function checkModule1Completion($db, $user_id) {
    $result = [
        'completed' => false,
        'total_topics' => 0,
        'completed_topics' => 0,
        'total_quizzes' => 0,
        'passed_quizzes' => 0,
        'missing_topics' => [],
        'missing_quizzes' => []
    ];
    
    try {
        // Get Module 1 ID
        $stmt = $db->prepare("SELECT id FROM course_modules WHERE module_key = 'module01'");
        $stmt->execute();
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$module) {
            return $result;
        }
        
        $module_id = $module['id'];
        
        // Get all VIDEO topics only for Module 1 (quizzes are not separate topics)
        $stmt = $db->prepare("SELECT topic_number, title FROM course_topics WHERE module_id = ? AND type = 'video' AND status = 'active' ORDER BY sort_order");
        $stmt->execute([$module_id]);
        $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result['total_topics'] = count($topics);
        
        // Check completion for each video topic
        foreach ($topics as $topic) {
            // Check if video lecture is completed
            $stmt = $db->prepare("SELECT id FROM completed_topics WHERE user_id = ? AND module_id = ? AND topic_id = ?");
            $stmt->execute([$user_id, $module_id, $topic['topic_number']]);
            
            if ($stmt->fetch()) {
                $result['completed_topics']++;
            } else {
                $result['missing_topics'][] = $topic['title'];
            }
        }
        
        // Check quizzes from quiz_results table (quizzes are tied to video topics)
        $stmt = $db->prepare("SELECT DISTINCT topic_id FROM quiz_results WHERE user_id = ? AND module = 'module01'");
        $stmt->execute([$user_id]);
        $attempted_quizzes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $result['total_quizzes'] = count($topics); // Each video topic has a quiz
        
        // Check how many quizzes are passed
        foreach ($topics as $topic) {
            $stmt = $db->prepare("SELECT passed FROM quiz_results WHERE user_id = ? AND module = 'module01' AND topic_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$user_id, $topic['topic_number']]);
            $quiz_result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($quiz_result && $quiz_result['passed']) {
                $result['passed_quizzes']++;
            } else {
                $result['missing_quizzes'][] = 'Quiz ' . $topic['topic_number'] . ': ' . $topic['title'];
            }
        }
        
        // Check if everything is completed: all videos watched AND all quizzes passed
        $all_videos_complete = ($result['completed_topics'] >= $result['total_topics']);
        $all_quizzes_passed = ($result['passed_quizzes'] >= $result['total_quizzes']);
        
        $result['completed'] = $all_videos_complete && $all_quizzes_passed;
        
    } catch (PDOException $e) {
        // If tables don't exist or error, return incomplete
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Get user progress percentage for a module
 */
function getModuleProgress($db, $user_id, $module_key) {
    try {
        // Get module ID
        $stmt = $db->prepare("SELECT id FROM course_modules WHERE module_key = ?");
        $stmt->execute([$module_key]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$module) {
            return 0;
        }
        
        $module_id = $module['id'];
        
        // Count total active topics
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM course_topics WHERE module_id = ? AND status = 'active'");
        $stmt->execute([$module_id]);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total == 0) {
            return 0;
        }
        
        // Count completed + passed
        $stmt = $db->prepare("
            SELECT 
                (SELECT COUNT(DISTINCT topic_id) FROM completed_topics WHERE user_id = ? AND module_id = ?) as completed_videos,
                (SELECT COUNT(DISTINCT topic_id) FROM quiz_results WHERE user_id = ? AND module = ? AND passed = 1) as passed_quizzes
        ");
        $stmt->execute([$user_id, $module_id, $user_id, $module_key]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $completed = $progress['completed_videos'] + $progress['passed_quizzes'];
        
        return round(($completed / $total) * 100);
        
    } catch (PDOException $e) {
        return 0;
    }
}
?>
