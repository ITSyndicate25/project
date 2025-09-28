<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/FOPHScrapMD/config.php';
require_once APP_ROOT . '/includes/functions.php';

header('Content-Type: application/json');

try {
    // Initialize application
    initializeApp();
    
    // Check authentication
    if (!getCurrentUserId()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    $user_id = getCurrentUserId();
    $action = $_GET['action'] ?? $_POST['action'] ?? 'get';
    
    switch ($action) {
        case 'get':
            $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';
            $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
            
            $notifications = getUserNotifications($user_id, $unread_only, $limit);
            $unread_count = getUnreadNotificationCount($user_id);
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unread_count
            ]);
            break;
            
        case 'mark_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            
            $notification_id = intval($_POST['notification_id'] ?? 0);
            
            if ($notification_id > 0) {
                $success = markNotificationAsRead($notification_id, $user_id);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
            }
            break;
            
        case 'mark_all_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit();
            }
            
            $count = markAllNotificationsAsRead($user_id);
            echo json_encode(['success' => true, 'count' => $count]);
            break;
            
        case 'count':
            $count = getUnreadNotificationCount($user_id);
            echo json_encode(['success' => true, 'count' => $count]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }

} catch (Exception $e) {
    logError("Notifications API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>