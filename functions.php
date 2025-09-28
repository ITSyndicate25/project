<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/FOPHScrapMD/config.php';

// ===========================================
// AUTHENTICATION & AUTHORIZATION
// ===========================================

/**
 * Admin access check (uses config.php function)
 */
function checkAdminAccess() {
    checkEnhancedAdminAccess();
}

/**
 * Logging function for security events
 */
function logSecurityEvent($event_type, $user_id = null, $details = '') {
    logMessage('security', "$event_type: $details", [
        'event_type' => $event_type,
        'user_id' => $user_id ?? getCurrentUserId() ?? 'anonymous',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
}

/**
 * Safe redirect function that prevents header injection
 */
function safeRedirect($url, $permanent = false) {
    $parsed_url = parse_url($url);
    if (!$parsed_url || isset($parsed_url['scheme']) || isset($parsed_url['host'])) {
        $url = BASE_URL . '/';
    }
    
    logSecurityEvent('redirect', null, "Redirecting to: $url");
    
    http_response_code($permanent ? 301 : 302);
    header("Location: $url");
    exit();
}

// ===========================================
// UI HELPER FUNCTIONS
// ===========================================

/**
 * Check if page is active for sidebar highlighting
 */
function isActive($pages) {
    $currentPage = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $pages = is_array($pages) ? $pages : [$pages];
    return in_array($currentPage, $pages) ? 'active' : '';
}

/**
 * Get user's display name safely
 */
function getUserDisplayName($user_data = null) {
    if (!$user_data && isset($_SESSION['username'])) {
        return htmlspecialchars($_SESSION['username']);
    }
    
    if (is_array($user_data)) {
        return htmlspecialchars($user_data['requestor_name'] ?? $user_data['username'] ?? 'Unknown User');
    }
    
    return 'Unknown User';
}

// ===========================================
// PERMISSION SYSTEM
// ===========================================

/**
 * Check scrap form permission based on department and role
 */
function checkScrapPermission($user_department, $user_role) {
    $allowed_departments = ['Engineering', 'Logistics', 'LX', 'INSTAX', 'PT', 'LENS'];
    return ($user_role === 'admin') || in_array($user_department, $allowed_departments);
}

/**
 * Get user department by user ID
 */
function getUserDepartment($user_id) {
    try {
        $db = getDatabaseConnection();
        $stmt = sqlsrv_query($db->link, "SELECT department FROM users WHERE user_id = ?", [$user_id]);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            return $row['department'];
        }
        
        return null;
    } catch (Exception $e) {
        logError("Error getting user department: " . $e->getMessage());
        return null;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

// ===========================================
// USER MANAGEMENT
// ===========================================

/**
 * Add new user to database with optional e-signature
 */
function addUser($username, $password, $role, $requestor_name, $department, $id_number, $email, $category, $e_signature = null) {
    // Validate inputs
    $fields = compact('username', 'password', 'role', 'requestor_name', 'department', 'id_number', 'email', 'category');
    foreach ($fields as $field => $value) {
        if (empty(trim($value))) {
            return ['success' => false, 'message' => 'All fields are required.'];
        }
    }
    
    // Sanitize inputs
    $username = sanitizeInput($username);
    $requestor_name = sanitizeInput($requestor_name);
    $department = sanitizeInput($department);
    $id_number = sanitizeInput($id_number);
    $email = sanitizeEmail($email);
    
    if (!validateEmail($email)) {
        return ['success' => false, 'message' => 'Invalid email format.'];
    }
    
    $role = is_array($role) ? implode(',', $role) : trim($role);
    $category = is_array($category) ? implode(',', $category) : trim($category);
    
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    if ($password_hash === false) {
        return ['success' => false, 'message' => 'Failed to create password hash.'];
    }
    
    try {
        $db = getDatabaseConnection();
        
        // Check for existing username or email
        $check_sql = "SELECT COUNT(*) AS count FROM users WHERE username = ? OR email = ?";
        $check_stmt = sqlsrv_query($db->link, $check_sql, [$username, $email]);
        
        if (!$check_stmt) {
            throw new Exception("Database error during uniqueness check");
        }
        
        $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
        if ($row && $row['count'] > 0) {
            return ['success' => false, 'message' => 'Username or Email already exists.'];
        }
        
        // Insert user
        $insert_sql = "INSERT INTO users (username, password, requestor_name, department, id_number, email, category, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [$username, $password_hash, $requestor_name, $department, $id_number, $email, $category, $role, date(DATETIME_FORMAT)];
        
        $insert_stmt = sqlsrv_query($db->link, $insert_sql, $params);
        if (!$insert_stmt) {
            throw new Exception("Failed to insert user");
        }
        
        // Handle signature upload if provided
        if ($e_signature && $e_signature['error'] === UPLOAD_ERR_OK) {
            $imageData = file_get_contents($e_signature['tmp_name']);
            if ($imageData !== false) {
                // Get the new user ID
                $id_stmt = sqlsrv_query($db->link, "SELECT user_id FROM users WHERE username = ?", [$username]);
                if ($id_stmt && $id_row = sqlsrv_fetch_array($id_stmt, SQLSRV_FETCH_ASSOC)) {
                    $userId = $id_row['user_id'];
                    
                    $update_sql = "UPDATE users SET e_signiture = ? WHERE user_id = ?";
                    $update_params = [
                        [$imageData, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_IMAGE],
                        [$userId, SQLSRV_PARAM_IN]
                    ];
                    
                    $update_stmt = sqlsrv_prepare($db->link, $update_sql, $update_params);
                    if (!$update_stmt || !sqlsrv_execute($update_stmt)) {
                        logError("Failed to update e_signature for user $userId");
                    }
                }
            }
        }
        
        logDatabase('INSERT', 'users', ['username' => $username], 'success');
        return ['success' => true, 'message' => 'User added successfully.'];
        
    } catch (Exception $e) {
        logError("Error adding user: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Get users data for DataTables with server-side processing
 */
function getUsersForDataTable($params) {
    $draw = intval($params['draw'] ?? 0);
    $start = intval($params['start'] ?? 0);
    $length = intval($params['length'] ?? 10);
    $search_value = $params['search']['value'] ?? '';
    
    $columns = ['user_id', 'username', 'requestor_name', 'email', 'role', 'department', 'category', 'created_at'];
    $order_column_index = intval($params['order'][0]['column'] ?? 0);
    $order_dir = strtoupper($params['order'][0]['dir'] ?? 'ASC');
    
    if ($order_column_index >= count($columns)) {
        $order_column_index = 0;
    }
    
    $order_by = $columns[$order_column_index];
    
    try {
        $db = getDatabaseConnection();
        
        // Build WHERE clause for search
        $where_clause = '';
        $query_params = [];
        if (!empty($search_value)) {
            $where_clause = "WHERE username LIKE ? OR email LIKE ? OR requestor_name LIKE ? OR department LIKE ? OR category LIKE ?";
            $search_param = "%{$search_value}%";
            $query_params = array_fill(0, 5, $search_param);
        }
        
        // Get total and filtered counts
        $count_sql = "SELECT COUNT(*) FROM users";
        $total_records = sqlsrv_fetch_array(sqlsrv_query($db->link, $count_sql))[0];
        
        $filtered_count_sql = "SELECT COUNT(*) FROM users " . $where_clause;
        $filtered_stmt = sqlsrv_query($db->link, $filtered_count_sql, $query_params);
        $total_filtered_records = sqlsrv_fetch_array($filtered_stmt)[0];
        
        // Get data with pagination
        $sql = "SELECT user_id, username, requestor_name, email, role, department, category, created_at FROM users " . 
               $where_clause . " ORDER BY " . $order_by . " " . $order_dir;
        
        if ($length != -1) {
            $sql .= " OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
            $query_params[] = $start;
            $query_params[] = $length;
        }
        
        $result = sqlsrv_query($db->link, $sql, $query_params);
        if ($result === false) {
            throw new Exception('Query execution failed');
        }
        
        $users = [];
        while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
            $users[] = [
                'id' => $row['user_id'],
                'username' => $row['username'],
                'requestor_name' => $row['requestor_name'],
                'email' => $row['email'],
                'role' => $row['role'],
                'department' => $row['department'],
                'category' => $row['category'],
                'created_at' => ($row['created_at'] instanceof DateTime) ? $row['created_at']->format(DATETIME_FORMAT) : $row['created_at']
            ];
        }
        
        return [
            'draw' => $draw,
            'recordsTotal' => $total_records,
            'recordsFiltered' => $total_filtered_records,
            'data' => $users
        ];
        
    } catch (Exception $e) {
        logError("Error in getUsersForDataTable: " . $e->getMessage());
        return ['error' => 'Database error occurred'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Get single user data
 */
function getUserData($userId) {
    $db = null;
    if (empty($userId)) {
        return ['success' => false, 'message' => 'User ID is required.'];
    }
    
    try {
        $db = getDatabaseConnection();
        
        $sql = "SELECT user_id, username, requestor_name, department, id_number, email, role, category, e_signiture FROM users WHERE user_id = ? AND is_active = 1";
        $stmt = sqlsrv_query($db->link, $sql, [intval($userId)]);
        
        if (!$stmt) {
            $errors = sqlsrv_errors();
            logError("Query execution failed in getUserData: " . print_r($errors, true));
            throw new Exception("Query execution failed");
        }
        
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            return [
                'success' => true,
                'data' => [
                    'id' => $row['user_id'],
                    'username' => $row['username'],
                    'requestor_name' => $row['requestor_name'],
                    'department' => $row['department'],
                    'id_number' => $row['id_number'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'category' => $row['category'],
                    'e_signiture' => $row['e_signiture']
                ]
            ];
        } else {
            return ['success' => false, 'message' => 'User not found.'];
        }
        
    } catch (Exception $e) {
        logError("Error in getUserData: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if ($db && $db->link) {
            $db->close();
        }
    }
}

/**
 * Update existing user information
 */
function updateUser($user_id, $username, $requestor_name, $department, $param_email, $param_role, $param_category) {
    $fields = compact('user_id', 'username', 'requestor_name', 'department', 'param_email', 'param_role');
    foreach ($fields as $field => $value) {
        if (empty(trim($value))) {
            return ['success' => false, 'message' => 'All fields are required.'];
        }
    }
    
    try {
        $db = getDatabaseConnection();
        
        // Check for email conflicts
        $email_check_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
        $email_check_stmt = sqlsrv_query($db->link, $email_check_sql, [$param_email, $user_id]);
        
        if ($email_check_stmt && sqlsrv_has_rows($email_check_stmt)) {
            return ['success' => false, 'message' => 'This email is already in use by another user.'];
        }
        
        $sql = "UPDATE users SET username = ?, requestor_name = ?, department = ?, email = ?, role = ?, category = ? WHERE user_id = ?";
        $params = [$username, $requestor_name, $department, $param_email, $param_role, $param_category, $user_id];
        $stmt = sqlsrv_query($db->link, $sql, $params);
        
        if (!$stmt) {
            throw new Exception("Update query failed");
        }
        
        $rows_affected = sqlsrv_rows_affected($stmt);
        if ($rows_affected > 0) {
            logDatabase('UPDATE', 'users', ['user_id' => $user_id], 'success');
            return ['success' => true, 'message' => 'User updated successfully.'];
        } else {
            return ['success' => false, 'message' => 'No changes were made or user not found.'];
        }
        
    } catch (Exception $e) {
        logError("Error updating user: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Change user password
 */
function changePassword($userIdToChange, $newPassword) {
    if (empty($userIdToChange) || empty($newPassword)) {
        return ['success' => false, 'message' => 'User ID and new password are required.'];
    }
    
    $hashed_password = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($hashed_password === false) {
        return ['success' => false, 'message' => 'Failed to create password hash.'];
    }
    
    try {
        $db = getDatabaseConnection();
        
        $sql = "UPDATE users SET password = ?, updated_at = GETDATE() WHERE user_id = ?";
        $stmt = sqlsrv_query($db->link, $sql, [$hashed_password, intval($userIdToChange)]);
        
        if (!$stmt) {
            throw new Exception("Password update failed");
        }
        
        $rows_affected = sqlsrv_rows_affected($stmt);
        if ($rows_affected > 0) {
            logDatabase('UPDATE', 'users', ['user_id' => $userIdToChange, 'action' => 'password_change'], 'success');
            return ['success' => true, 'message' => 'Password updated successfully.'];
        } else {
            return ['success' => false, 'message' => 'User not found or password is the same.'];
        }
        
    } catch (Exception $e) {
        logError("Error changing password: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Delete user from database
 */
function deleteUser($userIdToDelete, $currentUserId) {
    if (empty($userIdToDelete)) {
        return ['success' => false, 'message' => 'User ID is required.'];
    }
    
    if ($currentUserId == $userIdToDelete) {
        return ['success' => false, 'message' => 'You cannot delete your own account.'];
    }
    
    try {
        $db = getDatabaseConnection();
        
        $sql = "DELETE FROM users WHERE user_id = ?";
        $stmt = sqlsrv_query($db->link, $sql, [intval($userIdToDelete)]);
        
        if (!$stmt) {
            throw new Exception("Delete query failed");
        }
        
        $rows_affected = sqlsrv_rows_affected($stmt);
        if ($rows_affected > 0) {
            logDatabase('DELETE', 'users', ['user_id' => $userIdToDelete], 'success');
            return ['success' => true, 'message' => 'User deleted successfully.'];
        } else {
            return ['success' => false, 'message' => 'User not found or already deleted.'];
        }
        
    } catch (Exception $e) {
        logError("Error deleting user: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Verify admin password
 */
function verifyAdminPassword($userId, $password) {
    if (empty($password)) {
        return ['success' => false, 'message' => 'Password is required.'];
    }
    
    try {
        $db = getDatabaseConnection();
        
        $sql = "SELECT password FROM users WHERE user_id = ?";
        $stmt = sqlsrv_query($db->link, $sql, [$userId]);
        
        if (!$stmt) {
            throw new Exception("Password verification query failed");
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        
        if ($row && password_verify($password, $row['password'])) {
            return ['success' => true, 'message' => 'Password verified successfully.'];
        } else {
            return ['success' => false, 'message' => 'Incorrect password.'];
        }
        
    } catch (Exception $e) {
        logError("Error verifying password: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

// ===========================================
// DASHBOARD DATA FUNCTIONS
// ===========================================

/**
 * Get comprehensive dashboard data for admin
 */
function getDashboardData() {
    $data = [
        'total_users' => 0,
        'user_roles' => [],
        'active_users' => 0,
        'recent_users' => [],
        'total_rts' => 0,
        'total_ng' => 0,
        'total_coil_solder' => 0,
        'total_all_forms' => 0,
        'system_pending' => 0,
        'system_ongoing' => 0,
        'system_completed' => 0,
        'system_disapproved' => 0,
        'today_requests' => 0,
        'month_requests' => 0,
        'monthly_trend' => ['labels' => [], 'rts' => [], 'ng' => [], 'coil_solder' => []],
        'admin_name' => 'Admin',
        'today' => date(DISPLAY_DATE_FORMAT),
        'total_locations' => 0,
        'recent_requests' => []
    ];
    
    try {
        $db = getDatabaseConnection();
        
        // Clean up old sessions
        sqlsrv_query($db->link, "DELETE FROM active_sessions WHERE last_activity < DATEADD(minute, -30, GETDATE())");
        
        // Get basic counts using helper function
        $counts = [
            'total_users' => "SELECT COUNT(*) FROM users",
            'active_users' => "SELECT COUNT(DISTINCT user_id) FROM active_sessions WHERE last_activity >= DATEADD(minute, -30, GETDATE())",
            'total_locations' => "SELECT COUNT(*) FROM sap_loc_code"
        ];
        
        foreach ($counts as $key => $sql) {
            $stmt = sqlsrv_query($db->link, $sql);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data[$key] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        // Get user roles distribution
        $roles_stmt = sqlsrv_query($db->link, "SELECT role FROM users");
        $role_counts = [];
        if ($roles_stmt) {
            while ($row = sqlsrv_fetch_array($roles_stmt, SQLSRV_FETCH_ASSOC)) {
                $roles = array_map('trim', explode(',', $row['role']));
                foreach ($roles as $role) {
                    if (!empty($role)) {
                        $role_counts[$role] = ($role_counts[$role] ?? 0) + 1;
                    }
                }
            }
            $data['user_roles'] = $role_counts;
        }
        
        // Check if workflow_status column exists
        $check_column_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'rts_forms' AND COLUMN_NAME = 'workflow_status'";
        $has_workflow_status = sqlsrv_has_rows(sqlsrv_query($db->link, $check_column_sql));
        $status_column = $has_workflow_status ? 'workflow_status' : 'material_status';
        
        // Get RTS statistics
        $rts_stats_sql = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN $status_column = 'Pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN $status_column = 'In-Progress' THEN 1 ELSE 0 END) AS ongoing,
                SUM(CASE WHEN $status_column = 'Completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN $status_column IN ('Disapproved', 'Canceled') THEN 1 ELSE 0 END) AS disapproved,
                SUM(CASE WHEN CAST(created_at AS DATE) = CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN MONTH(created_at) = MONTH(GETDATE()) AND YEAR(created_at) = YEAR(GETDATE()) THEN 1 ELSE 0 END) AS this_month
            FROM rts_forms";
        
        $rts_stmt = sqlsrv_query($db->link, $rts_stats_sql);
        if ($rts_stmt && $row = sqlsrv_fetch_array($rts_stmt, SQLSRV_FETCH_ASSOC)) {
            $data = array_merge($data, [
                'total_rts' => $row['total'] ?? 0,
                'system_pending' => $row['pending'] ?? 0,
                'system_ongoing' => $row['ongoing'] ?? 0,
                'system_completed' => $row['completed'] ?? 0,
                'system_disapproved' => $row['disapproved'] ?? 0,
                'today_requests' => $row['today'] ?? 0,
                'month_requests' => $row['this_month'] ?? 0
            ]);
        }
        
        $data['total_all_forms'] = $data['total_rts'] + $data['total_ng'] + $data['total_coil_solder'];
        
        // Get recent users
        $recent_users_sql = "SELECT TOP 5 username, requestor_name, role, created_at FROM users ORDER BY created_at DESC";
        $recent_users_stmt = sqlsrv_query($db->link, $recent_users_sql);
        if ($recent_users_stmt) {
            while ($row = sqlsrv_fetch_array($recent_users_stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['created_at'] instanceof DateTime) {
                    $row['created_at'] = $row['created_at']->format('Y-m-d H:i');
                }
                $data['recent_users'][] = $row;
            }
        }
        
        // Get recent requests
        $recent_requests_sql = "
            SELECT TOP 5 
                rf.control_no,
                'RTS Form' as form_type,
                $status_column as workflow_status,
                rf.material_status,
                u.requestor_name as submitted_by
            FROM rts_forms rf
            LEFT JOIN users u ON rf.requestor_id = u.user_id
            ORDER BY rf.created_at DESC";
        
        $recent_requests_stmt = sqlsrv_query($db->link, $recent_requests_sql);
        if ($recent_requests_stmt) {
            while ($row = sqlsrv_fetch_array($recent_requests_stmt, SQLSRV_FETCH_ASSOC)) {
                $data['recent_requests'][] = $row;
            }
        }
        
        // Get monthly trend data (last 6 months)
        for ($i = 5; $i >= 0; $i--) {
            $month = date('F', strtotime("-$i months"));
            $month_num = date('n', strtotime("-$i months"));
            $year = date('Y', strtotime("-$i months"));
            
            $data['monthly_trend']['labels'][] = $month;
            
            $month_rts_sql = "SELECT COUNT(*) FROM rts_forms WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?";
            $month_stmt = sqlsrv_query($db->link, $month_rts_sql, [$month_num, $year]);
            if ($month_stmt && sqlsrv_fetch($month_stmt)) {
                $data['monthly_trend']['rts'][] = sqlsrv_get_field($month_stmt, 0) ?: 0;
            } else {
                $data['monthly_trend']['rts'][] = 0;
            }
            
            $data['monthly_trend']['ng'][] = 0;
            $data['monthly_trend']['coil_solder'][] = 0;
        }
        
        // Get admin name
        if (isset($_SESSION['user_id'])) {
            $admin_stmt = sqlsrv_query($db->link, "SELECT requestor_name FROM users WHERE user_id = ?", [$_SESSION['user_id']]);
            if ($admin_stmt && sqlsrv_fetch($admin_stmt)) {
                $data['admin_name'] = sqlsrv_get_field($admin_stmt, 0) ?: 'Admin';
            }
        }
        
    } catch (Exception $e) {
        logError("Error in getDashboardData: " . $e->getMessage());
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
    
    return $data;
}

/**
 * Get dashboard data for specific user
 */
function getUserDashboardData($user_id) {
    $data = [
        'total_rts' => 0,
        'total_ng' => 0,
        'total_coil_solder' => 0,
        'total_all_forms' => 0,
        'pending_all' => 0,
        'ongoing_all' => 0,
        'completed_all' => 0,
        'disapproved_all' => 0,
        'pending_rts' => 0,
        'pending_ng' => 0,
        'pending_coil_solder' => 0,
        'ongoing_rts' => 0,
        'ongoing_ng' => 0,
        'ongoing_coil_solder' => 0,
        'completed_rts' => 0,
        'completed_ng' => 0,
        'completed_coil_solder' => 0,
        'disapproved_rts' => 0,
        'disapproved_ng' => 0,
        'disapproved_coil_solder' => 0,
        'today_requests' => 0,
        'month_requests' => 0,
        'today' => date(DISPLAY_DATE_FORMAT),
        'requestor_name' => 'User'
    ];
    
    try {
        $db = getDatabaseConnection();
        
        $today = date('Y-m-d');
        $current_month = date('m');
        $current_year = date('Y');
        
        // Check for workflow_status column
        $check_column_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'rts_forms' AND COLUMN_NAME = 'workflow_status'";
        $has_workflow_status = sqlsrv_has_rows(sqlsrv_query($db->link, $check_column_sql));
        $status_column = $has_workflow_status ? 'workflow_status' : 'material_status';
        
        // Get RTS form statistics
        $rts_sql = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN $status_column = 'Pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN $status_column = 'In-Progress' THEN 1 ELSE 0 END) AS ongoing,
                SUM(CASE WHEN $status_column = 'Completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN $status_column IN ('Disapproved', 'Canceled') THEN 1 ELSE 0 END) AS disapproved,
                SUM(CASE WHEN CAST(created_at AS DATE) = ? THEN 1 ELSE 0 END) AS today_count,
                SUM(CASE WHEN MONTH(created_at) = ? AND YEAR(created_at) = ? THEN 1 ELSE 0 END) AS month_count
            FROM rts_forms 
            WHERE requestor_id = ?";
        
        $rts_stmt = sqlsrv_query($db->link, $rts_sql, [$today, $current_month, $current_year, $user_id]);
        if ($rts_stmt && $row = sqlsrv_fetch_array($rts_stmt, SQLSRV_FETCH_ASSOC)) {
            $data['total_rts'] = $row['total'] ?? 0;
            $data['pending_rts'] = $row['pending'] ?? 0;
            $data['ongoing_rts'] = $row['ongoing'] ?? 0;
            $data['completed_rts'] = $row['completed'] ?? 0;
            $data['disapproved_rts'] = $row['disapproved'] ?? 0;
            $data['today_requests'] += $row['today_count'] ?? 0;
            $data['month_requests'] += $row['month_count'] ?? 0;
        }
        
        // Calculate totals across all forms
        $data['total_all_forms'] = $data['total_rts'] + $data['total_ng'] + $data['total_coil_solder'];
        $data['pending_all'] = $data['pending_rts'] + $data['pending_ng'] + $data['pending_coil_solder'];
        $data['ongoing_all'] = $data['ongoing_rts'] + $data['ongoing_ng'] + $data['ongoing_coil_solder'];
        $data['completed_all'] = $data['completed_rts'] + $data['completed_ng'] + $data['completed_coil_solder'];
        $data['disapproved_all'] = $data['disapproved_rts'] + $data['disapproved_ng'] + $data['disapproved_coil_solder'];
        
        // Get requestor name
        $user_stmt = sqlsrv_query($db->link, "SELECT requestor_name FROM users WHERE user_id = ?", [$user_id]);
        if ($user_stmt && sqlsrv_fetch($user_stmt)) {
            $data['requestor_name'] = sqlsrv_get_field($user_stmt, 0) ?: 'User';
        }
        
    } catch (Exception $e) {
        logError("Error in getUserDashboardData: " . $e->getMessage());
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
    
    return $data;
}

/**
 * Get dashboard data for approvers (checker, approver, noter)
 */
function getApproverDashboardData($user_id, $user_roles) {
    $data = [
        'pending_checking' => 0,
        'pending_approval' => 0,
        'pending_noting' => 0,
        'total_processed' => 0,
        'total_disapproved' => 0,
        'monthly_checked' => 0,
        'monthly_approved' => 0,
        'monthly_noted' => 0,
        'today_actions' => 0,
        'avg_processing_time' => '0h',
        'form_types' => ['rts' => 0, 'ng' => 0, 'coil_solder' => 0],
        'monthly_trend' => ['labels' => [], 'checked' => [], 'approved' => [], 'noted' => []],
        'today' => date(DISPLAY_DATE_FORMAT),
        'username' => $_SESSION['username'] ?? 'User',
        'requestor_name' => 'User'
    ];
    
    try {
        $db = getDatabaseConnection();
        
        $current_month = date('m');
        $current_year = date('Y');
        $today = date('Y-m-d');
        
        // Check if workflow_status column exists
        $check_column_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'rts_forms' AND COLUMN_NAME = 'workflow_status'";
        $check_result = sqlsrv_query($db->link, $check_column_sql);
        $has_workflow_status = $check_result && sqlsrv_has_rows($check_result);
        
        // Role-specific pending counts
        if (in_array('checker', $user_roles)) {
            $pending_check_sql = "SELECT COUNT(*) FROM rts_forms WHERE checked_status = 'Pending'";
            if ($has_workflow_status) {
                $pending_check_sql .= " AND workflow_status NOT IN ('Completed', 'Disapproved', 'Canceled')";
            }
            
            $stmt = sqlsrv_query($db->link, $pending_check_sql);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data['pending_checking'] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        if (in_array('approver', $user_roles)) {
            $pending_approve_sql = "SELECT COUNT(*) FROM rts_forms WHERE approved_status = 'Pending' AND checked_status = 'Approved'";
            if ($has_workflow_status) {
                $pending_approve_sql .= " AND workflow_status NOT IN ('Completed', 'Disapproved', 'Canceled')";
            }
            
            $stmt = sqlsrv_query($db->link, $pending_approve_sql);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data['pending_approval'] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        if (in_array('noter', $user_roles)) {
            $pending_note_sql = "SELECT COUNT(*) FROM rts_forms WHERE noted_status = 'Pending' AND approved_status = 'Approved'";
            if ($has_workflow_status) {
                $pending_note_sql .= " AND workflow_status NOT IN ('Completed', 'Disapproved', 'Canceled')";
            }
            
            $stmt = sqlsrv_query($db->link, $pending_note_sql);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data['pending_noting'] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        // Monthly activity counts for current user
        if (in_array('checker', $user_roles)) {
            $monthly_checked_sql = "SELECT COUNT(*) FROM rts_forms WHERE checked_by_id = ? AND MONTH(checked_at) = ? AND YEAR(checked_at) = ?";
            $stmt = sqlsrv_query($db->link, $monthly_checked_sql, [$user_id, $current_month, $current_year]);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data['monthly_checked'] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        if (in_array('approver', $user_roles)) {
            $monthly_approved_sql = "SELECT COUNT(*) FROM rts_forms WHERE approved_by_id = ? AND MONTH(approved_at) = ? AND YEAR(approved_at) = ?";
            $stmt = sqlsrv_query($db->link, $monthly_approved_sql, [$user_id, $current_month, $current_year]);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data['monthly_approved'] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        if (in_array('noter', $user_roles)) {
            $monthly_noted_sql = "SELECT COUNT(*) FROM rts_forms WHERE noted_by_id = ? AND MONTH(noted_at) = ? AND YEAR(noted_at) = ?";
            $stmt = sqlsrv_query($db->link, $monthly_noted_sql, [$user_id, $current_month, $current_year]);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data['monthly_noted'] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        // Get total processed this month by this user
        $total_processed_sql = "
            SELECT COUNT(DISTINCT id) FROM rts_forms 
            WHERE (
                (checked_by_id = ? AND MONTH(checked_at) = ? AND YEAR(checked_at) = ?) OR
                (approved_by_id = ? AND MONTH(approved_at) = ? AND YEAR(approved_at) = ?) OR
                (noted_by_id = ? AND MONTH(noted_at) = ? AND YEAR(noted_at) = ?)
            )";
        
        $processed_stmt = sqlsrv_query($db->link, $total_processed_sql, [
            $user_id, $current_month, $current_year,
            $user_id, $current_month, $current_year,
            $user_id, $current_month, $current_year
        ]);
        if ($processed_stmt && sqlsrv_fetch($processed_stmt)) {
            $data['total_processed'] = sqlsrv_get_field($processed_stmt, 0) ?: 0;
        }
        
        // Get total disapproved by this user this month
        $disapproved_sql = "SELECT COUNT(*) FROM rts_forms WHERE ";
        $disapproved_conditions = [];
        $disapproved_params = [];
        
        if (in_array('checker', $user_roles)) {
            $disapproved_conditions[] = "(checked_by_id = ? AND checked_status = 'Disapproved' AND MONTH(checked_at) = ? AND YEAR(checked_at) = ?)";
            $disapproved_params = array_merge($disapproved_params, [$user_id, $current_month, $current_year]);
        }
        if (in_array('approver', $user_roles)) {
            $disapproved_conditions[] = "(approved_by_id = ? AND approved_status = 'Disapproved' AND MONTH(approved_at) = ? AND YEAR(approved_at) = ?)";
            $disapproved_params = array_merge($disapproved_params, [$user_id, $current_month, $current_year]);
        }
        if (in_array('noter', $user_roles)) {
            $disapproved_conditions[] = "(noted_by_id = ? AND noted_status = 'Disapproved' AND MONTH(noted_at) = ? AND YEAR(noted_at) = ?)";
            $disapproved_params = array_merge($disapproved_params, [$user_id, $current_month, $current_year]);
        }
        
        if (!empty($disapproved_conditions)) {
            $disapproved_sql .= implode(' OR ', $disapproved_conditions);
            $stmt = sqlsrv_query($db->link, $disapproved_sql, $disapproved_params);
            if ($stmt && sqlsrv_fetch($stmt)) {
                $data['total_disapproved'] = sqlsrv_get_field($stmt, 0) ?: 0;
            }
        }
        
        // Get today's actions
        $today_sql = "
            SELECT COUNT(DISTINCT id) FROM rts_forms 
            WHERE (
                (checked_by_id = ? AND CAST(checked_at AS DATE) = ?) OR
                (approved_by_id = ? AND CAST(approved_at AS DATE) = ?) OR
                (noted_by_id = ? AND CAST(noted_at AS DATE) = ?)
            )";
        
        $today_stmt = sqlsrv_query($db->link, $today_sql, [$user_id, $today, $user_id, $today, $user_id, $today]);
        if ($today_stmt && sqlsrv_fetch($today_stmt)) {
            $data['today_actions'] = sqlsrv_get_field($today_stmt, 0) ?: 0;
        }
        
        // Get average processing time for forms this user has processed
        $avg_time_sql = "
            SELECT AVG(DATEDIFF(hour, created_at, 
                CASE 
                    WHEN noted_by_id = ? AND noted_at IS NOT NULL THEN noted_at
                    WHEN approved_by_id = ? AND approved_at IS NOT NULL THEN approved_at
                    WHEN checked_by_id = ? AND checked_at IS NOT NULL THEN checked_at
                    ELSE GETDATE()
                END
            )) FROM rts_forms
            WHERE (checked_by_id = ? OR approved_by_id = ? OR noted_by_id = ?)
            AND (checked_at IS NOT NULL OR approved_at IS NOT NULL OR noted_at IS NOT NULL)";
        
        $avg_stmt = sqlsrv_query($db->link, $avg_time_sql, [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
        if ($avg_stmt && sqlsrv_fetch($avg_stmt)) {
            $avg_hours = sqlsrv_get_field($avg_stmt, 0) ?: 0;
            $data['avg_processing_time'] = round($avg_hours, 1) . 'h';
        }
        
        // Get form types distribution (all RTS forms for now since that's what we have)
        $form_types_stmt = sqlsrv_query($db->link, "SELECT COUNT(*) FROM rts_forms WHERE (checked_by_id = ? OR approved_by_id = ? OR noted_by_id = ?)", [$user_id, $user_id, $user_id]);
        if ($form_types_stmt && sqlsrv_fetch($form_types_stmt)) {
            $data['form_types']['rts'] = sqlsrv_get_field($form_types_stmt, 0) ?: 0;
        }
        
        // Get monthly trend data (last 6 months)
        for ($i = 5; $i >= 0; $i--) {
            $month = date('F', strtotime("-$i months"));
            $month_num = date('n', strtotime("-$i months"));
            $year = date('Y', strtotime("-$i months"));
            
            $data['monthly_trend']['labels'][] = $month;
            
            // Initialize arrays if they don't exist
            if (!isset($data['monthly_trend']['checked'])) $data['monthly_trend']['checked'] = [];
            if (!isset($data['monthly_trend']['approved'])) $data['monthly_trend']['approved'] = [];
            if (!isset($data['monthly_trend']['noted'])) $data['monthly_trend']['noted'] = [];
            
            foreach (['checker' => 'checked', 'approver' => 'approved', 'noter' => 'noted'] as $role => $trend_key) {
                if (in_array($role, $user_roles)) {
                    $column = $role . '_by_id';
                    $date_column = $role == 'checker' ? 'checked_at' : ($role == 'approver' ? 'approved_at' : 'noted_at');
                    
                    $trend_sql = "SELECT COUNT(*) FROM rts_forms WHERE $column = ? AND MONTH($date_column) = ? AND YEAR($date_column) = ?";
                    $trend_stmt = sqlsrv_query($db->link, $trend_sql, [$user_id, $month_num, $year]);
                    
                    if ($trend_stmt && sqlsrv_fetch($trend_stmt)) {
                        $data['monthly_trend'][$trend_key][] = sqlsrv_get_field($trend_stmt, 0) ?: 0;
                    } else {
                        $data['monthly_trend'][$trend_key][] = 0;
                    }
                } else {
                    $data['monthly_trend'][$trend_key][] = 0;
                }
            }
        }
        
        // Get requestor name
        $user_stmt = sqlsrv_query($db->link, "SELECT requestor_name FROM users WHERE user_id = ?", [$user_id]);
        if ($user_stmt && sqlsrv_fetch($user_stmt)) {
            $data['requestor_name'] = sqlsrv_get_field($user_stmt, 0) ?: 'User';
        }
        
    } catch (Exception $e) {
        logError("Error in getApproverDashboardData: " . $e->getMessage());
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
    
    return $data;
}

// ===========================================
// RTS FORM FUNCTIONS
// ===========================================

/**
 * Generate new RTS Control Number
 */
function generateRTSNumber() {
    try {
        $db = getDatabaseConnection();
        
        // Start transaction to prevent race conditions
        sqlsrv_begin_transaction($db->link);
        
        $currentYear = date('Y');
        $currentMonth = date('m');
        
        // Check if sequence record exists for current month/year
        $select_sql = "SELECT current_sequence FROM dbo.rts_sequence WITH (UPDLOCK) WHERE current_year = ? AND current_month = ?";
        $select_stmt = sqlsrv_query($db->link, $select_sql, [$currentYear, $currentMonth]);
        
        if (!$select_stmt) {
            throw new Exception("Failed to query sequence record");
        }
        
        $row = sqlsrv_fetch_array($select_stmt, SQLSRV_FETCH_ASSOC);
        
        if (!$row) {
            // Insert new sequence record
            $sequenceNumber = 1;
            $insert_sql = "INSERT INTO dbo.rts_sequence (current_year, current_month, current_sequence) VALUES (?, ?, ?)";
            $insert_stmt = sqlsrv_query($db->link, $insert_sql, [$currentYear, $currentMonth, $sequenceNumber]);
            
            if (!$insert_stmt) {
                throw new Exception("Failed to insert new sequence record");
            }
        } else {
            // Update existing sequence
            $sequenceNumber = (int)$row['current_sequence'] + 1;
            $update_sql = "UPDATE dbo.rts_sequence SET current_sequence = ? WHERE current_year = ? AND current_month = ?";
            $update_stmt = sqlsrv_query($db->link, $update_sql, [$sequenceNumber, $currentYear, $currentMonth]);
            
            if (!$update_stmt) {
                throw new Exception("Failed to update sequence");
            }
        }
        
        sqlsrv_commit($db->link);
        
        // Format and return control number
        $formattedSeq = str_pad($sequenceNumber, 4, '0', STR_PAD_LEFT);
        return "RTS-{$currentYear}-{$currentMonth}-{$formattedSeq}";
        
    } catch (Exception $e) {
        if (isset($db->link)) {
            sqlsrv_rollback($db->link);
        }
        logError("Error in generateRTSNumber: " . $e->getMessage());
        return false;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Get RTS details with materials and permission checking
 */
function getRTSDetails($rts_id, $requesting_user_id = null, $can_view_all = false) {
    if (!$rts_id) {
        return ['rts' => null, 'items' => [], 'error_message' => "RTS request ID not specified."];
    }
    
    try {
        $db = getDatabaseConnection();
        
        // Fetch RTS form details with permission check
        $sql = "SELECT
                    rts_forms.*,
                    submitted_user.requestor_name AS submitted_by,
                    submitted_user.user_id AS submitted_by_id,
                    checked_user.requestor_name AS checked_by_name,
                    approved_user.requestor_name AS approved_by_name,
                    noted_user.requestor_name AS noted_by_name,
                    sap_codes.LocationDescription,
                    sap_codes.Department AS sap_department
                FROM rts_forms
                LEFT JOIN users AS submitted_user ON rts_forms.requestor_id = submitted_user.user_id
                LEFT JOIN users AS checked_user ON rts_forms.checked_by_id = checked_user.user_id
                LEFT JOIN users AS approved_user ON rts_forms.approved_by_id = approved_user.user_id
                LEFT JOIN users AS noted_user ON rts_forms.noted_by_id = noted_user.user_id
                LEFT JOIN sap_loc_code AS sap_codes ON rts_forms.sap_loc_code = sap_codes.LocationCode
                WHERE rts_forms.id = ?";
        
        // Add permission check if not admin/approver
        if (!$can_view_all && $requesting_user_id) {
            $sql .= " AND rts_forms.requestor_id = ?";
            $params = [$rts_id, $requesting_user_id];
        } else {
            $params = [$rts_id];
        }
        
        $stmt = sqlsrv_query($db->link, $sql, $params);
        
        if (!$stmt) {
            throw new Exception("Database query for form failed");
        }
        
        if (!sqlsrv_has_rows($stmt)) {
            return ['rts' => null, 'items' => [], 'error_message' => "RTS request not found or access denied."];
        }
        
        $rts = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        
        // Convert signature images to Base64
        $signature_fields = ['prepared_by_signature_image', 'checked_by_signature_image', 'approved_by_signature_image', 'noted_by_signature_image'];
        foreach ($signature_fields as $field) {
            $base64_field = str_replace('_image', '_base64', $field);
            $rts[$base64_field] = convertSignatureToBase64($rts[$field] ?? null);
        }
        
        // Fetch materials
        $items_sql = "SELECT * FROM rts_materials WHERE rts_form_id = ? ORDER BY id";
        $items_stmt = sqlsrv_query($db->link, $items_sql, [$rts_id]);
        
        if (!$items_stmt) {
            throw new Exception("Database query for items failed");
        }
        
        $items = [];
        while ($row = sqlsrv_fetch_array($items_stmt, SQLSRV_FETCH_ASSOC)) {
            $items[] = $row;
        }
        
        return ['rts' => $rts, 'items' => $items, 'error_message' => null];
        
    } catch (Exception $e) {
        logError("Error in getRTSDetails: " . $e->getMessage());
        return ['rts' => null, 'items' => [], 'error_message' => "An error occurred: " . $e->getMessage()];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Convert binary signature data to Base64 string
 */
function convertSignatureToBase64($signature_data) {
    if (empty($signature_data)) {
        return null;
    }
    
    $image_data = false;
    if (is_resource($signature_data)) {
        $image_data = stream_get_contents($signature_data);
        if (is_resource($signature_data)) {
            fclose($signature_data);
        }
    } elseif (is_string($signature_data)) {
        $image_data = $signature_data;
    }
    
    return $image_data ? 'data:image/png;base64,' . base64_encode($image_data) : null;
}

// ===========================================
// SAP LOCATION FUNCTIONS
// ===========================================

/**
 * Get SAP Location Codes for DataTables
 */
function getSapLocCodes($params) {
    $draw = intval($params['draw'] ?? 0);
    $start = intval($params['start'] ?? 0);
    $length = intval($params['length'] ?? 10);
    $search_value = $params['search']['value'] ?? '';
    
    $columns = ['LocationCode', 'LocationCode', 'LocationDescription', 'Department'];
    $order_column_index = intval($params['order'][0]['column'] ?? 0);
    $order_dir = strtoupper($params['order'][0]['dir'] ?? 'ASC');
    
    // Default to ordering by LocationCode if counter column is selected
    $order_by = ($order_column_index === 0) ? 'LocationCode' : $columns[$order_column_index];
    
    try {
        $db = getDatabaseConnection();
        
        // Build WHERE clause for search
        $where_clause = '';
        $query_params = [];
        if (!empty($search_value)) {
            $where_clause = "WHERE LocationCode LIKE ? OR LocationDescription LIKE ? OR Department LIKE ?";
            $search_param = "%{$search_value}%";
            $query_params = [$search_param, $search_param, $search_param];
        }
        
        // Get total and filtered counts
        $total_records = sqlsrv_fetch_array(sqlsrv_query($db->link, "SELECT COUNT(*) FROM sap_loc_code"))[0] ?? 0;
        
        $filtered_sql = "SELECT COUNT(*) FROM sap_loc_code " . $where_clause;
        $filtered_stmt = sqlsrv_query($db->link, $filtered_sql, $query_params);
        $total_filtered_records = sqlsrv_fetch_array($filtered_stmt)[0] ?? 0;
        
        // Get data with pagination
        $sql = "SELECT LocationCode, LocationDescription, Department FROM sap_loc_code " . 
               $where_clause . " ORDER BY " . $order_by . " " . $order_dir;
        
        if ($length != -1) {
            $sql .= " OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
            $query_params[] = $start;
            $query_params[] = $length;
        }
        
        $stmt = sqlsrv_query($db->link, $sql, $query_params);
        if (!$stmt) {
            throw new Exception('Query execution failed');
        }
        
        $data = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $data[] = [
                'id' => $row['LocationCode'],
                'location_code' => $row['LocationCode'],
                'location_name' => $row['LocationDescription'],
                'department' => $row['Department']
            ];
        }
        
        return [
            'draw' => $draw,
            'recordsTotal' => $total_records,
            'recordsFiltered' => $total_filtered_records,
            'data' => $data,
            'status' => 'success'
        ];
        
    } catch (Exception $e) {
        logError("Error in getSapLocCodes: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Database error occurred'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Enhanced SAP Location parsing function
 */
function parseSAPLocationData($rts_data) {
    // Try new structure first (separate columns)
    if (!empty($rts_data['sap_from_location']) || !empty($rts_data['sap_to_location'])) {
        return [
            'from' => [
                'code' => $rts_data['sap_from_location'] ?? 'N/A',
                'description' => $rts_data['sap_from_description'] ?? '',
                'department' => $rts_data['sap_from_department'] ?? ''
            ],
            'to' => [
                'code' => $rts_data['sap_to_location'] ?? 'N/A',
                'description' => $rts_data['sap_to_description'] ?? '',
                'department' => $rts_data['sap_to_department'] ?? ''
            ]
        ];
    }
    
    // Fallback to old format parsing
    $sap_loc_code = $rts_data['sap_loc_code'] ?? '';
    
    if (empty($sap_loc_code)) {
        return [
            'from' => ['code' => 'N/A', 'description' => '', 'department' => ''],
            'to' => ['code' => 'N/A', 'description' => '', 'department' => '']
        ];
    }
    
    // Check for arrow separator
    if (strpos($sap_loc_code, '→') !== false) {
        $parts = explode('→', $sap_loc_code);
        return [
            'from' => ['code' => trim($parts[0] ?? 'N/A'), 'description' => '', 'department' => ''],
            'to' => ['code' => trim($parts[1] ?? 'N/A'), 'description' => '', 'department' => '']
        ];
    }
    
    // Single location format
    return [
        'from' => ['code' => trim($sap_loc_code), 'description' => '', 'department' => ''],
        'to' => ['code' => 'N/A', 'description' => '', 'department' => '']
    ];
}

/**
 * Add new SAP location code
 */
function addSapLocCode($location_code, $location_name, $department) {
    if (empty($location_code) || empty($location_name) || empty($department)) {
        return ['success' => false, 'message' => 'Location Code, Location Name, and Department are all required.'];
    }
    
    try {
        $db = getDatabaseConnection();
        
        // Check for existing location code
        $check_stmt = sqlsrv_query($db->link, "SELECT LocationCode FROM sap_loc_code WHERE LocationCode = ?", [$location_code]);
        if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
            return ['success' => false, 'message' => 'This Location Code already exists.'];
        }
        
        // Insert new record
        $insert_sql = "INSERT INTO sap_loc_code (LocationCode, LocationDescription, Department) VALUES (?, ?, ?)";
        $stmt = sqlsrv_query($db->link, $insert_sql, [$location_code, $location_name, $department]);
        
        if (!$stmt) {
            throw new Exception("Insert query failed");
        }
        
        if (sqlsrv_rows_affected($stmt) > 0) {
            logDatabase('INSERT', 'sap_loc_code', ['LocationCode' => $location_code], 'success');
            return ['success' => true, 'message' => 'Location Code added successfully.'];
        } else {
            return ['success' => false, 'message' => 'Failed to add the location code.'];
        }
        
    } catch (Exception $e) {
        logError("Error adding SAP location: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Update existing SAP location code
 */
function updateSapLocCode($original_location_code, $new_location_code, $location_name, $department) {
    if (empty($original_location_code) || empty($new_location_code) || empty($location_name) || empty($department)) {
        return ['success' => false, 'message' => 'All fields are required.'];
    }
    
    try {
        $db = getDatabaseConnection();
        
        $sql = "UPDATE sap_loc_code SET LocationCode = ?, LocationDescription = ?, Department = ? WHERE LocationCode = ?";
        $stmt = sqlsrv_query($db->link, $sql, [$new_location_code, $location_name, $department, $original_location_code]);
        
        if (!$stmt) {
            throw new Exception("Update query failed");
        }
        
        if (sqlsrv_rows_affected($stmt) > 0) {
            logDatabase('UPDATE', 'sap_loc_code', ['original' => $original_location_code, 'new' => $new_location_code], 'success');
            return ['success' => true, 'message' => 'Location Code updated successfully.'];
        } else {
            return ['success' => false, 'message' => 'Failed to update or no changes made.'];
        }
        
    } catch (Exception $e) {
        logError("Error updating SAP location: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Delete SAP location code
 */
function deleteSapLocCode($location_code) {
    try {
        $db = getDatabaseConnection();
        
        $stmt = sqlsrv_query($db->link, "DELETE FROM sap_loc_code WHERE LocationCode = ?", [$location_code]);
        
        if (!$stmt) {
            throw new Exception("Delete query failed");
        }
        
        if (sqlsrv_rows_affected($stmt) > 0) {
            logDatabase('DELETE', 'sap_loc_code', ['LocationCode' => $location_code], 'success');
            return ['success' => true, 'message' => 'Location Code deleted successfully.'];
        } else {
            return ['success' => false, 'message' => 'Failed to delete or no record found.'];
        }
        
    } catch (Exception $e) {
        logError("Error deleting SAP location: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

// ===========================================
// SCRAP RTS FORM FUNCTIONS
// ===========================================

/**
 * Initialize scrap RTS page data
 */
function initializeScrapRTSPageData($userId) {
    if (!isset($userId)) {
        return [
            'success' => false,
            'message' => 'User not authenticated.',
            'redirect' => BASE_URL . '/login.php'
        ];
    }
    
    $userData = getUserData($userId);
    
    if (!$userData['success']) {
        return [
            'success' => false,
            'message' => 'User data not found.',
            'userData' => [
                'requestor_name' => 'User Not Found',
                'department' => '',
                'role' => '',
                'e_signiture' => null
            ]
        ];
    }
    
    $data = $userData['data'];
    
    // Check scrap permission
    if (!checkScrapPermission($data['department'], $data['role'])) {
        return [
            'success' => false,
            'message' => 'User does not have permission to access this page.',
            'redirect' => BASE_URL . '/pages/scrap/scrap_dashboard.php'
        ];
    }
    
    // Process user signature
    $signature_base64 = '';
    if (!empty($data['e_signiture'])) {
        $user_signature_data = is_resource($data['e_signiture']) ?
            stream_get_contents($data['e_signiture']) : $data['e_signiture'];
        $signature_base64 = 'data:image/jpeg;base64,' . base64_encode($user_signature_data);
    }
    
    // Get form options
    $formOptions = getScrapRTSFormOptions();
    
    return array_merge([
        'success' => true,
        'userData' => [
            'requestor_name' => sanitizeInput($data['requestor_name'] ?? $data['username']),
            'department' => $data['department'],
            'role' => $data['role'],
            'signature_base64' => $signature_base64,
        ],
        'control_no' => 'Will be generated upon submission',
    ], $formOptions);
}

/**
 * Get form options for scrap RTS forms
 */
function getScrapRTSFormOptions() {
    $departments = [];
    $sap_locations = [];
    $errorMessage = null;
    
    try {
        $db = getDatabaseConnection();
        
        // Fetch departments
        $dept_stmt = sqlsrv_query($db->link, "SELECT DISTINCT department FROM users");
        if ($dept_stmt) {
            while ($row = sqlsrv_fetch_array($dept_stmt, SQLSRV_FETCH_ASSOC)) {
                $departments[] = $row['department'];
            }
        }
        
        // Fetch SAP locations
        $loc_stmt = sqlsrv_query($db->link, "SELECT LocationCode, LocationDescription, Department FROM sap_loc_code ORDER BY LocationCode");
        if ($loc_stmt) {
            while ($row = sqlsrv_fetch_array($loc_stmt, SQLSRV_FETCH_ASSOC)) {
                $sap_locations[] = $row;
            }
        }
        
    } catch (Exception $e) {
        logError("Error in getScrapRTSFormOptions: " . $e->getMessage());
        $errorMessage = $e->getMessage();
        $departments = ['Error fetching departments'];
        $sap_locations = [['LocationCode' => 'Error', 'LocationDescription' => 'Error', 'Department' => 'Error']];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
    
    return [
        'departments' => $departments,
        'sap_locations' => $sap_locations,
        'errorMessage' => $errorMessage
    ];
}

/**
 * Get RTS details for resubmission
 */
function getRTSDetailsForResubmission($rts_id, $user_id) {
    try {
        $db = getDatabaseConnection();
        
        // Verify form is disapproved and belongs to user
        $check_sql = "SELECT * FROM rts_forms WHERE id = ? AND requestor_id = ? AND workflow_status = 'Disapproved'";
        $check_stmt = sqlsrv_query($db->link, $check_sql, [$rts_id, $user_id]);
        
        if (!$check_stmt || !sqlsrv_has_rows($check_stmt)) {
            return ['success' => false, 'message' => 'Invalid request or permission denied.'];
        }
        
        // Get full RTS details
        $rtsData = getRTSDetails($rts_id);
        if ($rtsData['error_message']) {
            return ['success' => false, 'message' => $rtsData['error_message']];
        }
        
        // Get user signature
        $userData = getUserData($user_id);
        $signature_base64 = '';
        if ($userData['success'] && !empty($userData['data']['e_signiture'])) {
            $user_signature_data = is_resource($userData['data']['e_signiture']) ?
                stream_get_contents($userData['data']['e_signiture']) : $userData['data']['e_signiture'];
            $signature_base64 = 'data:image/jpeg;base64,' . base64_encode($user_signature_data);
        }
        
        // Get form options and original material status
        $formOptions = getScrapRTSFormOptions();
        $original_material_statuses = getRTSOriginalMaterialStatus($rts_id);
        
        return [
            'success' => true,
            'rts' => $rtsData['rts'],
            'items' => $rtsData['items'],
            'signature_base64' => $signature_base64,
            'departments' => $formOptions['departments'],
            'sap_locations' => $formOptions['sap_locations'],
            'original_material_statuses' => $original_material_statuses
        ];
        
    } catch (Exception $e) {
        logError("Error in getRTSDetailsForResubmission: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Get original material status from RTS form
 */
function getRTSOriginalMaterialStatus($rts_id) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "SELECT original_material_status_selection, material_status, judgement, details FROM rts_forms WHERE id = ?";
        $stmt = sqlsrv_query($db->link, $sql, [$rts_id]);
        
        if ($stmt && sqlsrv_fetch($stmt)) {
            $original_selection = sqlsrv_get_field($stmt, 0);
            
            if ($original_selection) {
                return explode(', ', $original_selection);
            }
            
            $material_status = sqlsrv_get_field($stmt, 1);
            if ($material_status) {
                return explode(', ', $material_status);
            }
            
            // Fallback to inference from judgement
            $judgement = sqlsrv_get_field($stmt, 2);
            if (strpos($judgement, 'Transfer to Good') !== false) {
                return ['Good'];
            } elseif (strpos($judgement, 'Scrap/Disposal') !== false || strpos($judgement, 'Hold') !== false) {
                $details = sqlsrv_get_field($stmt, 3);
                if (strpos($details, 'Material Defect') !== false) return ['Material Defect'];
                if (strpos($details, 'Human Error') !== false) return ['Human Error'];
                if (strpos($details, 'EOL') !== false) return ['EOL'];
                return ['NG/Others'];
            }
        }
        
        return [];
        
    } catch (Exception $e) {
        logError("Error getting original material status: " . $e->getMessage());
        return [];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Verify user has permission to resubmit a form
 */
function verifyResubmissionPermission($form_id, $user_id) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "SELECT requestor_id, workflow_status FROM rts_forms WHERE id = ?";
        $stmt = sqlsrv_query($db->link, $sql, [$form_id]);
        
        if (!$stmt || !sqlsrv_has_rows($stmt)) {
            return ['success' => false, 'message' => 'Form not found.'];
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        
        if ($row['requestor_id'] != $user_id) {
            return ['success' => false, 'message' => 'You do not have permission to resubmit this form.'];
        }
        
        if ($row['workflow_status'] != 'Disapproved') {
            return ['success' => false, 'message' => 'Only disapproved forms can be resubmitted.'];
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        logError("Error verifying resubmission permission: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred.'];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}
/**
 * Get disapproval history for an RTS request 
 */
function getDisapprovalHistory($db, $rts_id) {
    // Input validation
    if (!$db || !is_numeric($rts_id)) {
        logError("Invalid parameters in getDisapprovalHistory: rts_id=$rts_id");
        return [];
    }

    $history = [];
    
    // Correct SQL Server query for rts_disapproval_history table
    $query = "SELECT 
                dh.id,
                dh.disapproval_reason,
                dh.disapproved_by_role,
                u.requestor_name AS disapproved_by_name,
                dh.disapproved_at,
                dh.resubmission_count
              FROM rts_disapproval_history dh
              LEFT JOIN users u ON dh.disapproved_by_user_id = u.user_id
              WHERE dh.rts_form_id = ?
              ORDER BY dh.disapproved_at ASC";
              
    $params = [$rts_id];
    $stmt = sqlsrv_query($db->link, $query, $params);
    
    if (!$stmt) {
        $errors = sqlsrv_errors();
        logError("SQL query failed in getDisapprovalHistory: " . print_r($errors, true), [
            'query' => $query,
            'params' => $params,
            'rts_id' => $rts_id
        ]);
        return [];
    }
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert disapproved_at to DateTime object if it's valid
        if (!empty($row['disapproved_at']) && $row['disapproved_at'] instanceof DateTime) {
            $row['disapproved_at'] = $row['disapproved_at'];
        } else if (!empty($row['disapproved_at'])) {
            $row['disapproved_at'] = new DateTime($row['disapproved_at']);
        }
        
        $history[] = $row;
    }
    
    // Log the number of history records found
    logInfo("Disapproval history retrieved", [
        'rts_id' => $rts_id,
        'count' => count($history),
        'history' => $history
    ]);
    
    return $history;
}

// ===========================================
// SESSION MANAGEMENT FUNCTIONS
// ===========================================

/**
 * Clean up expired sessions
 */
function cleanupExpiredSessions() {
    if (!class_exists('Connection')) {
        return;
    }
    
    try {
        $db = getDatabaseConnection();
        
        $cleanup_sql = "DELETE FROM active_sessions WHERE last_activity < DATEADD(hour, -8, GETDATE())";
        $result = sqlsrv_query($db->link, $cleanup_sql);
        
        if ($result) {
            $rows_affected = sqlsrv_rows_affected($result);
            if ($rows_affected > 0) {
                logSecurityEvent('session_cleanup', null, "Cleaned up $rows_affected expired sessions");
            }
        }
        
    } catch (Exception $e) {
        logError("Session cleanup error: " . $e->getMessage());
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Update user's last activity
 */
function updateUserActivity($user_id = null) {
    if (!class_exists('Connection')) {
        return;
    }
    
    if (!$user_id && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    if (!$user_id) {
        return;
    }
    
    try {
        $db = getDatabaseConnection();
        $session_id = session_id();
        
        $update_sql = "
            IF EXISTS (SELECT 1 FROM active_sessions WHERE session_id = ?)
                UPDATE active_sessions SET last_activity = GETDATE() WHERE session_id = ?
            ELSE
                INSERT INTO active_sessions (session_id, user_id, last_activity) VALUES (?, ?, GETDATE())
        ";
        
        sqlsrv_query($db->link, $update_sql, [$session_id, $session_id, $session_id, $user_id]);
        
    } catch (Exception $e) {
        logError("Update user activity error: " . $e->getMessage());
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Initialize session management
 */
function initializeSessionManagement() {
    // Auto-cleanup with 1% probability per request
    if (rand(1, 100) === 1) {
        cleanupExpiredSessions();
    }
    
    // Update user activity if session is active
    if (isset($_SESSION['user_id'])) {
        updateUserActivity();
    }
}

// ===========================================
// NOTIFICATION SYSTEM FUNCTIONS
// ===========================================

/**
 * Create a new notification with requestor information
 */
function createNotification($user_id, $type, $title, $message, $related_id = null, $related_type = null, $control_no = null, $url = null, $requestor_id = null) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "INSERT INTO notifications (user_id, type, title, message, related_id, related_type, control_no, url, requestor_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
        
        $params = [$user_id, $type, $title, $message, $related_id, $related_type, $control_no, $url, $requestor_id];
        
        $stmt = sqlsrv_query($db->link, $sql, $params);
        
        if (!$stmt) {
            throw new Exception("Failed to create notification");
        }
        
        logInfo("Notification created", [
            'user_id' => $user_id,
            'type' => $type,
            'control_no' => $control_no,
            'requestor_id' => $requestor_id
        ]);
        
        return true;
        
    } catch (Exception $e) {
        logError("Error creating notification: " . $e->getMessage());
        return false;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Create notifications for users with specific roles - Enhanced with requestor info
 */
function createNotificationForRoles($roles, $type, $title, $message, $related_id = null, $related_type = null, $control_no = null, $url = null, $requestor_id = null) {
    try {
        $db = getDatabaseConnection();
        
        // Build role condition
        $role_conditions = [];
        $params = [];
        
        foreach ($roles as $role) {
            $role_conditions[] = "role LIKE ?";
            $params[] = "%{$role}%";
        }
        
        $role_where = implode(' OR ', $role_conditions);
        
        $sql = "SELECT DISTINCT user_id FROM users WHERE ({$role_where}) AND user_id IS NOT NULL";
        
        $stmt = sqlsrv_query($db->link, $sql, $params);
        
        if (!$stmt) {
            throw new Exception("Failed to get users for roles");
        }
        
        $notification_count = 0;
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (createNotification($row['user_id'], $type, $title, $message, $related_id, $related_type, $control_no, $url, $requestor_id)) {
                $notification_count++;
            }
        }
        
        logInfo("Bulk notifications created", [
            'roles' => $roles,
            'count' => $notification_count,
            'control_no' => $control_no,
            'requestor_id' => $requestor_id
        ]);
        
        return $notification_count;
        
    } catch (Exception $e) {
        logError("Error creating bulk notifications: " . $e->getMessage());
        return 0;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Get notifications for a user - Enhanced with requestor information
 */
function getUserNotifications($user_id, $unread_only = false, $limit = 20) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "SELECT TOP (?) 
                    n.id, n.type, n.title, n.message, n.related_id, n.related_type, 
                    n.control_no, n.is_read, n.created_at, n.url, n.requestor_id,
                    u.requestor_name, u.department as requestor_department,
                    rf.created_at as form_submitted_at
                FROM notifications n
                LEFT JOIN users u ON n.requestor_id = u.user_id
                LEFT JOIN rts_forms rf ON n.related_id = rf.id AND n.related_type = 'rts_form'
                WHERE n.user_id = ?";
        
        $params = [$limit, $user_id];
        
        if ($unread_only) {
            $sql .= " AND n.is_read = 0";
        }
        
        $sql .= " ORDER BY n.created_at DESC";
        
        $stmt = sqlsrv_query($db->link, $sql, $params);
        
        if (!$stmt) {
            throw new Exception("Failed to get user notifications");
        }
        
        $notifications = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Use form submission time if available, otherwise use notification creation time
            $display_time = $row['form_submitted_at'] ?: $row['created_at'];
            
            if ($display_time instanceof DateTime) {
                $row['created_at_formatted'] = $display_time->format('M j, g:i A');
                $row['created_at_iso'] = $display_time->format('c');
            } else {
                $row['created_at_formatted'] = date('M j, g:i A', strtotime($display_time));
                $row['created_at_iso'] = date('c', strtotime($display_time));
            }
            
            // Enhanced message with requestor name
            if (!empty($row['requestor_name'])) {
                $row['display_requestor'] = $row['requestor_name'];
            } else {
                $row['display_requestor'] = 'Unknown User';
            }
            
            $notifications[] = $row;
        }
        
        return $notifications;
        
    } catch (Exception $e) {
        logError("Error getting user notifications: " . $e->getMessage());
        return [];
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount($user_id) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0";
        $stmt = sqlsrv_query($db->link, $sql, [$user_id]);
        
        if ($stmt && sqlsrv_fetch($stmt)) {
            return sqlsrv_get_field($stmt, 0) ?: 0;
        }
        
        return 0;
        
    } catch (Exception $e) {
        logError("Error getting unread notification count: " . $e->getMessage());
        return 0;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($notification_id, $user_id) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "UPDATE notifications SET is_read = 1, read_at = GETDATE() 
                WHERE id = ? AND user_id = ?";
        
        $stmt = sqlsrv_query($db->link, $sql, [$notification_id, $user_id]);
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        logError("Error marking notification as read: " . $e->getMessage());
        return false;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsAsRead($user_id) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "UPDATE notifications SET is_read = 1, read_at = GETDATE() 
                WHERE user_id = ? AND is_read = 0";
        
        $stmt = sqlsrv_query($db->link, $sql, [$user_id]);
        
        if ($stmt) {
            $rows_affected = sqlsrv_rows_affected($stmt);
            logInfo("Marked all notifications as read", [
                'user_id' => $user_id,
                'count' => $rows_affected
            ]);
            return $rows_affected;
        }
        
        return 0;
        
    } catch (Exception $e) {
        logError("Error marking all notifications as read: " . $e->getMessage());
        return 0;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

/**
 * Delete old notifications (cleanup)
 */
function cleanupOldNotifications($days_old = 30) {
    try {
        $db = getDatabaseConnection();
        
        $sql = "DELETE FROM notifications WHERE created_at < DATEADD(day, ?, GETDATE())";
        $stmt = sqlsrv_query($db->link, $sql, [-$days_old]);
        
        if ($stmt) {
            $rows_affected = sqlsrv_rows_affected($stmt);
            logInfo("Cleaned up old notifications", ['count' => $rows_affected]);
            return $rows_affected;
        }
        
        return 0;
        
    } catch (Exception $e) {
        logError("Error cleaning up notifications: " . $e->getMessage());
        return 0;
    } finally {
        if (isset($db)) {
            $db->close();
        }
    }
}

// ===========================================
// AUTHENTICATION HELPER FUNCTIONS
// ===========================================

/**
 * Check if current page requires authentication
 */
function requiresAuthentication($page_path = null) {
    if (!$page_path) {
        $page_path = $_SERVER['REQUEST_URI'];
    }
    
    $public_pages = ['/login.php', '/maintenance.php', '/error-pages/', '/assets/', '/PHPMailer/'];
    
    foreach ($public_pages as $public_page) {
        if (strpos($page_path, $public_page) !== false) {
            return false;
        }
    }
    
    return true;
}

// Auto-initialize session management on include
if (isset($_SERVER['REQUEST_METHOD'])) {
    initializeSessionManagement();
}

?>