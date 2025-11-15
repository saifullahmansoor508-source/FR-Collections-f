<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Get module and topic from URL
$module = isset($_GET['module']) ? $_GET['module'] : 'module01';
$topic_id = isset($_GET['topic']) ? intval($_GET['topic']) : 1;

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Fetch module from database
try {
    // Try with is_active first, fallback to status for compatibility
    $stmt = $db->prepare("SELECT * FROM course_modules WHERE module_key = ?");
    $stmt->execute([$module]);
    $current_module = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_module) {
        header('Location: modules.php');
        exit();
    }
    
    // Fetch topics for this module (check both status and is_active for compatibility)
    $stmt = $db->prepare("SELECT * FROM course_topics WHERE module_id = ? AND (status = 'active' OR is_active = 1 OR status IS NULL) ORDER BY sort_order ASC");
    $stmt->execute([$current_module['id']]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($topics)) {
        header('Location: modules.php');
        exit();
    }
    
    // Convert topics to indexed array by topic_number for compatibility
    $topics_by_number = [];
    foreach ($topics as $topic) {
        $topics_by_number[$topic['topic_number']] = $topic;
    }
    
    // Get current topic
    $current_topic = null;
    foreach ($topics as $topic) {
        if ($topic['id'] == $topic_id || $topic['topic_number'] == $topic_id) {
            $current_topic = $topic;
            break;
        }
    }
    
    if (!$current_topic) {
        header('Location: modules.php');
        exit();
    }
    
    $total_topics = count($topics);
    
} catch (PDOException $e) {
    // Fallback to first module if database error
    header('Location: modules.php');
    exit();
}

// Check if topic is already completed (from completed_topics table - syncs with admin)
$is_completed = false;
try {
    $stmt = $db->prepare("SELECT id FROM completed_topics WHERE user_id = ? AND module_id = ? AND topic_id = ?");
    $stmt->execute([$user_id, $current_module['id'], $topic_id]);
    $completion = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($completion) {
        $is_completed = true;
    }
} catch (PDOException $e) {
    // Continue even if query fails
}

require_once 'includes/header.php';
?>

<!-- Custom Dialog System -->
<link rel="stylesheet" href="assets/css/custom-dialogs.css">
<script src="assets/js/custom-dialogs.js"></script>

<style>
body {
    background: #f8fafc;
}

.lecture-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 30px 15px;
}

.lecture-header {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.breadcrumb-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.9rem;
    color: #64748b;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.breadcrumb-nav a {
    color: #3b82f6;
    text-decoration: none;
    transition: color 0.3s ease;
}

.breadcrumb-nav a:hover {
    color: #2563eb;
}

.breadcrumb-separator {
    color: #cbd5e1;
}

.lecture-title-section {
    display: flex;
    align-items: center;
    gap: 15px;
}

.topic-badge {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 800;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    flex-shrink: 0;
}

.lecture-title-text h1 {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 5px 0;
}

.lecture-title-text p {
    font-size: 0.95rem;
    color: #64748b;
    margin: 0;
}

.video-container {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.video-wrapper {
    position: relative;
    padding-bottom: 177.78%; /* 9:16 aspect ratio for YT Shorts */
    height: 0;
    overflow: hidden;
    border-radius: 12px;
    background: #000;
    max-width: 500px;
    margin: 0 auto;
}

.video-wrapper iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
}

.video-placeholder {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
}

.video-placeholder i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.video-placeholder p {
    font-size: 1.1rem;
    margin: 0;
}

.course-home-btn {
    width: 100%;
    max-width: 300px;
    margin: 20px auto;
    padding: 12px 25px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
}

.course-home-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
}

.lecture-navigation {
    display: flex;
    gap: 15px;
    margin-top: 25px;
}

.nav-btn {
    flex: 1;
    padding: 15px 25px;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.nav-btn-prev {
    background: #f1f5f9;
    color: #475569;
}

.nav-btn-prev:hover {
    background: #e2e8f0;
    transform: translateX(-5px);
}

.nav-btn-next {
    background: <?php echo $current_module['gradient']; ?>;
    color: white;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
}

.nav-btn-next:hover {
    transform: translateX(5px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

.nav-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.nav-btn:disabled:hover {
    transform: none;
}

.language-toggle-btn {
    width: 100%;
    max-width: 500px;
    margin: 15px auto;
    padding: 12px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.language-toggle-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.3);
}

.complete-btn {
    width: 100%;
    max-width: 500px;
    margin: 15px auto;
    padding: 15px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.complete-btn:disabled {
    background: linear-gradient(135deg, #9ca3af, #6b7280);
    cursor: not-allowed;
    opacity: 0.6;
}

.complete-btn:not(:disabled):hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
}

/* Solve Quiz Button - Animated and Beautiful */
.solve-quiz-btn {
    width: 100%;
    max-width: 500px;
    margin: 15px auto;
    padding: 18px 35px;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #f59e0b 100%);
    background-size: 200% auto;
    color: white;
    border: none;
    border-radius: 15px;
    font-weight: 800;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
    position: relative;
    overflow: hidden;
    animation: slideInUp 0.6s ease-out, pulse 2s ease-in-out infinite;
}

.solve-quiz-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
        45deg,
        transparent,
        rgba(255, 255, 255, 0.3),
        transparent
    );
    transform: rotate(45deg);
    animation: shine 3s ease-in-out infinite;
}

.solve-quiz-btn:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 12px 35px rgba(245, 158, 11, 0.5);
    background-position: right center;
}

.solve-quiz-btn:active {
    transform: translateY(-1px) scale(0.98);
}

.solve-quiz-btn i:first-child {
    font-size: 1.3rem;
    animation: bounce 2s ease-in-out infinite;
}

.solve-quiz-btn i:last-child {
    font-size: 1rem;
    animation: slideRight 1.5s ease-in-out infinite;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes pulse {
    0%, 100% {
        box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
    }
    50% {
        box-shadow: 0 12px 35px rgba(245, 158, 11, 0.6);
    }
}

@keyframes shine {
    0% {
        left: -50%;
    }
    100% {
        left: 150%;
    }
}

@keyframes bounce {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

@keyframes slideRight {
    0%, 100% {
        transform: translateX(0);
    }
    50% {
        transform: translateX(5px);
    }
}

.watch-progress {
    max-width: 500px;
    margin: 15px auto;
    text-align: center;
    font-size: 0.9rem;
    color: #64748b;
    font-weight: 600;
}

.topic-progress {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.progress-header h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.progress-text {
    font-size: 0.9rem;
    color: #64748b;
    font-weight: 600;
}

.progress-bar-container {
    width: 100%;
    height: 10px;
    background: #e5e7eb;
    border-radius: 20px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background: <?php echo $current_module['gradient']; ?>;
    border-radius: 20px;
    transition: width 0.5s ease;
}

@media (max-width: 768px) {
    .lecture-title-text h1 {
        font-size: 1.3rem;
    }
    
    .course-home-btn {
        max-width: 100%;
        margin: 15px 0;
    }
    
    .lecture-navigation {
        flex-direction: column;
    }
    
    .nav-btn {
        width: 100%;
    }
    
    .video-container {
        padding: 15px;
    }
}
</style>

<div class="lecture-container">
    <!-- Breadcrumb Navigation -->
    <div class="lecture-header">
        <div class="breadcrumb-nav">
            <a href="affiliate.php">Affiliate Dashboard</a>
            <span class="breadcrumb-separator">›</span>
            <a href="<?php echo $module; ?>.php"><?php echo htmlspecialchars($current_module['module_name']); ?></a>
            <span class="breadcrumb-separator">›</span>
            <span>Topic <?php echo $current_topic['topic_number']; ?></span>
        </div>
        
        <div class="lecture-title-section">
            <div class="topic-badge" style="background: <?php echo $current_module['gradient']; ?>;">
                <?php echo $current_topic['topic_number']; ?>
            </div>
            <div class="lecture-title-text">
                <h1><?php echo htmlspecialchars($current_topic['title']); ?></h1>
                <p><?php echo htmlspecialchars($current_module['module_name']); ?> - Topic <?php echo $current_topic['topic_number']; ?> of <?php echo $total_topics; ?></p>
            </div>
        </div>
    </div>

    <!-- Progress Indicator -->
    <div class="topic-progress">
        <div class="progress-header">
            <h3>Course Progress</h3>
            <span class="progress-text"><?php echo $topic_id; ?> / <?php echo $total_topics; ?> Topics</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar-fill" style="width: <?php echo round(($topic_id / $total_topics) * 100); ?>%;"></div>
        </div>
    </div>

    <!-- Video Container -->
    <div class="video-container">
        <div class="video-wrapper">
            <?php 
            $video_en = isset($current_topic['video_en']) ? $current_topic['video_en'] : (isset($current_topic['video']) ? $current_topic['video'] : 'YOUR_VIDEO_ID');
            $video_ur = isset($current_topic['video_ur']) ? $current_topic['video_ur'] : 'YOUR_VIDEO_ID';
            $has_video = (strpos($video_en, 'YOUR_VIDEO_ID') === false);
            ?>
            <?php if ($has_video): ?>
                <!-- YouTube Embed with YouTube API -->
                <iframe 
                    id="videoPlayer"
                    src="https://www.youtube.com/embed/<?php echo htmlspecialchars($video_en); ?>?enablejsapi=1&rel=0&modestbranding=1&controls=1&disablekb=1&fs=0&iv_load_policy=3&autoplay=0&playsinline=1&origin=<?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?>" 
                    allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                    allowfullscreen>
                </iframe>
            <?php else: ?>
                <!-- Placeholder for missing video -->
                <div class="video-placeholder">
                    <i class="fas fa-video"></i>
                    <p>Video will be available soon</p>
                    <p style="font-size: 0.85rem; opacity: 0.7; margin-top: 10px;">Please contact admin to add video content</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($has_video): ?>
        <!-- Watch Again Button -->
        <button class="language-toggle-btn" id="watchAgainBtn" onclick="watchAgain()" style="background: linear-gradient(135deg, #8b5cf6, #6366f1); display: none;">
            <i class="fas fa-redo"></i>
            <span>Watch Again</span>
        </button>
        <?php endif; ?>
        
        <?php if ($has_video && strpos($video_ur, 'YOUR_VIDEO_ID') === false): ?>
        <!-- Language Toggle Button -->
        <button class="language-toggle-btn" id="languageToggle" onclick="toggleLanguage()">
            <i class="fas fa-language"></i>
            <span id="langText">Watch in Urdu</span>
        </button>
        <?php endif; ?>
        
        <!-- Watch Progress -->
        <div class="watch-progress" id="watchProgress">
            <i class="fas fa-clock"></i> Please watch <span id="requiredPercent">90</span>% of the video to unlock completion
        </div>
        
        <!-- Debug Info (helpful for troubleshooting) -->
        <div style="max-width: 500px; margin: 10px auto; text-align: center; font-size: 0.85rem; color: #94a3b8;">
            <div id="debugInfo" style="display: none;">
                📊 Debug: <span id="debugText">Waiting for video...</span>
            </div>
        </div>
        
        <!-- Mark as Complete Button -->
        <button class="complete-btn" id="completeBtn" onclick="markComplete()" 
                <?php if ($is_completed): ?>
                style="background: linear-gradient(135deg, #10b981, #059669); cursor: default;"
                <?php else: ?>
                disabled
                <?php endif; ?>>
            <i class="fas fa-check-circle"></i>
            <span id="completeBtnText"><?php echo $is_completed ? 'Completed!' : 'Watch 90% to unlock'; ?></span>
        </button>
        
        <!-- Solve Quiz Button (appears after completion) -->
        <button class="solve-quiz-btn" id="solveQuizBtn" 
                onclick="window.location.href='quiz.php?module=<?php echo $module; ?>&topic=<?php echo $topic_id; ?>'"
                style="display: <?php echo $is_completed ? 'flex' : 'none'; ?>;">
            <i class="fas fa-clipboard-check"></i>
            <span>Solve Quiz</span>
            <i class="fas fa-arrow-right"></i>
        </button>
    </div>

    <!-- Navigation Buttons -->
    <div class="lecture-navigation">
        <?php if ($topic_id > 1): ?>
            <a href="lecture.php?module=<?php echo $module; ?>&topic=<?php echo ($topic_id - 1); ?>" class="nav-btn nav-btn-prev">
                <i class="fas fa-chevron-left"></i>
                Previous Topic
            </a>
        <?php else: ?>
            <button class="nav-btn nav-btn-prev" disabled>
                <i class="fas fa-chevron-left"></i>
                Previous Topic
            </button>
        <?php endif; ?>

        <?php if ($topic_id < $total_topics): ?>
            <a href="lecture.php?module=<?php echo $module; ?>&topic=<?php echo ($topic_id + 1); ?>" class="nav-btn nav-btn-next">
                Next Topic
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <a href="<?php echo $module; ?>.php" class="nav-btn nav-btn-next">
                Back to Course
                <i class="fas fa-home"></i>
            </a>
        <?php endif; ?>
    </div>

    <!-- Course Home Button -->
    <a href="<?php echo $module; ?>.php" class="course-home-btn">
        <i class="fas fa-home"></i>
        Course Home
    </a>
</div>

<!-- Suppress console warnings FIRST (before YouTube loads) -->
<script>
// Comprehensive YouTube warning suppression - MUST RUN FIRST!
(function() {
    const originalConsoleError = console.error;
    const originalConsoleWarn = console.warn;
    const originalConsoleLog = console.log;

    // List of patterns to suppress
    const suppressPatterns = [
        'postMessage',
        'youtube.com',
        'www.youtube.com',
        'Content Security Policy',
        'unsafe-eval',
        'unsafe-inline',
        'script-src',
        'script-src-elem',
        'GroupMarkerNotSet',
        'WebGL',
        'aria-hidden',
        'ytp-',
        'ytp-play-button',
        'ytp-chrome',
        'assistive technology',
        'swiftshader',
        'iframe',
        'enablejsapi',
        'crbug.com',
        'Blocked aria-hidden',
        'WAI-ARIA',
        'w3c.github.io',
        'report-only',
        'Understand this warning'
    ];

    function shouldSuppress(message) {
        const str = message?.toString() || '';
        return suppressPatterns.some(pattern => str.includes(pattern));
    }

    console.error = function(...args) {
        if (shouldSuppress(args[0])) return;
        originalConsoleError.apply(console, args);
    };

    console.warn = function(...args) {
        if (shouldSuppress(args[0])) return;
        originalConsoleWarn.apply(console, args);
    };

    console.log = function(...args) {
        if (shouldSuppress(args[0])) return;
        originalConsoleLog.apply(console, args);
    };
})();
</script>

<!-- Load YouTube IFrame API -->
<script src="https://www.youtube.com/iframe_api"></script>

<script>

// Video configuration
const videoConfig = {
    en: '<?php echo $video_en; ?>',
    ur: '<?php echo isset($video_ur) ? $video_ur : ""; ?>',
    currentLang: 'en'
};

// Check if already completed
const isAlreadyCompleted = <?php echo $is_completed ? 'true' : 'false'; ?>;

let player;
let videoDuration = 0;
let watchInterval;
let universalTracker;
let hasUnlockedComplete = isAlreadyCompleted; // Start as true if already completed

// Anti-cheat tracking: Actual watched segments
let watchedSegments = new Set(); // Set of seconds actually watched
let lastPosition = 0;
let lastCheckTime = Date.now();
let requiredWatchPercentage = 90;
let isPlayerReady = false;
let skipWarningShown = false;
let autoCompleteTriggered = false;

// Initialize YouTube Player with error handling
function onYouTubeIframeAPIReady() {
    <?php if ($has_video): ?>
    try {
        player = new YT.Player('videoPlayer', {
            events: {
                'onReady': onPlayerReady,
                'onStateChange': onPlayerStateChange
            }
        });
    } catch (error) {
        console.log('YouTube player initialization:', error.message);
    }
    <?php endif; ?>
}

function onPlayerReady(event) {
    videoDuration = player.getDuration();
    isPlayerReady = true;
    console.log('✅ Video ready. Duration:', videoDuration, 'seconds');
    
    // If already completed, hide watch progress and show completed state
    if (isAlreadyCompleted) {
        console.log('✅ Topic already completed');
        document.getElementById('watchProgress').style.display = 'none';
        document.getElementById('debugInfo').style.display = 'none';
        return; // Don't start tracker if already completed
    }
    
    // Start universal tracker (always running)
    if (!universalTracker) {
        universalTracker = setInterval(universalTrackTime, 1000);
        console.log('✅ Universal tracker started');
    }
    
    // Update progress display initially
    updateProgressDisplay(0);
}

function onPlayerStateChange(event) {
    const states = ['UNSTARTED', 'ENDED', 'PLAYING', 'PAUSED', 'BUFFERING', 'CUED'];
    console.log('📹 Player state:', states[event.data + 1] || event.data);
    
    if (event.data == YT.PlayerState.PLAYING) {
        lastCheckTime = Date.now();
        console.log('▶️ Video playing - tracking active');
    } else if (event.data == YT.PlayerState.ENDED) {
        console.log('🏁 Video ended');
        
        // Check if user watched enough and auto-complete if 100%
        const progressPercent = (watchedSegments.size / videoDuration) * 100;
        console.log('📊 Video ended. Watched:', Math.round(progressPercent) + '%');
        
        // Auto-complete if watched 95%+ (to account for slight timing differences)
        if (progressPercent >= 95 && !autoCompleteTriggered && !isAlreadyCompleted) {
            console.log('🎉 Auto-completing topic (100% watched)');
            autoCompleteTriggered = true;
            
            // If not already unlocked, unlock now
            if (!hasUnlockedComplete) {
                unlockCompletion();
            }
            
            // Auto-mark as complete
            setTimeout(function() {
                markComplete();
            }, 1000);
        } else {
            checkVideoCompletion();
        }
        
        // Only show Watch Again button if NOT completed
        if (!isAlreadyCompleted && !autoCompleteTriggered) {
            document.getElementById('watchAgainBtn').style.display = 'flex';
        }
    }
}

// Universal tracker - ANTI-CHEAT: Only counts continuous watching
function universalTrackTime() {
    try {
        if (!player || !player.getCurrentTime) {
            return;
        }
        
        const currentTime = Math.floor(player.getCurrentTime());
        const playerState = player.getPlayerState ? player.getPlayerState() : -1;
        
        if (!videoDuration || videoDuration === 0) {
            videoDuration = player.getDuration();
        }
        
        if (videoDuration > 0 && playerState === 1) { // Only when PLAYING
            const timeDiff = Math.abs(currentTime - lastPosition);
            
            // ANTI-CHEAT: Detect skipping (jump > 3 seconds)
            if (timeDiff > 3 && lastPosition > 0) {
                console.log('⚠️ Skip detected! Jumped from', lastPosition, 'to', currentTime);
                
                if (!skipWarningShown) {
                    skipWarningShown = true;
                    CustomDialog.warning(
                        'Skipping Detected!',
                        'Please watch the video continuously without skipping. Your progress will only count if you watch the video properly.',
                        function() {
                            skipWarningShown = false;
                        }
                    );
                }
                
                lastPosition = currentTime;
                return; // Don't count this second
            }
            
            // Only count if moving forward naturally (0-2 seconds)
            if (timeDiff >= 0 && timeDiff <= 2) {
                // Mark this second as actually watched
                watchedSegments.add(currentTime);
                
                // Also mark previous position if it's just 1 second ahead
                if (timeDiff === 1) {
                    watchedSegments.add(lastPosition);
                }
            }
            
            lastPosition = currentTime;
            lastCheckTime = Date.now();
            
            // Calculate actual watched percentage (segments / duration)
            const progressPercent = (watchedSegments.size / videoDuration) * 100;
            
            // Update display
            updateProgressDisplay(progressPercent);
            
            // Unlock at 90%
            if (progressPercent >= requiredWatchPercentage && !hasUnlockedComplete) {
                console.log('🎉 Unlocking at', Math.round(progressPercent) + '%');
                console.log('📊 Watched', watchedSegments.size, 'out of', Math.floor(videoDuration), 'seconds');
                unlockCompletion();
            }
        }
    } catch (error) {
        console.log('⚠️ Tracking error:', error.message);
    }
}

function updateProgressDisplay(watchedPercent) {
    const progressEl = document.getElementById('watchProgress');
    const roundedPercent = Math.round(watchedPercent);
    
    // Update debug info
    const debugInfo = document.getElementById('debugInfo');
    const debugText = document.getElementById('debugText');
    if (videoDuration > 0) {
        debugInfo.style.display = 'block';
        debugText.textContent = `Watched: ${watchedSegments.size}/${Math.floor(videoDuration)}s (${roundedPercent}%)`;
    }
    
    if (roundedPercent >= requiredWatchPercentage) {
        progressEl.innerHTML = 
            '<i class="fas fa-check-circle" style="color: #10b981;"></i> <strong>Great!</strong> You watched ' + roundedPercent + '% - You can mark as complete now!';
        progressEl.style.color = '#10b981';
    } else {
        progressEl.innerHTML = 
            '<i class="fas fa-eye"></i> Actually Watched: <strong>' + roundedPercent + '%</strong> / ' + requiredWatchPercentage + '% required';
        progressEl.style.color = '#64748b';
    }
}

function unlockCompletion() {
    if (hasUnlockedComplete) return; // Prevent multiple unlocks
    
    hasUnlockedComplete = true;
    const completeBtn = document.getElementById('completeBtn');
    completeBtn.disabled = false;
    completeBtn.querySelector('#completeBtnText').textContent = 'Mark as Complete';
    
    console.log('🎉 UNLOCKED! Complete button enabled');
    
    // Show success message
    CustomDialog.success(
        'Well Done!',
        'You have watched enough of the video. You can now mark this topic as complete and continue to the next one!'
    );
}

function checkVideoCompletion() {
    if (!hasUnlockedComplete && videoDuration > 0) {
        const progressPercent = (watchedSegments.size / videoDuration) * 100;
        console.log('🔍 Final check - Watched:', watchedSegments.size, 'seconds (', Math.round(progressPercent) + '%)');
        
        if (progressPercent >= requiredWatchPercentage) {
            unlockCompletion();
        }
    }
}

// Watch Again Function
function watchAgain() {
    if (player && player.seekTo) {
        player.seekTo(0);
        player.playVideo();
        document.getElementById('watchAgainBtn').style.display = 'none';
    }
}

// Language Toggle
function toggleLanguage() {
    if (!videoConfig.ur || videoConfig.ur.includes('YOUR_VIDEO_ID')) {
        CustomDialog.error(
            'Urdu Version Not Available',
            'The Urdu version of this video is not available yet. Please contact the administrator.'
        );
        return;
    }
    
    const langText = document.getElementById('langText');
    
    if (videoConfig.currentLang === 'en') {
        // Switch to Urdu
        player.loadVideoById(videoConfig.ur);
        videoConfig.currentLang = 'ur';
        langText.textContent = 'Watch in English';
    } else {
        // Switch to English
        player.loadVideoById(videoConfig.en);
        videoConfig.currentLang = 'en';
        langText.textContent = 'Watch in Urdu';
    }
    
    // Reset watch tracking
    watchedSegments.clear();
    lastPosition = 0;
    lastCheckTime = Date.now();
    hasUnlockedComplete = false;
    skipWarningShown = false;
    document.getElementById('watchAgainBtn').style.display = 'none';
    updateProgressDisplay(0);
    
    // Re-enable complete button if needed
    const completeBtn = document.getElementById('completeBtn');
    completeBtn.disabled = true;
    completeBtn.querySelector('#completeBtnText').textContent = 'Watch 90% to unlock';
}

function markComplete() {
    // If already completed, just show info message
    if (isAlreadyCompleted) {
        CustomDialog.success(
            'Already Completed!',
            'You have already completed this topic. You can watch it again for review.'
        );
        return;
    }
    
    if (!hasUnlockedComplete) {
        CustomDialog.warning(
            'Cannot Mark Complete',
            'Please watch at least ' + requiredWatchPercentage + '% of the video before marking as complete. You cannot skip the video!'
        );
        return;
    }
    
    // Show loading state
    const btn = document.getElementById('completeBtn');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<div class="dialog-loading"></div> Completing...';
    btn.disabled = true;
    
    // Send AJAX request to mark topic as complete
    fetch('ajax/mark_topic_complete.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            module: '<?php echo $module; ?>',
            topic_id: <?php echo $topic_id; ?>,
            watched_percent: Math.round((watchedSegments.size / videoDuration) * 100)
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Mark as completed locally
            isAlreadyCompleted = true;
            
            // Show success message
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Completed!';
            btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
            btn.style.cursor = 'default';
            
            // Hide watch progress and debug info
            document.getElementById('watchProgress').style.display = 'none';
            document.getElementById('debugInfo').style.display = 'none';
            
            // Show Solve Quiz button with animation
            const quizBtn = document.getElementById('solveQuizBtn');
            quizBtn.style.display = 'flex';
            quizBtn.style.animation = 'slideInUp 0.6s ease-out, pulse 2s ease-in-out infinite';
            
            // Update progress bar
            const progressFill = document.querySelector('.progress-bar-fill');
            const nextTopic = <?php echo min($topic_id + 1, $total_topics); ?>;
            progressFill.style.width = Math.round((nextTopic / <?php echo $total_topics; ?>) * 100) + '%';
            
            // Show completion dialog
            <?php if ($topic_id < $total_topics): ?>
            CustomDialog.success(
                'Topic Completed!',
                'Great job! Moving to the next topic...',
                function() {
                    window.location.href = 'lecture.php?module=<?php echo $module; ?>&topic=<?php echo ($topic_id + 1); ?>';
                }
            );
            
            // Auto redirect after 2 seconds
            setTimeout(() => {
                window.location.href = 'lecture.php?module=<?php echo $module; ?>&topic=<?php echo ($topic_id + 1); ?>';
            }, 2000);
            <?php else: ?>
            CustomDialog.success(
                'Module Completed!',
                'Congratulations! You have completed all topics in this module.',
                function() {
                    window.location.href = '<?php echo $module; ?>.php';
                }
            );
            <?php endif; ?>
        }
    })
    .catch(error => {
        console.error('Error:', error);
        btn.innerHTML = originalHTML;
        btn.disabled = false;
        CustomDialog.error(
            'Error',
            'Failed to mark as complete. Please check your internet connection and try again.'
        );
    });
}

// Initialize on page load
<?php if (!$has_video): ?>
// No video, enable complete button immediately for placeholder
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('completeBtn').disabled = false;
    document.getElementById('completeBtnText').textContent = 'Mark as Complete';
    document.getElementById('watchProgress').style.display = 'none';
});
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
