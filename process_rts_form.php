<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/FOPHScrapMD/config.php';
require_once APP_ROOT . '/includes/functions.php';

header('Content-Type: application/json');

try {
    // Initialize application
    initializeApp();
    
    // Check authentication
    if (!getCurrentUserId()) {
        echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
        exit();
    }

    $user_id = getCurrentUserId();
    
    // Get user data and validate permissions
    $userData = getUserData($user_id);
    if (!$userData['success']) {
        echo json_encode(['status' => 'error', 'message' => 'User data not found.']);
        exit();
    }

    $user_department = $userData['data']['department'];
    $user_role = $userData['data']['role'];

    // Check scrap form permissions
    if (!checkScrapPermission($user_department, $user_role)) {
        logWarning("Unauthorized RTS form submission attempt", [
            'user_id' => $user_id,
            'department' => $user_department,
            'role' => $user_role
        ]);
        echo json_encode(['status' => 'error', 'message' => 'Insufficient permissions.']);
        exit();
    }

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
        exit();
    }

    // Generate control number
    $control_no = generateRTSNumber();
    if ($control_no === false) {
        throw new Exception("Failed to generate RTS control number.");
    }

    $db = getDatabaseConnection();

    // Start transaction
    if (!sqlsrv_begin_transaction($db->link)) {
        throw new Exception("Failed to start DB transaction: " . print_r(sqlsrv_errors(), true));
    }

    // Prepare and sanitize RTS form data
    $requestor_name = sanitizeInput($_SESSION['username'] ?? $userData['data']['username']);
    $requestor_department = sanitizeInput($user_department);

    // Process material type array
    $material_type_arr = $_POST['material_type'] ?? [];
    $material_type = !empty($material_type_arr) ? implode(', ', array_map('sanitizeInput', $material_type_arr)) : null;
    
    // Process material status array
    $material_status_arr = $_POST['material_status'] ?? [];
    $material_status = !empty($material_status_arr) ? implode(', ', array_map('sanitizeInput', $material_status_arr)) : null;

    // Process judgement array
    $judgement_arr = $_POST['judgement'] ?? [];
    $judgement = !empty($judgement_arr) ? implode(', ', array_map('sanitizeInput', $judgement_arr)) : null;

    // Sanitize other form fields
    $details = sanitizeInput($_POST['details'] ?? '');
    $remark = sanitizeInput($_POST['remark'] ?? '');
    $return_date = !empty($_POST['return_date']) ? date('Y-m-d', strtotime($_POST['return_date'])) : null;
    $department = sanitizeInput($_POST['department'] ?? '');
    $model = sanitizeInput($_POST['model'] ?? '');
    
    // Process SAP location data
    $sap_location_from_code = sanitizeInput($_POST['sap_location']['from'] ?? '');
    $sap_location_to_code = sanitizeInput($_POST['sap_location']['to'] ?? '');
    
    // Fetch complete SAP location details for both From and To
    $sap_from_details = getSapLocationDetails($sap_location_from_code, $db->link);
    $sap_to_details = getSapLocationDetails($sap_location_to_code, $db->link);
    
    $sap_loc_code = $sap_location_from_code . ' → ' . $sap_location_to_code;
    
    $prepared_by = sanitizeInput($_SESSION['username'] ?? $userData['data']['username']);
    $prepared_by_signature_image = $userData['data']['e_signiture'] ?? null;
    
    // Initialize all approval fields with default values
    $checked_by = null;
    $approved_by = null;
    $noted_by = null;
    $checked_by_id = null;
    $approved_by_id = null;
    $noted_by_id = null;
    $checked_at = null;
    $approved_at = null;
    $noted_at = null;

    $checked_status = 'Pending';
    $approved_status = 'Pending';
    $noted_status = 'Pending';
    $workflow_status = 'Pending';

    // Prepare SQL insertion with proper parameter binding
    $sql_insert_form = "
        INSERT INTO rts_forms
            (control_no, requestor_id, requestor_name, requestor_department, material_type, material_status, judgement, details, remark, return_date, department, model, sap_loc_code, sap_from_location, sap_to_location, sap_from_description, sap_to_description, sap_from_department, sap_to_department, prepared_by, checked_by, approved_by, noted_by, checked_status, approved_status, noted_status, checked_by_id, approved_by_id, noted_by_id, checked_at, approved_at, noted_at, prepared_by_signature_image, workflow_status, created_at)
        OUTPUT INSERTED.id
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $params_insert_form = [
        [$control_no, SQLSRV_PARAM_IN],
        [$user_id, SQLSRV_PARAM_IN],
        [$requestor_name, SQLSRV_PARAM_IN],
        [$requestor_department, SQLSRV_PARAM_IN],
        [$material_type, SQLSRV_PARAM_IN],
        [$material_status, SQLSRV_PARAM_IN],
        [$judgement, SQLSRV_PARAM_IN],
        [$details, SQLSRV_PARAM_IN],
        [$remark, SQLSRV_PARAM_IN],
        [$return_date, SQLSRV_PARAM_IN],
        [$department, SQLSRV_PARAM_IN],
        [$model, SQLSRV_PARAM_IN],
        [$sap_loc_code, SQLSRV_PARAM_IN],
        [$sap_location_from_code, SQLSRV_PARAM_IN],
        [$sap_location_to_code, SQLSRV_PARAM_IN],
        [$sap_from_details['description'], SQLSRV_PARAM_IN],
        [$sap_to_details['description'], SQLSRV_PARAM_IN],
        [$sap_from_details['department'], SQLSRV_PARAM_IN],
        [$sap_to_details['department'], SQLSRV_PARAM_IN],
        [$prepared_by, SQLSRV_PARAM_IN],
        [$checked_by, SQLSRV_PARAM_IN],
        [$approved_by, SQLSRV_PARAM_IN],
        [$noted_by, SQLSRV_PARAM_IN],
        [$checked_status, SQLSRV_PARAM_IN],
        [$approved_status, SQLSRV_PARAM_IN],
        [$noted_status, SQLSRV_PARAM_IN],
        [$checked_by_id, SQLSRV_PARAM_IN],
        [$approved_by_id, SQLSRV_PARAM_IN],
        [$noted_by_id, SQLSRV_PARAM_IN],
        [$checked_at, SQLSRV_PARAM_IN],
        [$approved_at, SQLSRV_PARAM_IN],
        [$noted_at, SQLSRV_PARAM_IN],
        [$prepared_by_signature_image, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_VARBINARY('max')],
        [$workflow_status, SQLSRV_PARAM_IN],
        [date(DATETIME_FORMAT), SQLSRV_PARAM_IN]
    ];
    
    $stmt = sqlsrv_prepare($db->link, $sql_insert_form, $params_insert_form);

    if ($stmt === false) {
         throw new Exception("SQL Prepare Error (rts_forms): " . print_r(sqlsrv_errors(), true));
    }

    if (!sqlsrv_execute($stmt)) {
        throw new Exception("SQL Insert Error (rts_forms): " . print_r(sqlsrv_errors(), true));
    }
    
    $inserted = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$inserted || !isset($inserted['id'])) {
        throw new Exception("Failed to retrieve inserted RTS form ID.");
    }
    $rts_form_id = $inserted['id'];

    // Insert material details
    $material_details = $_POST['material_details'] ?? [];
    insertMaterialDetails($db->link, $rts_form_id, $material_details);
    
    // Commit transaction
    if (!sqlsrv_commit($db->link)) {
        throw new Exception("Failed to commit database transaction: " . print_r(sqlsrv_errors(), true));
    }
    
    // Log successful submission
    logDatabase('INSERT', 'rts_forms', [
        'control_no' => $control_no,
        'user_id' => $user_id
    ], 'success');
    
    logInfo("RTS form submitted successfully", [
        'control_no' => $control_no,
        'user_id' => $user_id,
        'department' => $user_department
    ]);

    try {
    $notification_title = "New RTS Form Pending Review";
    $notification_message = "RTS Form {$control_no} has been submitted by {$requestor_name} from {$requestor_department} department and requires your review.";
    $notification_url = BASE_URL . "/pages/approver/rts_details.php?id=" . $rts_form_id;
    
    $notifications_created = createNotificationForRoles(
        ['checker'], 
        'rts_pending', 
        $notification_title, 
        $notification_message, 
        $rts_form_id, 
        'rts_form', 
        $control_no, 
        $notification_url
    );
    
    logInfo("In-app notifications created for RTS submission", [
        'control_no' => $control_no,
        'notifications_created' => $notifications_created
    ]);
    
} catch (Exception $notification_error) {
    logError("Error creating in-app notifications", [
        'control_no' => $control_no,
        'error' => $notification_error->getMessage()
    ]);
}
    
    // Send email notification to checker role after successful submission
    $checker_emails = getCheckerEmails($db->link);
    
    if (!empty($checker_emails)) {
        try {
            // Include email functionality if available
            if (file_exists(APP_ROOT . '/includes/send_email.php')) {
                include_once APP_ROOT . '/includes/send_email.php';
                
                if (function_exists('sendEmailRTS')) {
                    $email_result = sendEmailRTS($checker_emails, $control_no);
                    
                    logEmail('rts_submission', $control_no, $checker_emails, 
                        $email_result ? 'success' : 'failed',
                        $email_result ? 'Notification sent to checkers' : 'Failed to send notification'
                    );
                } else {
                    logWarning("sendEmailRTS function not available");
                }
            } else {
                logWarning("Email functionality not available");
            }
        } catch (Exception $email_error) {
            logError("Exception sending RTS notification", [
                'control_no' => $control_no,
                'error' => $email_error->getMessage()
            ]);
        }
    } else {
        logWarning("No checker users found for email notification", ['control_no' => $control_no]);
    }

    echo json_encode([
        'status' => 'success',
        'control_no' => $control_no,
        'message' => 'RTS form submitted successfully.'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($db) && $db->link) {
        sqlsrv_rollback($db->link);
    }
    
    logError("RTS form submission error: " . $e->getMessage(), [
        'user_id' => getCurrentUserId(),
        'stack_trace' => $e->getTraceAsString()
    ]);
    
    echo json_encode([
        'status' => 'error',
        'message' => DISPLAY_ERRORS ? $e->getMessage() : 'An error occurred while processing your request.'
    ]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}

/**
 * Get SAP location details from database
 */
function getSapLocationDetails($locationCode, $conn) {
    if (empty($locationCode)) {
        return ['description' => '', 'department' => ''];
    }
    
    $sql = "SELECT LocationDescription, Department FROM sap_loc_code WHERE LocationCode = ?";
    $stmt = sqlsrv_query($conn, $sql, [sanitizeInput($locationCode)]);
    
    if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return [
            'description' => sanitizeInput($row['LocationDescription'] ?? ''),
            'department' => sanitizeInput($row['Department'] ?? '')
        ];
    }
    
    return ['description' => '', 'department' => ''];
}

/**
 * Get checker email addresses
 */
function getCheckerEmails($conn) {
    $checker_emails = [];
    
    $sql_checkers = "
        SELECT DISTINCT TOP 20 email 
        FROM users 
        WHERE (role = 'checker' OR role LIKE '%checker%') 
        AND email IS NOT NULL 
        AND email != ''
        AND email LIKE '%@%.%'
        ORDER BY email
    ";
    
    $stmt_checkers = sqlsrv_query($conn, $sql_checkers);
    if ($stmt_checkers === false) {
        logError("Failed to fetch checker emails: " . print_r(sqlsrv_errors(), true));
        return [];
    }
    
    while ($row = sqlsrv_fetch_array($stmt_checkers, SQLSRV_FETCH_ASSOC)) {
        $email = sanitizeEmail($row['email'] ?? '');
        if (!empty($email) && validateEmail($email)) {
            $checker_emails[] = $email;
        }
    }
    
    logInfo("Found checker emails for notification", [
        'count' => count($checker_emails),
        'emails' => implode(', ', $checker_emails)
    ]);
    
    return $checker_emails;
}

/**
 * Insert material details into database
 */
function insertMaterialDetails($conn, $rts_form_id, $material_details) {
    if (empty($material_details) || !is_array($material_details)) {
        return;
    }

    $ref_no_arr = $material_details['ref_no'] ?? [];
    $sap_doc_arr = $material_details['sap_doc'] ?? [];
    $invoice_no_arr = $material_details['invoice_no'] ?? [];
    $supplier_arr = $material_details['supplier'] ?? [];
    $part_name_arr = $material_details['part_name'] ?? [];
    $part_number_arr = $material_details['part_number'] ?? [];
    $description_arr = $material_details['description'] ?? [];
    $qty_returned_arr = $material_details['qty_returned'] ?? [];
    $qty_received_arr = $material_details['qty_received'] ?? [];
    $amount_arr = $material_details['amount'] ?? [];
    $due_date_arr = $material_details['due_date'] ?? [];

    $count_materials = count($ref_no_arr);
    if ($count_materials === 0) return;

    $sql_insert_material = "
        INSERT INTO rts_materials
            (rts_form_id, ref_no, sap_doc, invoice_no, supplier, part_name, part_number, description, qty_returned, qty_received, amount, due_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    for ($i = 0; $i < $count_materials; $i++) {
        // Check if all fields are empty for this row
        $all_empty = empty($ref_no_arr[$i]) && empty($sap_doc_arr[$i])
            && empty($invoice_no_arr[$i]) && empty($supplier_arr[$i]) && empty($part_name_arr[$i]) 
            && empty($part_number_arr[$i]) && empty($description_arr[$i]) && empty($qty_returned_arr[$i]) 
            && empty($qty_received_arr[$i]) && empty($amount_arr[$i]) && empty($due_date_arr[$i]);

        if ($all_empty) {
            continue;
        }

        // Sanitize and prepare data
        $due_date = !empty($due_date_arr[$i]) ? date('Y-m-d', strtotime($due_date_arr[$i])) : null;
                $qty_returned = isset($qty_returned_arr[$i]) && $qty_returned_arr[$i] !== '' ? (int)$qty_returned_arr[$i] : null;
        $qty_received = isset($qty_received_arr[$i]) && $qty_received_arr[$i] !== '' ? (int)$qty_received_arr[$i] : null;
        $amount = isset($amount_arr[$i]) && $amount_arr[$i] !== '' ? (float)$amount_arr[$i] : null;

        $params_material = [
            $rts_form_id,
            sanitizeInput($ref_no_arr[$i] ?? ''),
            sanitizeInput($sap_doc_arr[$i] ?? ''),
            sanitizeInput($invoice_no_arr[$i] ?? ''),
            sanitizeInput($supplier_arr[$i] ?? ''),
            sanitizeInput($part_name_arr[$i] ?? ''),
            sanitizeInput($part_number_arr[$i] ?? ''),
            sanitizeInput($description_arr[$i] ?? ''),
            $qty_returned,
            $qty_received,
            $amount,
            $due_date
        ];

        $stmt_material = sqlsrv_query($conn, $sql_insert_material, $params_material);
        if ($stmt_material === false) {
            throw new Exception("SQL Insert Error (rts_materials) at index $i: " . print_r(sqlsrv_errors(), true));
        }
    }
    
    logInfo("Material details inserted", [
        'rts_form_id' => $rts_form_id,
        'material_count' => $count_materials
    ]);
}
?>