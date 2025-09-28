<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/FOPHScrapMD/config.php';
require_once APP_ROOT . '/includes/functions.php';

// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get user information safely
$current_user_id = getCurrentUserId();
$current_user_roles = getCurrentUserRoles();
$user_name = getUserDisplayName();
$is_admin = isAdmin();

// Determine profile URL based on roles
$profile_url = BASE_URL . '/pages/user/profile.php'; // Default

if ($is_admin) {
    $profile_url = BASE_URL . '/pages/admin/profile.php';
} elseif (hasAnyRole(['approver', 'checker', 'noter'])) {
    $profile_url = BASE_URL . '/pages/approver/profile.php';
}
?>

<nav class="navbar navbar-expand-lg navbar-absolute fixed-top navbar-transparent">
    <style>
        .dropdown-menu {
            background-color: white !important;
            border: 1px solid rgba(0, 0, 0, 0.15) !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important;
            border-radius: 6px !important;
            padding: 8px 0 !important;
            min-width: 200px !important;
            z-index: 1050 !important;
        }

        .dropdown-item {
            color: #333 !important;
            padding: 10px 20px !important;
            font-size: 14px !important;
            transition: all 0.2s ease !important;
            border: none !important;
            background: none !important;
        }

        .dropdown-item:hover {
            background-color: #526170ff !important;
            color: #264c7eff !important;
            transform: translateX(5px);
        }

        .dropdown-item i {
            margin-right: 8px;
            width: 16px;
            text-align: center;
        }

        .user-info {
            border-bottom: 1px solid #e9ecef;
            margin-bottom: 8px;
            padding-bottom: 12px;
        }

        .user-info .dropdown-item {
            pointer-events: none;
            color: #6c757d !important;
            font-size: 12px;
            padding: 5px 20px;
        }

        .search-container {
            position: relative;
        }

        .search-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }

        .search-suggestion {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f1f1f1;
            transition: background-color 0.2s;
        }

        .search-suggestion:hover {
            background-color: #f8f9fa;
        }

        .search-suggestion:last-child {
            border-bottom: none;
        }

        .navbar-brand {
            font-weight: 600;
            color: #264c7eff !important;
        }

        .sidebar-toggle-btn {
            background: none;
            border: none;
            color: #51cbce;
            font-size: 18px;
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.3s ease;
            margin-right: 10px;
        }

        .sidebar-toggle-btn:hover {
            background: rgba(81, 203, 206, 0.1);
            color: #41b3b6;
        }

        .sidebar-toggle-btn:focus {
            outline: none;
            box-shadow: none;
        }

        /* Enhanced notification badge */
        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: linear-gradient(135deg, #dc3545, #e74c3c);
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        /* Enhanced notification dropdown styling */
        #notificationsDropdown + .dropdown-menu {
            min-width: 320px !important;
            max-width: 400px !important;
            max-height: 500px !important;
            overflow-y: auto !important;
            padding: 0 !important;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15) !important;
            border-radius: 8px !important;
        }

        /* Notification header */
        .notification-header {
            background: linear-gradient(135deg, #264c7eff, #526170ff);
            color: white;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            font-size: 12px;
        }

        /* Enhanced notification items */
        .notification-item {
            padding: 16px 20px !important;
            border-left: 4px solid transparent !important;
            transition: all 0.3s ease !important;
            border-bottom: 1px solid #f8f9fa !important;
            display: block !important;
            text-decoration: none !important;
            color: inherit !important;
            background: none !important;
        }

        .notification-item:last-child {
            border-bottom: none !important;
        }

        .notification-item.unread {
            background: linear-gradient(90deg, #f8f9fa, #ffffff) !important;
            border-left-color: #264c7eff !important;
        }

        .notification-item:hover {
            background: linear-gradient(90deg, #e9ecef, #f8f9fa) !important;
            transform: translateX(3px) !important;
            color: inherit !important;
            text-decoration: none !important;
        }

        /* Notification content layout */
        .notification-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .notification-icon {
            min-width: 24px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-top: 2px;
        }

        .notification-icon.rts_pending { background: #e3f2fd; color: #1976d2; }
        .notification-icon.rts_approved { background: #e8f5e8; color: #2e7d32; }
        .notification-icon.rts_rejected { background: #ffebee; color: #c62828; }
        .notification-icon.rts_checked { background: #fff3e0; color: #f57c00; }
        .notification-icon.rts_noted { background: #f3e5f5; color: #7b1fa2; }

        .notification-details {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 600;
            font-size: 14px;
            line-height: 1.4;
            margin-bottom: 4px;
            color: #2c3e50;
        }

        .notification-message {
            font-size: 13px;
            line-height: 1.4;
            color: #6c757d;
            margin-bottom: 6px;
        }

        .notification-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #868e96;
        }

        .notification-time {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .notification-requestor {
            font-weight: 500;
            color: #264c7eff;
        }

        .notification-control-no {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            margin-top: 4px;
        }

        /* Status badges */
        .notification-status {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 500;
        }

        .status-new { background: #dc3545; color: white; }
        .status-unread { background: #17a2b8; color: white; }

        /* Mark all as read button */
        .mark-all-read {
            background: #f8f9fa !important;
            text-align: center !important;
            padding: 12px 20px !important;
            font-weight: 600 !important;
            color: #264c7eff !important;
            border-top: 1px solid #e9ecef !important;
            transition: all 0.2s ease !important;
            margin-top: auto !important;
            cursor: pointer !important;
            border-left: none !important;
            transform: none !important;
        }

        .mark-all-read:hover {
            background: #e9ecef !important;
            color: #1e3a5f !important;
            text-decoration: none !important;
            transform: none !important;
        }

        /* Empty state */
        .notifications-empty {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .notifications-empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Loading state */
        .notifications-loading {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }

        /* Scrollbar styling for webkit browsers */
        #notificationsDropdown + .dropdown-menu::-webkit-scrollbar {
            width: 6px;
        }

        #notificationsDropdown + .dropdown-menu::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        #notificationsDropdown + .dropdown-menu::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        #notificationsDropdown + .dropdown-menu::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Mobile view fixes */
        @media (max-width: 768px) {
            .navbar-nav {
                background: white;
                padding: 10px;
                border-radius: 8px;
                margin-top: 10px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }

            .nav-link {
                color: #333 !important;
                padding: 8px 12px !important;
            }

            .dropdown-menu {
                position: static !important;
                float: none !important;
                box-shadow: none !important;
                border: none !important;
                background: #f8f9fa !important;
                margin-top: 5px !important;
            }

            #notificationsDropdown + .dropdown-menu {
                min-width: 280px !important;
                max-width: 90vw !important;
                position: static !important;
            }
            
            .notification-item {
                padding: 12px 16px !important;
            }
            
            .notification-header {
                padding: 12px 16px;
                font-size: 14px;
            }

            .search-container {
                margin-bottom: 15px;
            }

            .input-group {
                background: white;
                border-radius: 6px;
            }

            .sidebar-toggle-btn {
                display: none;
            }
        }
    </style>

    <div class="container-fluid">
        <div class="navbar-wrapper">
            <div class="navbar-toggle">
                <!-- Desktop Sidebar Toggle -->
                <button type="button" class="sidebar-toggle-btn d-none d-lg-block" onclick="toggleSidebar()">
                    <i class="nc-icon nc-minimal-left"></i>
                </button>
                <!-- Mobile Menu Toggle -->
                <button type="button" class="navbar-toggler d-lg-none" onclick="toggleMobileSidebar()">
                    <span class="navbar-toggler-bar bar1"></span>
                    <span class="navbar-toggler-bar bar2"></span>
                    <span class="navbar-toggler-bar bar3"></span>
                </button>
            </div>
            <a class="navbar-brand" href="javascript:void(0);">
                Dashboard
            </a>
        </div>

        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navigation"
            aria-controls="navigation-index" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-bar navbar-kebab"></span>
            <span class="navbar-toggler-bar navbar-kebab"></span>
            <span class="navbar-toggler-bar navbar-kebab"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="navigation">
            <form class="search-container">
                <div class="input-group no-border">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search" autocomplete="off">
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <i class="nc-icon nc-zoom-split"></i>
                        </div>
                    </div>
                </div>
                <div id="searchSuggestions" class="search-suggestions"></div>
            </form>

            <ul class="navbar-nav">
                <!-- Enhanced Notifications -->
                <li class="nav-item btn-rotate dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="notificationsDropdown" 
                       data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="nc-icon nc-bell-55"></i>
                        <span id="notificationBadge" class="notification-badge" style="display: none;">0</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="notificationsDropdown">
                        <div class="notification-header">
                            <span><i class="fas fa-bell mr-2"></i>Notifications</span>
                            <span id="notificationCountBadge" class="badge" style="display: none;">0</span>
                        </div>
                        <div id="notificationsList">
                            <div class="notifications-loading">
                                <i class="fas fa-spinner fa-spin"></i>
                                <div>Loading notifications...</div>
                            </div>
                        </div>
                    </div>
                </li>

                <!-- Settings Menu -->
                <li class="nav-item btn-rotate dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink"
                        data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="nc-icon nc-settings-gear-65"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="navbarDropdownMenuLink">

                        <a class="dropdown-item" href="<?php echo htmlspecialchars($profile_url); ?>">
                            <i class="fas fa-user-circle"></i> Profile
                        </a>

                        <div class="dropdown-divider"></div>

                        <a class="dropdown-item" href="#" id="logoutLink">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<script>
    // Simple sidebar toggle functions
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const mainPanel = document.querySelector('.main-panel');

        if (window.innerWidth > 991) {
            sidebar.classList.toggle('collapsed');
            mainPanel.classList.toggle('expanded');
        }
    }

    function toggleMobileSidebar() {
        const sidebar = document.querySelector('.sidebar');

        if (window.innerWidth <= 991) {
            sidebar.classList.toggle('mobile-open');
        }
    }

    (function() {
        // Initialize navbar functionality
        document.addEventListener('DOMContentLoaded', function() {
            initializeSearch();
            initializeLogout();
            initializeNotifications();

            // Toggle navbar on mobile
            document.querySelectorAll('.navbar-toggler').forEach(button => {
                button.addEventListener('click', function() {
                    this.classList.toggle('active');
                });
            });
        });

        // Search functionality
        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            const suggestions = document.getElementById('searchSuggestions');
            const sidebarNav = document.getElementById('sidebarNav');

            if (!searchInput || !sidebarNav) return;

            let searchItems = [];

            // Build search index
            sidebarNav.querySelectorAll('a').forEach(link => {
                const text = link.textContent.trim();
                const href = link.getAttribute('href');
                if (text && href && !href.includes('javascript:')) {
                    searchItems.push({
                        text,
                        href,
                        element: link.closest('li')
                    });
                }
            });

            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();

                if (query.length < 2) {
                    suggestions.style.display = 'none';
                    resetSidebarItems();
                    return;
                }

                const matches = searchItems.filter(item =>
                    item.text.toLowerCase().includes(query)
                );

                if (matches.length > 0) {
                    showSuggestions(matches, query);
                    filterSidebarItems(matches);
                } else {
                    showNoResults();
                    hideAllSidebarItems();
                }
            });

            searchInput.addEventListener('blur', function() {
                // Delay hiding to allow clicks on suggestions
                setTimeout(() => {
                    suggestions.style.display = 'none';
                }, 200);
            });

            function showSuggestions(matches, query) {
                suggestions.innerHTML = matches.map(item => `
                <div class="search-suggestion" data-href="${item.href}">
                    <strong>${highlightMatch(item.text, query)}</strong>
                </div>
            `).join('');

                suggestions.style.display = 'block';

                // Add click handlers
                suggestions.querySelectorAll('.search-suggestion').forEach(el => {
                    el.addEventListener('click', function() {
                        window.location.href = this.dataset.href;
                    });
                });
            }

            function showNoResults() {
                suggestions.innerHTML = '<div class="search-suggestion">No results found</div>';
                suggestions.style.display = 'block';
            }

            function highlightMatch(text, query) {
                const regex = new RegExp(`(${escapeRegex(query)})`, 'gi');
                return text.replace(regex, '<mark>$1</mark>');
            }

            function escapeRegex(string) {
                return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function filterSidebarItems(matches) {
                const matchElements = new Set(matches.map(m => m.element));
                sidebarNav.querySelectorAll('li').forEach(item => {
                    item.style.display = matchElements.has(item) ? '' : 'none';
                });
            }

            function hideAllSidebarItems() {
                sidebarNav.querySelectorAll('li').forEach(item => {
                    item.style.display = 'none';
                });
            }

            function resetSidebarItems() {
                sidebarNav.querySelectorAll('li').forEach(item => {
                    item.style.display = '';
                });
            }
        }

        // Logout functionality
        function initializeLogout() {
            const logoutLink = document.getElementById('logoutLink');
            if (!logoutLink) return;

            logoutLink.addEventListener('click', function(e) {
                e.preventDefault();

                let timerInterval;
                const timerDuration = 5;

                Swal.fire({
                    title: `You'll be logged out in ${timerDuration} seconds`,
                    html: 'You will be logged out automatically.<br/>You can cancel this action by clicking the cancel button.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Log Out Now',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#264c7eff',
                    cancelButtonColor: '#6c757d',
                    timer: timerDuration * 1000,
                    timerProgressBar: true,
                    allowOutsideClick: false,
                    didOpen: () => {
                        timerInterval = setInterval(() => {
                            const timeLeft = Math.ceil(Swal.getTimerLeft() / 1000);
                            Swal.update({
                                title: `You'll be logged out in ${timeLeft} seconds`,
                            });
                        }, 1000);
                    },
                    willClose: () => {
                        clearInterval(timerInterval);
                    }
                }).then((result) => {
                    if (result.dismiss === Swal.DismissReason.timer || result.isConfirmed) {
                        // Show loading state
                        Swal.fire({
                            title: 'Logging out...',
                            text: 'Please wait while we securely log you out.',
                            icon: 'info',
                            allowOutsideClick: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });

                        // Redirect after delay
                        setTimeout(() => {
                            window.location.href = "<?php echo BASE_URL; ?>/logout.php";
                        }, 1000);
                    }
                });
            });
        }

        // Enhanced Notifications functionality
        function initializeNotifications() {
            const badge = document.getElementById('notificationBadge');
            const countBadge = document.getElementById('notificationCountBadge');
            const list = document.getElementById('notificationsList');

            // Load notifications on page load
            loadNotifications();

            // Auto-refresh notifications every 30 seconds
            setInterval(loadNotifications, 30000);

            function loadNotifications() {
                fetch('<?php echo BASE_URL; ?>/includes/notifications.php?action=get&limit=15')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateNotifications(data.notifications, data.unread_count);
                        } else {
                            showError();
                        }
                    })
                    .catch(error => {
                        console.error('Error loading notifications:', error);
                        showError();
                    });
            }

            function updateNotifications(notifications, unreadCount) {
                // Update badges
                updateNotificationBadges(unreadCount);

                // Update notifications list
                if (notifications.length === 0) {
                    showEmptyState();
                } else {
                    showNotifications(notifications, unreadCount);
                }
            }

            function updateNotificationBadges(unreadCount) {
                if (unreadCount > 0) {
                    badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                    badge.style.display = 'flex';
                    countBadge.textContent = unreadCount;
                    countBadge.style.display = 'inline';
                } else {
                    badge.style.display = 'none';
                    countBadge.style.display = 'none';
                }
            }

            function showNotifications(notifications, unreadCount) {
                let notificationsHtml = '';

                notifications.forEach(notif => {
                    const isUnread = !notif.is_read;
                    const icon = getNotificationIcon(notif.type);
                    const timeAgo = getTimeAgo(notif.created_at_iso);
                    const requestorName = notif.display_requestor || notif.requestor_name || 'Unknown User';

                    notificationsHtml += `
                        <a class="notification-item ${isUnread ? 'unread' : ''}" 
                           href="${notif.url || '#'}" 
                           data-notification-id="${notif.id}"
                           onclick="markAsRead(${notif.id}, event)">
                            <div class="notification-content">
                                <div class="notification-icon ${notif.type}">
                                    <i class="${icon}"></i>
                                </div>
                                <div class="notification-details">
                                    <div class="notification-title">${escapeHtml(notif.title)}</div>
                                    <div class="notification-message">
                                        ${escapeHtml(notif.message)}
                                    </div>
                                    <div class="notification-meta">
                                        <div class="notification-time">
                                            <i class="fas fa-clock"></i>
                                            <span>${timeAgo}</span>
                                        </div>
                                        <div class="notification-requestor">
                                            By: ${escapeHtml(requestorName)}
                                        </div>
                                    </div>
                                    ${notif.control_no ? `<div class="notification-control-no">${escapeHtml(notif.control_no)}</div>` : ''}
                                </div>
                                ${isUnread ? '<span class="notification-status status-new">New</span>' : ''}
                            </div>
                        </a>`;
                });

                // Add "Mark all as read" if there are unread notifications
                if (unreadCount > 0) {
                    notificationsHtml += `
                        <div class="mark-all-read" onclick="markAllAsRead(event)">
                            <i class="fas fa-check-double mr-2"></i>
                            Mark all as read (${unreadCount})
                        </div>`;
                }

                list.innerHTML = notificationsHtml;
            }

            function showEmptyState() {
                list.innerHTML = `
                    <div class="notifications-empty">
                        <i class="fas fa-inbox"></i>
                        <div><strong>No notifications</strong></div>
                        <div class="text-muted">You're all caught up!</div>
                    </div>`;
            }

            function showError() {
                list.innerHTML = `
                    <div class="notifications-empty">
                        <i class="fas fa-exclamation-triangle text-warning"></i>
                        <div><strong>Unable to load notifications</strong></div>
                        <div class="text-muted">Please try refreshing the page</div>
                    </div>`;
            }

            // Mark single notification as read
            window.markAsRead = function(notificationId, event) {
                if (!notificationId) return;

                fetch('<?php echo BASE_URL; ?>/includes/notifications.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=mark_read&notification_id=${notificationId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI immediately
                            const notifElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
                            if (notifElement) {
                                notifElement.classList.remove('unread');
                                const statusBadge = notifElement.querySelector('.status-new');
                                if (statusBadge) statusBadge.remove();
                            }

                            // Refresh notifications to update count
                            setTimeout(loadNotifications, 300);
                        }
                    })
                    .catch(error => console.error('Error marking notification as read:', error));
            };

            // Mark all notifications as read
            window.markAllAsRead = function(event) {
                event.preventDefault();
                event.stopPropagation();

                fetch('<?php echo BASE_URL; ?>/includes/notifications.php', {
                        method: 'POST',
                                                headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=mark_all_read'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Reload notifications to reflect changes
                            loadNotifications();
                        }
                    })
                    .catch(error => console.error('Error marking all notifications as read:', error));
            };

            function getNotificationIcon(type) {
                const iconMap = {
                    'rts_pending': 'fas fa-clock',
                    'rts_approved': 'fas fa-check-circle',
                    'rts_rejected': 'fas fa-times-circle',
                    'rts_checked': 'fas fa-eye',
                    'rts_noted': 'fas fa-sticky-note',
                    'system': 'fas fa-cog',
                    'default': 'fas fa-bell'
                };
                return iconMap[type] || iconMap.default;
            }

            function getTimeAgo(timestamp) {
                if (!timestamp) return 'Just now';

                try {
                    const now = new Date();
                    const notifTime = new Date(timestamp);
                    const diffMs = now - notifTime;
                    const diffSeconds = Math.floor(diffMs / 1000);
                    const diffMinutes = Math.floor(diffSeconds / 60);
                    const diffHours = Math.floor(diffMinutes / 60);
                    const diffDays = Math.floor(diffHours / 24);

                    if (diffSeconds < 60) return 'Just now';
                    if (diffMinutes < 60) return `${diffMinutes}m ago`;
                    if (diffHours < 24) return `${diffHours}h ago`;
                    if (diffDays < 7) return `${diffDays}d ago`;
                    if (diffDays < 30) return `${Math.floor(diffDays / 7)}w ago`;
                    if (diffDays < 365) return `${Math.floor(diffDays / 30)}mo ago`;
                    return `${Math.floor(diffDays / 365)}y ago`;
                } catch (e) {
                    return 'Recently';
                }
            }

            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        }

        // Handle responsive behavior
        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('.sidebar');
            const mainPanel = document.querySelector('.main-panel');

            if (window.innerWidth <= 991) {
                // Mobile view
                if (sidebar) {
                    sidebar.classList.remove('collapsed');
                    sidebar.classList.remove('mobile-open');
                }
                if (mainPanel) {
                    mainPanel.classList.remove('expanded');
                }
            } else {
                // Desktop view
                if (sidebar) {
                    sidebar.classList.remove('mobile-open');
                }
            }
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const toggleButton = document.querySelector('.navbar-toggler');
            
            if (window.innerWidth <= 991 && 
                sidebar && 
                sidebar.classList.contains('mobile-open') &&
                !sidebar.contains(event.target) &&
                !toggleButton.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const dropdowns = document.querySelectorAll('.dropdown-menu.show');
            dropdowns.forEach(dropdown => {
                const toggle = dropdown.previousElementSibling;
                if (toggle && 
                    !toggle.contains(event.target) && 
                    !dropdown.contains(event.target)) {
                    dropdown.classList.remove('show');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        });

        // Handle dropdown toggles
        document.addEventListener('click', function(event) {
            if (event.target.matches('[data-toggle="dropdown"]') || 
                event.target.closest('[data-toggle="dropdown"]')) {
                event.preventDefault();
                
                const trigger = event.target.closest('[data-toggle="dropdown"]');
                const menu = trigger.nextElementSibling;
                
                if (menu && menu.classList.contains('dropdown-menu')) {
                    // Close other dropdowns
                    document.querySelectorAll('.dropdown-menu.show').forEach(otherMenu => {
                        if (otherMenu !== menu) {
                            otherMenu.classList.remove('show');
                            otherMenu.previousElementSibling.setAttribute('aria-expanded', 'false');
                        }
                    });
                    
                    // Toggle current dropdown
                    const isOpen = menu.classList.contains('show');
                    if (isOpen) {
                        menu.classList.remove('show');
                        trigger.setAttribute('aria-expanded', 'false');
                    } else {
                        menu.classList.add('show');
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                }
            }
        });

        // Initialize tooltips if Bootstrap tooltips are available
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.addEventListener('DOMContentLoaded', function() {
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function(tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            });
        }

        // Smooth scrolling for anchor links
        document.addEventListener('click', function(event) {
            const link = event.target.closest('a[href^="#"]');
            if (link && link.getAttribute('href') !== '#') {
                const target = document.querySelector(link.getAttribute('href'));
                if (target) {
                    event.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });

        // Prevent form submission on search
        document.addEventListener('submit', function(event) {
            if (event.target.querySelector('#searchInput')) {
                event.preventDefault();
            }
        });

        // Add loading states to navigation links
        document.addEventListener('click', function(event) {
            const link = event.target.closest('a[href]');
            if (link && 
                link.getAttribute('href') !== '#' && 
                !link.getAttribute('href').startsWith('javascript:') &&
                !link.hasAttribute('data-toggle') &&
                !link.classList.contains('notification-item')) {
                
                // Add loading indicator
                const originalText = link.innerHTML;
                const spinner = '<i class="fas fa-spinner fa-spin mr-2"></i>';
                
                // Only add spinner to links with text content
                if (link.textContent.trim()) {
                    link.innerHTML = spinner + link.textContent.trim();
                    
                    // Remove spinner after navigation or timeout
                    setTimeout(() => {
                        if (link.innerHTML.includes('fa-spinner')) {
                            link.innerHTML = originalText;
                        }
                    }, 3000);
                }
            }
        });

    })();

    // Global utility functions
    window.showToast = function(message, type = 'info') {
        // Simple toast notification (can be enhanced with a toast library)
        console.log(`${type.toUpperCase()}: ${message}`);
        
        // If SweetAlert2 is available, use it for better notifications
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                text: message,
                icon: type,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                toast: true
            });
        }
    };

    // Expose notification refresh function globally
    window.refreshNotifications = function() {
        const event = new CustomEvent('refreshNotifications');
        document.dispatchEvent(event);
    };
</script>