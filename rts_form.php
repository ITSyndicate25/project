<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/FOPHScrapMD/config.php';
require_once APP_ROOT . '/includes/functions.php';

initializeApp();

if (!getCurrentUserId()) {
    safeRedirect('/login.php');
}

$user_id = getCurrentUserId();
$user_roles = getCurrentUserRoles();

$userData = getUserData($user_id);
if (!$userData['success']) {
    logError("Failed to get user data for RTS form access", ['user_id' => $user_id]);
    redirectTo500('Unable to verify user permissions');
}

$user_department = $userData['data']['department'];
$user_role = $userData['data']['role'];

if (!checkScrapPermission($user_department, $user_role)) {
    logSecurityEvent('unauthorized_rts_access', $user_id, "Department: $user_department, Role: $user_role");
    redirectTo403('You do not have permission to access the RTS form');
}

redirectToMaintenance();

$pageData = initializeScrapRTSPageData($user_id);

if (!$pageData['success']) {
    if (isset($pageData['redirect'])) {
        safeRedirect($pageData['redirect']);
    }
    $showErrorAlert = true;
    $errorMessage = $pageData['message'];
} else {
    $user_name = $pageData['userData']['requestor_name'];
    $user_department = $pageData['userData']['department'];
    $user_role = $pageData['userData']['role'];
    $signature_base64 = $pageData['userData']['signature_base64'];
    $departments = $pageData['departments'];
    $sap_locations = $pageData['sap_locations'];
    $control_no = $pageData['control_no'];

    $showSuccessAlert = isset($_GET['success']) && $_GET['success'] === '1' && !empty($_GET['control_no']);
    $showErrorAlert = isset($_GET['error']);
    $controlNo = $showSuccessAlert ? sanitizeInput($_GET['control_no']) : '';
    $errorMessage = $showErrorAlert ? sanitizeInput($_GET['message'] ?? 'An error occurred during submission.') : '';
}

logInfo('RTS form page accessed', [
    'user_id' => $user_id,
    'department' => $user_department,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 0;
        font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f8f9fa;
    }

    .wrapper {
        min-height: 100vh;
    }

    .main-panel {
        width: 100%;
    }

    .content {
        padding: 20px;
    }

    .container-fluid {
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Collapsible Header Styling */
    .collapsible-header {
        cursor: pointer;
        position: relative;
        transition: all 0.3s ease;
        user-select: none;
    }

    .collapsible-header:hover {
        background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
        transform: translateX(5px);
    }

    .collapse-icon {
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        transition: transform 0.3s ease;
        font-size: 16px;
        color: #264c7eff;
    }

    .collapsible-header[aria-expanded="true"] .collapse-icon {
        transform: translateY(-50%) rotate(180deg);
    }

    .collapsible-header[aria-expanded="false"] .collapse-icon {
        transform: translateY(-50%) rotate(0deg);
    }

    /* Inline Table Responsive Container */
    .table-responsive-custom-inline {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        background: white;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        overflow-x: auto;
        max-height: 500px;
        overflow-y: auto;
        margin-top: 15px;
    }

    /* Enhanced Inline Material Table Styling */
    .material-table-enhanced-inline {
        width: 100%;
        border-collapse: collapse;
        font-size: 11pt;
        background: white;
        margin: 0;
        min-width: 1600px;
    }

    .material-table-enhanced-inline th {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 15px 12px;
        text-align: center;
        font-weight: 600;
        color: #495057;
        border: 1px solid #dee2e6;
        text-transform: uppercase;
        letter-spacing: 0.2px;
        font-size: 10pt;
        line-height: 1.3;
        position: sticky;
        top: 0;
        z-index: 10;
        white-space: nowrap;
    }

    .material-table-enhanced-inline td {
        padding: 12px;
        border: 1px solid #dee2e6;
        text-align: center;
        vertical-align: middle;
        background: white;
    }

    /* Enhanced Input Field Styling for Inline Table */
    .material-table-enhanced-inline input {
        border: 2px solid #e9ecef;
        border-radius: 6px;
        padding: 8px 10px;
        font-size: 10pt;
        width: 100%;
        background: white;
        transition: all 0.2s ease-in-out;
        min-height: 38px;
    }

    .material-table-enhanced-inline input:focus {
        border-color: #264c7eff;
        outline: none;
        box-shadow: 0 0 0 2px rgba(38, 76, 126, 0.15);
        background: #fff;
        transform: scale(1.02);
    }

    .material-table-enhanced-inline input::placeholder {
        color: #6c757d;
        font-style: italic;
        font-size: 9pt;
    }

    /* Enhanced Row Styling for Inline Table */
    .material-table-enhanced-inline tbody tr {
        transition: all 0.2s ease;
    }

    .material-table-enhanced-inline tbody tr:nth-child(even) {
        background: #f8f9fa;
    }

    .material-table-enhanced-inline tbody tr:hover {
        background: #e3f2fd !important;
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .material-table-enhanced-inline tbody tr.has-data {
        background: linear-gradient(135deg, #f0f8f0 0%, #e8f5e8 100%) !important;
        border-left: 4px solid #28a745;
    }

    .material-table-enhanced-inline tbody tr.has-data:hover {
        background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%) !important;
    }

    /* Enhanced Button Styling for Inline Table */
    .material-table-enhanced-inline .btn-danger {
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 9pt;
        transition: all 0.2s ease;
    }

    .material-table-enhanced-inline .btn-danger:hover {
        transform: scale(1.1);
        box-shadow: 0 2px 6px rgba(220, 53, 69, 0.3);
    }

    /* Row Number Styling for Inline Table */
    .material-table-enhanced-inline td:first-child {
        background: #f8f9fa;
        font-weight: 600;
        color: #495057;
        border-right: 2px solid #dee2e6;
        position: sticky;
        left: 0;
        z-index: 5;
    }

    /* Scrollbar Styling for Inline Table */
    .table-responsive-custom-inline::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .table-responsive-custom-inline::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }

    .table-responsive-custom-inline::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
        transition: background 0.2s ease;
    }

    .table-responsive-custom-inline::-webkit-scrollbar-thumb:hover {
        background: #264c7eff;
    }

    /* Enhanced Materials Header */
    .materials-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding: 15px 20px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }

    /* Collapse Animation */
    .collapse {
        transition: all 0.35s ease;
    }

    .collapsing {
        transition: height 0.35s ease;
    }

    /* Empty State Styling */
    .empty-table-message {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
        background: #f8f9fa;
        border-radius: 6px;
        margin: 15px 0;
        border: 2px dashed #dee2e6;
    }

    .empty-table-message i {
        font-size: 48px;
        color: #dee2e6;
        margin-bottom: 15px;
    }

    /* Responsive Design for Inline Table */
    @media (max-width: 768px) {
        .materials-header {
            flex-direction: column;
            gap: 15px;
            align-items: stretch;
        }

        .materials-header .d-flex {
            justify-content: center;
        }

        .table-responsive-custom-inline {
            max-height: 400px;
        }

        .material-table-enhanced-inline {
            min-width: 1000px;
            font-size: 9pt;
        }

        .material-table-enhanced-inline th,
        .material-table-enhanced-inline td {
            padding: 8px 4px;
        }

        .material-table-enhanced-inline input {
            padding: 6px 8px;
            font-size: 9pt;
            min-height: 32px;
        }
    }

    @media (max-width: 480px) {
        .material-table-enhanced-inline {
            min-width: 800px;
            font-size: 8pt;
        }

        .material-table-enhanced-inline th {
            padding: 6px 3px;
            font-size: 7pt;
        }

        .material-table-enhanced-inline td {
            padding: 4px 3px;
        }

        .material-table-enhanced-inline input {
            padding: 4px 6px;
            font-size: 8pt;
            min-height: 28px;
        }
    }

    /* Card Styling */
    .card {
        background: white;
        border-radius: 8px;
        border: none;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    /* Enhanced Document Header - Responsive Design */
    .card-header {
        background: white;
        color: #1a1a1a;
        padding: 25px 30px;
        border-radius: 8px 8px 0 0;
        border: none;
        border-bottom: 3px solid #264c7eff;
        position: relative;
    }

    .document-header-content {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 25px;
        flex-wrap: wrap;
    }

    .company-section {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        flex: 1;
    }

    .company-logo {
        max-height: 45px;
        width: auto;
        flex-shrink: 0;
    }

    .company-details {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .company-name {
        font-size: 12pt;
        font-weight: 700;
        color: #1a1a1a;
        line-height: 1.2;
    }

    .company-subtitle {
        font-size: 10pt;
        color: #666;
        font-weight: 400;
        line-height: 1.3;
        max-width: 350px;
    }

    /* Control Number Box - Smaller and Responsive */
    .control-number-box {
        border: 2px solid #264c7eff;
        padding: 8px 12px;
        background: #fff;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        flex-shrink: 0;
        min-width: 250px;
        /* Increased length */
        max-width: 300px;
        /* Increased length */
    }

    .control-number-box .label {
        font-size: 8pt;
        color: #666;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 3px;
        display: block;
        letter-spacing: 0.3px;
        text-align: center;
    }

    #control_no {
        font-family: 'Courier New', monospace;
        font-weight: 700;
        font-size: 11pt;
        text-align: center;
        background: transparent;
        border: none;
        color: #264c7eff;
        width: 100%;
        padding: 2px 0;
        outline: none;
    }

    /* Document Title Section */
    .document-title-section {
        text-align: center;
        margin: 15px 0 5px 0;
    }

    .card-title {
        font-size: 22pt;
        font-weight: 700;
        color: #1a1a1a;
        margin: 0;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        line-height: 1.2;
    }

    .card-header .subtitle {
        color: #666;
        font-size: 11pt;
        font-weight: 400;
        margin-top: 5px;
        line-height: 1.3;
    }

    /* Card Body */
    .card-body {
        padding: 30px;
        background: white;
    }

    /* Form Sections */
    .form-section {
        margin-bottom: 30px;
        page-break-inside: avoid;
    }

    .section-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-left: 4px solid #264c7eff;
        padding: 12px 20px;
        margin-bottom: 20px;
        font-weight: 600;
        font-size: 14pt;
        color: #2c3e50;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border-radius: 0 4px 4px 0;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .section-header i {
        margin-right: 8px;
        color: #264c7eff;
    }

    /* Form Controls */
    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        font-size: 11pt;
        font-weight: 600;
        color: #495057;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 8px;
        display: block;
        border-bottom: 1px solid #dee2e6;
        padding-bottom: 3px;
    }

    .form-control {
        border: 1px solid #ced4da;
        border-radius: 4px;
        padding: 10px 12px;
        font-size: 11pt;
        color: #495057;
        background-color: white;
        transition: all 0.15s ease-in-out;
        font-family: 'Inter', sans-serif;
        width: 100%;
    }

    .form-control:focus {
        border-color: #264c7eff;
        outline: none;
        box-shadow: 0 0 0 2px rgba(38, 76, 126, 0.25);
        background-color: white;
    }

    .form-control::placeholder {
        color: #6c757d;
        font-style: italic;
    }

    .form-control:disabled {
        background-color: #f8f9fa;
        border-color: #e9ecef;
        color: #6c757d;
        cursor: not-allowed;
    }

    /* Select Controls */
    select.form-control {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 5'%3e%3cpath fill='%23343a40' d='m2 0-2 2h4zm0 5 2-2h-4z'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 0.75rem center;
        background-size: 16px 12px;
        padding-right: 2.25rem;
    }

    /* Custom Checkbox Styling */
    .custom-control {
        position: relative;
        display: block;
        min-height: 1.5rem;
        padding-left: 2rem;
        margin-bottom: 8px;
    }

    .custom-control-input {
        position: absolute;
        z-index: -1;
        opacity: 0;
    }

    .custom-control-label {
        position: relative;
        margin-bottom: 0;
        cursor: pointer;
        font-size: 11pt;
        color: #495057;
        line-height: 1.5;
        font-weight: 400;
    }

    .custom-control-label::before {
        position: absolute;
        top: 0.25rem;
        left: -2rem;
        display: block;
        width: 1.25rem;
        height: 1.25rem;
        pointer-events: none;
        content: "";
        background-color: #fff;
        border: 2px solid #ced4da;
        border-radius: 0.25rem;
        transition: all 0.15s ease-in-out;
    }

    .custom-control-label::after {
        position: absolute;
        top: 0.25rem;
        left: -2rem;
        display: block;
        width: 1.25rem;
        height: 1.25rem;
        content: "";
        background: no-repeat 50% / 60% 60%;
    }

    .custom-control-input:checked~.custom-control-label::before {
        color: #fff;
        border-color: #264c7eff;
        background-color: #264c7eff;
    }

    .custom-control-input:checked~.custom-control-label::after {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23fff' d='m6.564.75-3.59 3.612-1.538-1.55L0 4.26 2.974 7.25 8 2.193z'/%3e");
    }

    .custom-control-input:disabled~.custom-control-label {
        color: #6c757d;
        opacity: 0.6;
    }

    .custom-control-input:disabled~.custom-control-label::before {
        background-color: #e9ecef;
        border-color: #adb5bd;
    }

    /* Field Grid */
    .field-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        margin-bottom: 20px;
    }

    .checkbox-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }

    /* SAP Location Table */
    .sap-location-table {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        overflow: hidden;
        background: white;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .sap-location-table table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
    }

    .sap-location-table th {
        background: #f8f9fa;
        padding: 15px;
        text-align: center;
        font-weight: 600;
        color: #495057;
        border: none;
        border-bottom: 1px solid #dee2e6;
        text-transform: uppercase;
        letter-spacing: 0.2px;
        font-size: 12pt;
    }

    .sap-location-table td {
        background: #ffffff;
        padding: 15px;
        vertical-align: middle;
        border: none;
        text-align: center;
    }

    /* Approval Matrix */
    .approval-matrix {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        overflow: hidden;
        margin-top: 15px;
        background: white;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .approval-matrix table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10pt;
        margin: 0;
    }

    .approval-matrix th {
        background: #f8f9fa;
        padding: 15px;
        text-align: center;
        font-weight: 600;
        color: #495057;
        border-right: 1px solid #dee2e6;
        text-transform: uppercase;
        letter-spacing: 0.2px;
        font-size: 11pt;
    }

    .approval-matrix td {
        padding: 20px 15px;
        text-align: center;
        vertical-align: middle;
        border-right: 1px solid #dee2e6;
        border-bottom: 1px solid #dee2e6;
        min-height: 120px;
    }

    .approval-matrix th:last-child,
    .approval-matrix td:last-child {
        border-right: none;
    }

    .signature-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        min-height: 100px;
        justify-content: space-between;
    }

    .signature-container img {
        max-width: 120px;
        max-height: 60px;
        margin-bottom: 10px;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        padding: 5px;
        background: white;
    }

    .signature-container span {
        font-weight: 500;
        color: #495057;
        font-size: 11pt;
        text-align: center;
    }

    /* Material Details Table */
    .materials-section {
        margin-top: 20px;
    }

    .materials-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding: 0 5px;
    }

    .materials-header h5 {
        margin: 0;
        color: #495057;
        font-weight: 600;
    }

    /* Material Count Indicator */
    .material-count-indicator {
        background: #e3f2fd;
        border: 1px solid #2196f3;
        border-radius: 4px;
        padding: 8px 15px;
        margin-bottom: 10px;
        font-size: 11pt;
        color: #1565c0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .material-count-indicator i {
        color: #2196f3;
    }

    .material-count-indicator.no-records {
        background: #fff3cd;
        border-color: #ffc107;
        color: #856404;
    }

    .material-count-indicator.no-records i {
        color: #ffc107;
    }

    #material_details_table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10pt;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        overflow: hidden;
    }

    #material_details_table th {
        background: #f8f9fa;
        padding: 12px 8px;
        text-align: center;
        font-weight: 600;
        color: #495057;
        border: 1px solid #dee2e6;
        text-transform: uppercase;
        letter-spacing: 0.1px;
        font-size: 10pt;
        line-height: 1.2;
    }

    #material_details_table td {
        padding: 8px;
        border: 1px solid #dee2e6;
        text-align: left;
        vertical-align: middle;
        background: white;
    }

    #material_details_table input {
        border: 1px solid #ced4da;
        border-radius: 3px;
        padding: 6px 8px;
        font-size: 10pt;
        width: 100%;
        background: white;
        transition: border-color 0.15s ease-in-out;
    }

    #material_details_table input:focus {
        border-color: #264c7eff;
        outline: none;
        box-shadow: 0 0 0 1px rgba(38, 76, 126, 0.25);
    }

    #material_details_table input::placeholder {
        color: #6c757d;
        font-style: italic;
    }

    #material_details_table tbody tr:nth-child(even) {
        background: #f9f9f9;
    }

    #material_details_table tbody tr:hover {
        background: #e3f2fd;
    }

    /* Row with data styling */
    #material_details_table tbody tr.has-data {
        background: #f0f8f0 !important;
        border-left: 3px solid #28a745;
    }

    #material_details_table tbody tr.has-data:hover {
        background: #e8f5e8 !important;
    }

    /* Button Styling */
    .btn {
        border-radius: 4px;
        font-size: 11pt;
        font-weight: 500;
        padding: 10px 20px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        transition: all 0.2s ease;
        border: none;
        cursor: pointer;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .btn-primary {
        background: linear-gradient(135deg, #264c7eff 0%, #1a3a5c 100%);
        color: white;
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #1a3a5c 0%, #0f2a42 100%);
        color: white;
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
        color: white;
    }

    .btn-success {
        background: #28a745;
        color: white;
        font-size: 13pt;
        padding: 12px 30px;
    }

    .btn-success:hover {
        background: #218838;
        color: white;
    }

    .btn-danger {
        background: #dc3545;
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
        color: white;
    }

    .btn-sm {
        font-size: 9pt;
        padding: 5px 10px;
    }

    .btn-lg {
        font-size: 14pt;
        padding: 15px 40px;
    }

    /* Modal Styling */
    .modal-content {
        border-radius: 8px;
        border: none;
        box-shadow: 0 16px 32px rgba(0, 0, 0, 0.15);
    }

    .modal-header {
        background: linear-gradient(135deg, #264c7eff 0%, #1a3a5c 100%);
        color: white;
        border-bottom: none;
        border-radius: 8px 8px 0 0;
        padding: 20px 25px;
    }

    .modal-title {
        color: white;
        font-weight: 600;
        font-size: 16pt;
    }

    .modal-header .close {
        color: white;
        opacity: 0.8;
        font-size: 20pt;
    }

    .modal-header .close:hover {
        opacity: 1;
        color: white;
    }

    .modal-body {
        padding: 25px;
        max-height: 70vh;
        overflow-y: auto;
    }

    .modal-footer {
        border-top: 1px solid #e9ecef;
        padding: 20px 25px;
        background: #f8f9fa;
    }

    /* Additional Form Specific Styles */
    .submit-section {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 8px;
        padding: 30px;
        text-align: center;
        margin-top: 30px;
        border: 1px solid #dee2e6;
    }

    .table-responsive {
        border-radius: 6px;
        overflow: hidden;
        border: 1px solid #dee2e6;
        background: white;
    }

    /* Status indicators */
    .form-status {
        background: #e3f2fd;
        border: 1px solid #2196f3;
        border-radius: 6px;
        padding: 15px 20px;
        margin: 20px 0;
        color: #1565c0;
        text-align: center;
        font-weight: 500;
    }

    /* Loading states */
    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .btn:disabled:hover {
        transform: none;
        box-shadow: none;
    }

    /* Validation styling */
    .is-invalid {
        border-color: #dc3545;
    }

    .is-invalid:focus {
        border-color: #dc3545;
        box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.25);
    }

    .invalid-feedback {
        display: block;
        width: 100%;
        margin-top: 5px;
        font-size: 10pt;
        color: #dc3545;
    }

    .readonly-field {
        background-color: #f8f9fa !important;
        border-color: #e9ecef !important;
        color: #6c757d !important;
        cursor: not-allowed !important;
    }

    /* Enhanced Modal Sizing - Custom Breakpoints */
    .modal-xl-custom {
        max-width: 95vw !important;
        width: 95vw !important;
    }

    /* Enhanced Modal Content */
    #materialDetailsModal .modal-content {
        height: 90vh;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
    }

    #materialDetailsModal .modal-body {
        flex: 1;
        overflow: hidden;
        padding: 20px 25px;
        display: flex;
        flex-direction: column;
    }

    /* Custom Table Responsive Container */
    .table-responsive-custom {
        flex: 1;
        overflow: auto;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        background: white;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    /* Enhanced Material Table Styling */
    .material-table-enhanced {
        width: 100%;
        border-collapse: collapse;
        font-size: 11pt;
        background: white;
        margin: 0;
        min-width: 1600px;
    }

    .material-table-enhanced th {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 15px 12px;
        text-align: center;
        font-weight: 600;
        color: #495057;
        border: 1px solid #dee2e6;
        text-transform: uppercase;
        letter-spacing: 0.2px;
        font-size: 10pt;
        line-height: 1.3;
        position: sticky;
        top: 0;
        z-index: 10;
        white-space: nowrap;
    }

    .material-table-enhanced td {
        padding: 12px;
        border: 1px solid #dee2e6;
        text-align: center;
        vertical-align: middle;
        background: white;
    }

    /* Enhanced Input Field Styling in Modal */
    .material-table-enhanced input {
        border: 2px solid #e9ecef;
        border-radius: 6px;
        padding: 10px 12px;
        font-size: 11pt;
        width: 100%;
        background: white;
        transition: all 0.2s ease-in-out;
        min-height: 42px;
    }

    .material-table-enhanced input:focus {
        border-color: #264c7eff;
        outline: none;
        box-shadow: 0 0 0 3px rgba(38, 76, 126, 0.15);
        background: #fff;
        transform: scale(1.02);
    }

    .material-table-enhanced input::placeholder {
        color: #6c757d;
        font-style: italic;
        font-size: 10pt;
    }

    /* Enhanced Row Styling */
    .material-table-enhanced tbody tr {
        transition: all 0.2s ease;
    }

    .material-table-enhanced tbody tr:nth-child(even) {
        background: #f8f9fa;
    }

    .material-table-enhanced tbody tr:hover {
        background: #e3f2fd !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .material-table-enhanced tbody tr.has-data {
        background: linear-gradient(135deg, #f0f8f0 0%, #e8f5e8 100%) !important;
        border-left: 4px solid #28a745;
    }

    .material-table-enhanced tbody tr.has-data:hover {
        background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%) !important;
    }

    /* Enhanced Button Styling in Modal */
    .material-table-enhanced .btn-danger {
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 10pt;
        transition: all 0.2s ease;
    }

    .material-table-enhanced .btn-danger:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
    }

    /* Modal Footer Enhancement */
    #materialDetailsModal .modal-footer {
        border-top: 2px solid #e9ecef;
        padding: 20px 25px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 0 0 8px 8px;
    }

    /* Row Number Styling */
    .material-table-enhanced td:first-child {
        background: #f8f9fa;
        font-weight: 600;
        color: #495057;
        border-right: 2px solid #dee2e6;
        position: sticky;
        left: 0;
        z-index: 5;
    }

    /* Scrollbar Styling for Table */
    .table-responsive-custom::-webkit-scrollbar {
        width: 12px;
        height: 12px;
    }

    .table-responsive-custom::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 6px;
    }

    .table-responsive-custom::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 6px;
        transition: background 0.2s ease;
    }

    .table-responsive-custom::-webkit-scrollbar-thumb:hover {
        background: #264c7eff;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .document-header-content {
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .company-section {
            width: 100%;
            text-align: center;
            align-items: center;
        }

        .control-number-box {
            min-width: 200px;
            max-width: 250px;
        }

        .card-body {
            padding: 20px 15px;
        }

        .field-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .checkbox-grid {
            grid-template-columns: 1fr;
        }

        .modal-xl-custom {
            max-width: 100vw !important;
            width: 100vw !important;
            margin: 0;
        }

        #materialDetailsModal .modal-content {
            height: 100vh;
            border-radius: 0;
        }

        .material-table-enhanced {
            min-width: 1000px;
            font-size: 9pt;
        }

        .material-table-enhanced th,
        .material-table-enhanced td {
            padding: 8px 4px;
        }

        .material-table-enhanced input {
            padding: 6px 8px;
            font-size: 9pt;
            min-height: 34px;
        }
    }

    @media (max-width: 480px) {
        .company-logo {
            max-height: 35px;
        }

        .company-name {
            font-size: 11pt;
        }

        .company-subtitle {
            font-size: 8pt;
        }

        .control-number-box {
            min-width: 150px;
            max-width: 180px;
        }

        .card-title {
            font-size: 16pt;
        }

        .content {
            padding: 10px;
        }

        .material-table-enhanced {
            min-width: 800px;
            font-size: 8pt;
        }

        .material-table-enhanced th {
            padding: 6px 3px;
            font-size: 7pt;
        }

        .material-table-enhanced td {
            padding: 4px 3px;
        }

        .material-table-enhanced input {
            padding: 4px 6px;
            font-size: 8pt;
            min-height: 30px;
        }
    }

    /* Print Styles */
    @media print {

        .btn,
        .modal {
            display: none !important;
        }

        .card {
            box-shadow: none;
            border: 1px solid #000;
        }

        .card-header {
            border-bottom: 2pt solid #264c7eff;
        }

        .control-number-box {
            border: 1pt solid #264c7eff;
        }
    }

    /* Enhanced paste target indicator */
.paste-target {
    border: 2px solid #007bff !important;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25) !important;
}

/* Column paste options styling */
.column-paste-options .form-group {
    margin-bottom: 15px;
}

.column-preview {
    max-height: 250px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.column-preview .list-group {
    margin-bottom: 0;
}

/* Row paste options styling */
.row-paste-options .form-group {
    margin-bottom: 15px;
}

/* Multiple paste options styling */
.multiple-paste-options .form-group {
    margin-bottom: 15px;
}

/* Paste success animation */
.paste-success {
    animation: pasteHighlight 2s ease-in-out;
    border-color: #28a745 !important;
}

@keyframes pasteHighlight {
    0% { 
        background-color: #d4edda !important; 
        transform: scale(1.02);
    }
    50% { 
        background-color: #c3e6cb !important; 
        transform: scale(1.02);
    }
    100% { 
        background-color: inherit; 
        transform: scale(1);
    }
}

/* Enhanced visual feedback for different paste types */
.alert-primary {
    background-color: #e3f2fd;
    border-color: #2196f3;
    color: #1565c0;
}

.alert-success {
    background-color: #f0f8f0;
    border-color: #28a745;
    color: #155724;
}

/* Improved table cell selection */
.material-table-enhanced-inline input:focus {
    border-color: #264c7eff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(38, 76, 126, 0.15);
    background: #fff;
    transform: scale(1.02);
    z-index: 10;
    position: relative;
}

    /* Paste-related styling */
    .paste-options {
        text-align: left;
        margin: 15px 0;
    }

    .paste-options .form-group {
        margin-bottom: 15px;
    }

    .paste-options .form-check {
        margin-bottom: 8px;
    }

    .paste-options .alert {
        margin: 10px 0;
        font-size: 12px;
    }

    /* Table focus indicator for paste */
    .table-responsive-custom-inline:focus {
        outline: 2px solid #264c7eff;
        outline-offset: 2px;
    }

    /* Paste preview table styling */
    .swal2-html-container .table {
        font-size: 11px;
    }

    .swal2-html-container .table td {
        max-width: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Help button styling */
    #pasteHelpBtn {
        margin-left: 10px;
    }

    /* Enhanced paste loading indicator */
    .paste-processing {
        position: relative;
    }

    .paste-processing::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.8);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Success highlight for pasted rows */
    .paste-success {
        animation: pasteHighlight 2s ease-in-out;
    }

    @keyframes pasteHighlight {
        0% {
            background-color: #d4edda;
        }

        100% {
            background-color: inherit;
        }
    }
</style>

<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    <div class="main-panel">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        <div class="content">
            <div class="container-fluid">
                <!-- Success/Error Alerts -->
                <?php if (isset($showSuccessAlert) && $showSuccessAlert): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <h4 class="alert-heading"><i class="fas fa-check-circle"></i> Form Submitted Successfully!</h4>
                        <p>Your RTS form has been submitted successfully.</p>
                        <hr>
                        <p class="mb-0"><strong>Control Number:</strong> <code><?= htmlspecialchars($controlNo) ?></code></p>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (isset($showErrorAlert) && $showErrorAlert): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> Submission Error</h4>
                        <p class="mb-0"><?= htmlspecialchars($errorMessage) ?></p>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <!-- Document Header-->
                            <div class="card-header">
                                <div class="document-header-content">
                                    <div class="company-section">
                                        <img src="<?php echo BASE_URL; ?>/assets/img/logo_black.png" alt="Fujifilm Logo" class="company-logo">
                                        <div class="company-details">
                                            <div class="company-name"><strong>Fujifilm Optics Philippines Inc.</strong></div>
                                        </div>
                                    </div>

                                    <div class="control-number-box">
                                        <span class="label">Control No.</span>
                                        <input type="text" id="control_no" name="control_no" value="<?= htmlspecialchars($control_no) ?>" readonly>
                                    </div>
                                </div>

                                <div class="document-title-section">
                                    <h1 class="card-title">Return / Transfer Slip</h1>
                                    <div class="subtitle">Centralized Return to Vendor & Return To Stock Monitoring System</div>
                                </div>
                            </div>

                            <div class="card-body">
                                <form id="rtsForm" method="POST" action="<?= BASE_URL ?>/pages/scrap/process_rts_form.php">
                                    <input type="hidden" name="requestor_id" value="<?php echo getCurrentUserId(); ?>">
                                    <input type="hidden" name="requestor_name" value="<?php echo htmlspecialchars($user_name); ?>">
                                    <input type="hidden" name="requestor_department" value="<?php echo htmlspecialchars($user_department); ?>">

                                    <!-- Material Classification Section -->
                                    <div class="form-section">
                                        <div class="section-header">
                                            <i class="fas fa-boxes"></i> Material Classification
                                        </div>
                                        <div class="checkbox-grid">
                                            <div class="form-group">
                                                <label>Material Type</label>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="rawMaterial"
                                                        name="material_type[]" value="Raw Material">
                                                    <label class="custom-control-label" for="rawMaterial">Raw Material</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="packagingMaterial"
                                                        name="material_type[]" value="Packaging Material">
                                                    <label class="custom-control-label" for="packagingMaterial">Packaging Material</label>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label>Material Status</label>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input material-status-checkbox"
                                                        id="good" name="material_status[]" value="Good">
                                                    <label class="custom-control-label" for="good">Good</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input material-status-checkbox"
                                                        id="materialDefect" name="material_status[]" value="Material Defect">
                                                    <label class="custom-control-label" for="materialDefect">Material Defect</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input material-status-checkbox"
                                                        id="humanError" name="material_status[]" value="Human Error">
                                                    <label class="custom-control-label" for="humanError">Human Error</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input material-status-checkbox"
                                                        id="endOfLife" name="material_status[]" value="EOL">
                                                    <label class="custom-control-label" for="endOfLife">End of Life (EOL)</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input material-status-checkbox"
                                                        id="othersNoGood" name="material_status[]" value="NG/Others">
                                                    <label class="custom-control-label" for="othersNoGood">NG/Others</label>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label>Judgement</label>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input judgement-checkbox"
                                                        id="scrapdisposal" name="judgement[]" value="Scrap/Disposal" disabled>
                                                    <label class="custom-control-label" for="scrapdisposal">Scrap/Disposal</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input judgement-checkbox"
                                                        id="rtv" name="judgement[]" value="RTV" disabled>
                                                    <label class="custom-control-label" for="rtv">Return to Vendor</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input judgement-checkbox"
                                                        id="hold" name="judgement[]" value="Hold" disabled>
                                                    <label class="custom-control-label" for="hold">Hold</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input judgement-checkbox"
                                                        id="transfertogood" name="judgement[]" value="Transfer to Good" disabled>
                                                    <label class="custom-control-label" for="transfertogood">Transfer to Good</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- SAP Location Section -->
                                    <div class="form-section">
                                        <div class="section-header">
                                            <i class="fas fa-map-marker-alt"></i> SAP Location Code
                                        </div>
                                        <div class="sap-location-table">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th style="width: 50%;">From Location</th>
                                                        <th style="width: 50%;">To Location</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>
                                                            <select class="form-control" name="sap_location[from]" id="sapLocationFrom" required>
                                                                <option value="" disabled selected>Select From Location</option>
                                                                <?php if (!empty($sap_locations)): ?>
                                                                    <?php foreach ($sap_locations as $location): ?>
                                                                        <option value="<?php echo htmlspecialchars($location['LocationCode']); ?>">
                                                                            <?php echo htmlspecialchars($location['LocationCode'] . ' - ' . $location['LocationDescription'] . ' (' . $location['Department'] . ')'); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                <?php else: ?>
                                                                    <option value="" disabled>No locations available</option>
                                                                <?php endif; ?>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select class="form-control" name="sap_location[to]" id="sapLocationTo" required>
                                                                <option value="" disabled selected>Select To Location</option>
                                                                <?php if (!empty($sap_locations)): ?>
                                                                    <?php foreach ($sap_locations as $location): ?>
                                                                        <option value="<?php echo htmlspecialchars($location['LocationCode']); ?>">
                                                                            <?php echo htmlspecialchars($location['LocationCode'] . ' - ' . $location['LocationDescription'] . ' (' . $location['Department'] . ')'); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                <?php else: ?>
                                                                    <option value="" disabled>No locations available</option>
                                                                <?php endif; ?>
                                                            </select>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Additional Information Section -->
                                    <div class="form-section">
                                        <div class="section-header">
                                            <i class="fas fa-info-circle"></i> Additional Information
                                        </div>
                                        <div class="field-grid">
                                            <div class="form-group">
                                                <label for="details">Details under others status</label>
                                                <textarea class="form-control" id="details" name="details" rows="4"
                                                    placeholder="Enter additional details..." disabled></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label for="remarks">Remarks</label>
                                                <textarea name="remark" id="remark" class="form-control" rows="4"
                                                    placeholder="Enter remarks..."></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Form Details Section -->
                                    <div class="form-section">
                                        <div class="section-header">
                                            <i class="fas fa-calendar-alt"></i> Model & Department Details
                                        </div>
                                        <div class="field-grid">
                                            <div class="form-group">
                                                <label for="return_date">Return Date</label>
                                                <input type="date" class="form-control" id="return_date" name="return_date"
                                                    min="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="department">Department</label>
                                                <input type="text" class="form-control readonly-field" id="department" name="department"
                                                    value="<?php echo htmlspecialchars($user_department); ?>" readonly>
                                                <input type="hidden" name="department_backup" value="<?php echo htmlspecialchars($user_department); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="model">Model</label>
                                                <input type="text" class="form-control" id="model" name="model"
                                                    placeholder="Enter Model" required>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Approval Matrix Section -->
                                    <div class="form-section">
                                        <div class="section-header">
                                            <i class="fas fa-clipboard-check"></i> Approval Matrix
                                        </div>
                                        <div class="approval-matrix">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Prepared By</th>
                                                        <th>Checked By</th>
                                                        <th>Approved By</th>
                                                        <th>Noted By</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>
                                                            <div class="signature-container">
                                                                <?php if (!empty($signature_base64)): ?>
                                                                    <img src="<?php echo $signature_base64; ?>" alt="Signature">
                                                                <?php endif; ?>
                                                                <span><?php echo htmlspecialchars($user_name); ?></span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="signature-container">
                                                                <img src="<?php echo BASE_URL . '/assets/img/e_signiture/pending-stamp.png'; ?>" alt="Pending">
                                                                <span>Pending</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="signature-container">
                                                                <img src="<?php echo BASE_URL . '/assets/img/e_signiture/pending-stamp.png'; ?>" alt="Pending">
                                                                <span>Pending</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="signature-container">
                                                                <img src="<?php echo BASE_URL . '/assets/img/e_signiture/pending-stamp.png'; ?>" alt="Pending">
                                                                <span>Pending</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>


                                    <!-- Material Count Indicator -->
                                    <div class="material-count-indicator no-records" id="materialCountIndicator">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <span id="materialCountText">No material details added yet. Please add material information.</span>
                                    </div>

                                    <!-- Material Details Section -->
                                    <div class="form-section">
                                        <div class="section-header collapsible-header" data-toggle="collapse" data-target="#materialDetailsCollapse" aria-expanded="false">
                                            <i class="fas fa-list-alt"></i> Material Details
                                            <i class="fas fa-chevron-down collapse-icon"></i>
                                        </div>

                                        <!-- Collapsible Material Details Table -->
                                        <div class="collapse" id="materialDetailsCollapse">
                                            <div class="materials-header">
                                                <div class="d-flex align-items-center">
                                                    <input type="number" class="form-control mr-2" id="numRowsInput"
                                                        placeholder="Number of rows" min="1" max="50" value="5" style="width: 150px;">
                                                    <button type="button" class="btn btn-secondary" id="addRowsBtn">
                                                        <i class="fas fa-plus"></i> Add Rows
                                                    </button>
                                                </div>
                                                <button type="button" class="btn btn-danger" id="clearAllRowsBtn">
                                                    <i class="fas fa-trash"></i> Clear All
                                                </button>
                                            </div>

                                            <div class="table-responsive-custom-inline">
                                                <table id="material_details_table" class="material-table-enhanced-inline">
                                                    <thead>
                                                        <tr>
                                                            <th style="width: 60px;">No.</th>
                                                            <th style="width: 140px;">Ref. No</th>
                                                            <th style="width: 150px;">SAP Mat Doc</th>
                                                            <th style="width: 130px;">Invoice No</th>
                                                            <th style="width: 180px;">Supplier</th>
                                                            <th style="width: 140px;">Part Number</th>
                                                            <th style="width: 160px;">Part Name</th>
                                                            <th style="width: 180px;">Description</th>
                                                            <th style="width: 110px;">Qty Returned</th>
                                                            <th style="width: 110px;">Qty Received</th>
                                                            <th style="width: 100px;">Amount</th>
                                                            <th style="width: 140px;">Due Date</th>
                                                            <th style="width: 90px;">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <!-- Rows will be added dynamically -->
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Submit Section -->
                                    <div class="submit-section">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-paper-plane"></i> Submit RTS Form
                                        </button>
                                        <div style="margin-top: 10px; color: #6c757d; font-size: 10pt;">
                                            <i class="fas fa-info-circle"></i> Please review all information before submitting
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php include INCLUDES_PATH . '/footer.php'; ?>
    </div>
</div>

<script>
    $(document).ready(function() {
        let totalRows = 0;
        let recordsWithData = 0;

        // Set today's date as default for return date
        const today = new Date().toISOString().split('T')[0];
        $('#return_date').val(today);

        // Initialize with 5 rows when page loads
        setTimeout(() => {
            generateTableRows(5);
        }, 500);

        // Collapsible header functionality
        $('.collapsible-header').on('click', function() {
            const target = $(this).attr('data-target');
            const $target = $(target);
            const isExpanded = $(this).attr('aria-expanded') === 'true';
            
            if (isExpanded) {
                $target.collapse('hide');
                $(this).attr('aria-expanded', 'false');
            } else {
                $target.collapse('show');
                $(this).attr('aria-expanded', 'true');
            }
        });

        // Auto-expand on first interaction
        let hasInteracted = false;
        $(document).on('focus', '.material-input', function() {
            if (!hasInteracted) {
                $('#materialDetailsCollapse').collapse('show');
                $('.collapsible-header').attr('aria-expanded', 'true');
                hasInteracted = true;
            }
        });

        // Add rows functionality
        $('#addRowsBtn').on('click', function() {
            const numRows = parseInt($('#numRowsInput').val()) || 5;
            if (numRows >= 1 && numRows <= 50) {
                generateTableRows(numRows);
                $('#numRowsInput').val('');
                
                // Auto-expand if collapsed
                if (!$('#materialDetailsCollapse').hasClass('show')) {
                    $('#materialDetailsCollapse').collapse('show');
                    $('.collapsible-header').attr('aria-expanded', 'true');
                }
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Input',
                    text: 'Please enter a number between 1 and 50.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#264c7eff'
                });
            }
        });

        // Clear all rows functionality
        $('#clearAllRowsBtn').on('click', function() {
            if ($('#material_details_table tbody tr').length > 0) {
                Swal.fire({
                    title: 'Clear All Rows?',
                    text: 'This will remove all material details. This action cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Clear All',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $('#material_details_table tbody').empty();
                        totalRows = 0;
                        recordsWithData = 0;
                        updateMaterialCount();
                        showEmptyTableMessage();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Cleared',
                            text: 'All material details have been cleared.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                });
            }
        });

        // Show empty table message
        function showEmptyTableMessage() {
            if ($('#material_details_table tbody tr').length === 0) {
                const emptyMessage = `
                    <div class="empty-table-message">
                        <i class="fas fa-table"></i>
                        <h5>No Material Details Added</h5>
                        <p>Click "Add Rows" to start adding material information.</p>
                    </div>
                `;
                $('.table-responsive-custom-inline').html(emptyMessage);
            }
        }

        // Generate table rows
        function generateTableRows(numberOfRows) {
            // If table is showing empty message, restore table structure
            if ($('.empty-table-message').length > 0) {
                $('.table-responsive-custom-inline').html(`
                    <table id="material_details_table" class="material-table-enhanced-inline">
                        <thead>
                            <tr>
                                <th style="width: 60px;">No.</th>
                                <th style="width: 140px;">Ref. No</th>
                                <th style="width: 150px;">SAP Mat Doc</th>
                                <th style="width: 130px;">Invoice No</th>
                                <th style="width: 180px;">Supplier</th>
                                <th style="width: 140px;">Part Number</th>
                                <th style="width: 160px;">Part Name</th>
                                <th style="width: 180px;">Description</th>
                                <th style="width: 110px;">Qty Returned</th>
                                <th style="width: 110px;">Qty Received</th>
                                <th style="width: 100px;">Amount</th>
                                <th style="width: 140px;">Due Date</th>
                                <th style="width: 90px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                `);
            }

            let rowsHtml = '';
            const startingIndex = $('#material_details_table tbody tr').length + 1;

            for (let i = 0; i < numberOfRows; i++) {
                const rowNumber = startingIndex + i;
                rowsHtml += `
                <tr data-row-number="${rowNumber}">
                    <td style="text-align: center; font-weight: 500;">${rowNumber}</td>
                    <td><input type="text" class="form-control material-input" name="material_details[ref_no][]" placeholder="Ref. No"></td>
                    <td><input type="text" class="form-control material-input" name="material_details[sap_doc][]" placeholder="SAP Doc"></td>
                    <td><input type="text" class="form-control material-input" name="material_details[invoice_no][]" placeholder="Invoice"></td>
                    <td><input type="text" class="form-control material-input" name="material_details[supplier][]" placeholder="Supplier"></td>
                    <td><input type="text" class="form-control material-input" name="material_details[part_number][]" placeholder="Part No"></td>
                    <td><input type="text" class="form-control material-input" name="material_details[part_name][]" placeholder="Part Name"></td>
                    <td><input type="text" class="form-control material-input" name="material_details[description][]" placeholder="Description"></td>
                    <td><input type="number" class="form-control material-input" name="material_details[qty_returned][]" placeholder="0" min="0"></td>
                    <td><input type="number" class="form-control material-input" name="material_details[qty_received][]" placeholder="0" min="0"></td>
                    <td><input type="number" class="form-control material-input" name="material_details[amount][]" placeholder="0.00" min="0" step="0.01"></td>
                    <td><input type="date" class="form-control material-input" name="material_details[due_date][]"></td>
                    <td style="text-align: center;">
                        <button type="button" class="btn btn-danger btn-sm remove-row-btn">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>`;
            }

            $('#material_details_table tbody').append(rowsHtml);
            totalRows += numberOfRows;

            // Attach event listeners to new inputs
            attachMaterialInputListeners();
            updateMaterialCount();
        }

        // Attach event listeners to material inputs for real-time tracking
        function attachMaterialInputListeners() {
            $(document).off('input change', '.material-input');
            $(document).on('input change', '.material-input', function() {
                const $row = $(this).closest('tr');
                checkRowData($row);
                updateMaterialCount();
            });
        }

        // Check if a row has data
        function checkRowData($row) {
            const inputs = $row.find('.material-input');
            let hasData = false;

            inputs.each(function() {
                if ($(this).val().trim() !== '') {
                    hasData = true;
                    return false; // Break the loop
                }
            });

            if (hasData) {
                $row.addClass('has-data');
            } else {
                $row.removeClass('has-data');
            }

            return hasData;
        }

        // Update material count indicator
        function updateMaterialCount() {
            recordsWithData = 0;
            const totalAvailableRows = $('#material_details_table tbody tr').length;

            $('#material_details_table tbody tr').each(function() {
                if (checkRowData($(this))) {
                    recordsWithData++;
                }
            });

            const $indicator = $('#materialCountIndicator');
            const $text = $('#materialCountText');

            if (recordsWithData > 0) {
                $indicator.removeClass('no-records');
                $indicator.find('i').removeClass('fa-exclamation-triangle').addClass('fa-check-circle');
                $text.text(`${recordsWithData} material record(s) added out of ${totalAvailableRows} available rows.`);
            } else {
                $indicator.addClass('no-records');
                $indicator.find('i').removeClass('fa-check-circle').addClass('fa-exclamation-triangle');
                if (totalAvailableRows > 0) {
                    $text.text(`No material details filled yet. ${totalAvailableRows} rows available for input.`);
                } else {
                    $text.text('No material details added yet. Please add material information.');
                }
            }
        }

        // Remove row functionality
        $(document).on('click', '.remove-row-btn', function() {
            const $row = $(this).closest('tr');
            
            Swal.fire({
                title: 'Remove Row?',
                text: 'Are you sure you want to remove this material entry?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Remove',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $row.remove();
                    renumberRows();
                    updateMaterialCount();
                    
                    // Show empty message if no rows left
                    if ($('#material_details_table tbody tr').length === 0) {
                        showEmptyTableMessage();
                    }
                }
            });
        });

        // Renumber rows after removal
        function renumberRows() {
            $('#material_details_table tbody tr').each(function(index) {
                $(this).find('td:first').text(index + 1);
                $(this).attr('data-row-number', index + 1);
            });
        }

        // ======= ENHANCED EXCEL COLUMN PASTE FUNCTIONALITY =======
        
        // Add paste functionality to the table
        let isProcessingPaste = false;
        let selectedCell = null;
        
        // Make the table container focusable for paste events
        $('.table-responsive-custom-inline').attr('tabindex', '0');
        
        // Track which cell is selected for pasting
        $(document).on('click', '.material-input', function() {
            selectedCell = $(this);
            $('.material-input').removeClass('paste-target');
            $(this).addClass('paste-target');
        });
        
        // Handle paste events on the table container or inputs
        $(document).on('paste', '.table-responsive-custom-inline, .material-input', function(e) {
            if (isProcessingPaste) return;
            
            e.preventDefault();
            isProcessingPaste = true;
            
            const clipboardData = e.originalEvent.clipboardData || window.clipboardData;
            const pastedData = clipboardData.getData('text');
            
            if (pastedData.trim()) {
                processColumnPasteData(pastedData);
            }
            
            setTimeout(() => {
                isProcessingPaste = false;
            }, 100);
        });

        // Process column paste data with enhanced Excel parsing
        function processColumnPasteData(pastedData) {
            try {
                console.log('Raw pasted data:', JSON.stringify(pastedData));
                
                // Clean the pasted data first
                const cleanData = pastedData.trim();
                
                let parsedLines = [];
                
                // Try multiple parsing methods for Excel data
                
                if (cleanData.includes('') || cleanData.includes('\r')) {
                    parsedLines = cleanData.split(/\r? |\r/);
                }
                // Method 2: Excel sometimes uses vertical tab or form feed
                else if (cleanData.includes('\v') || cleanData.includes('\f')) {
                    parsedLines = cleanData.split(/\v|\f/);
                }
                // Method 3: For single column data, try to detect if it's concatenated
                else if (cleanData.length > 1) {
                    // Check if it looks like concatenated data (e.g., "No.12345")
                    parsedLines = parseConcatenatedData(cleanData);
                }
                else {
                    // Single value
                    parsedLines = [cleanData];
                }
                
                console.log('Parsed lines:', parsedLines);
                
                let parsedData = parsedLines.map(line => {
                    // Split by tabs (Excel default) and clean each cell
                    return line.split('\t').map(cell => cell.trim().replace(/"/g, ''));
                });

                // Filter out completely empty rows
                parsedData = parsedData.filter(row => {
                    return row.some(cell => cell && cell.trim() !== '');
                });

                if (parsedData.length === 0) {
                    showPasteError('No data found to paste.');
                    return;
                }

                // Determine data type and process accordingly
                const isColumnData = parsedData.every(row => row.length === 1) && parsedData.length > 1;
                const isRowData = parsedData.length === 1 && parsedData[0].length > 1;
                const isMultipleData = parsedData.length > 1 && parsedData[0].length > 1;

                if (isColumnData) {
                    // Extract values from the column
                    let columnValues = parsedData.map(row => row[0]).filter(value => value && value.trim() !== '');
                    
                    // Remove header if detected
                    columnValues = removeHeaderFromColumnData(columnValues);
                    
                    if (columnValues.length === 0) {
                        showPasteError('No valid data found in the copied column. Only headers were detected.');
                        return;
                    }
                    
                    console.log('Final column values:', columnValues);
                    showColumnPasteDialog(columnValues);
                } else if (isRowData) {
                    // Filter out empty values from the row
                    let rowValues = parsedData[0].filter(value => value && value.trim() !== '');
                    
                    // Remove headers if detected
                    rowValues = removeHeaderFromRowData(rowValues);
                    
                    if (rowValues.length === 0) {
                        showPasteError('No valid data found in the copied row.');
                        return;
                    }
                    showRowPasteDialog(rowValues);
                } else if (isMultipleData) {
                    // Filter out completely empty rows and columns
                    let cleanedData = cleanMultipleData(parsedData);
                    
                    // Remove header row if detected
                    cleanedData = removeHeaderFromMultipleData(cleanedData);
                    
                    if (cleanedData.length === 0) {
                        showPasteError('No valid data found to paste.');
                        return;
                    }
                    showMultiplePasteDialog(cleanedData);
                } else {
                    // Single cell - handle concatenated data
                    let singleValue = parsedData[0][0];
                    
                    // Check if this might be concatenated column data
                    if (singleValue && singleValue.length > 1) {
                        const potentialColumnData = parseConcatenatedData(singleValue);
                        if (potentialColumnData.length > 1) {
                            // Treat as column data
                            let columnValues = potentialColumnData.filter(value => value && value.trim() !== '');
                            columnValues = removeHeaderFromColumnData(columnValues);
                            
                            if (columnValues.length > 0) {
                                console.log('Detected concatenated column data:', columnValues);
                                showColumnPasteDialog(columnValues);
                                return;
                            }
                        }
                    }
                    
                    // Don't paste if it's clearly a header
                    if (isHeaderValue(singleValue)) {
                        showPasteError('Header detected. Please copy only the data values, not the column header.');
                        return;
                    }
                    
                    if (!singleValue || singleValue.trim() === '') {
                        showPasteError('No data to paste.');
                        return;
                    }
                    
                    if (selectedCell && selectedCell.length > 0) {
                        selectedCell.val(singleValue).trigger('input');
                        updateMaterialCount();
                        
                        selectedCell.addClass('paste-success');
                        setTimeout(() => {
                            selectedCell.removeClass('paste-success');
                        }, 1000);
                    } else {
                        showPasteError('Please click on a cell first, then paste.');
                    }
                }

            } catch (error) {
                console.error('Error processing paste data:', error);
                showPasteError('Failed to process pasted data. Please check the format.');
            }
        }

        // NEW: Parse concatenated data (like "No.12345" -> ["No.", "1", "2", "3", "4", "5"])
        function parseConcatenatedData(data) {
            console.log('Attempting to parse concatenated data:', data);
            
            // Method 1: Look for patterns like "HeaderText123456"
            const headerPattern = /^([a-zA-Z\s\.\-_]+)(.*)$/;
            const match = data.match(headerPattern);
            
            if (match && match[2]) {
                const headerPart = match[1].trim();
                const dataPart = match[2].trim();
                
                console.log('Header part:', headerPart, 'Data part:', dataPart);
                
                // Try to split the data part into individual characters/numbers
                let dataItems = [];
                
                // Method 1a: If data part contains only digits, split each digit
                if (/^\d+$/.test(dataPart)) {
                    dataItems = dataPart.split('').filter(char => char.trim() !== '');
                    console.log('Split digits:', dataItems);
                }
                // Method 1b: If data part contains mixed content, try other patterns
                else {
                    // Try splitting by common separators
                    const separators = [',', ';', '|', ' '];
                    for (const sep of separators) {
                        if (dataPart.includes(sep)) {
                            dataItems = dataPart.split(sep).filter(item => item.trim() !== '');
                            break;
                        }
                    }
                    
                    // If no separators found, try pattern matching for common data types
                    if (dataItems.length === 0) {
                        // Try to extract numbers, words, etc.
                        const numberMatches = dataPart.match(/\d+/g);
                        const wordMatches = dataPart.match(/[a-zA-Z]+/g);
                        
                        if (numberMatches && numberMatches.length > 1) {
                            dataItems = numberMatches;
                        } else if (wordMatches && wordMatches.length > 1) {
                            dataItems = wordMatches;
                        }
                    }
                }
                
                // If we found data items, combine with header
                if (dataItems.length > 0) {
                    const result = [headerPart, ...dataItems];
                    console.log('Parsed concatenated result:', result);
                    return result;
                }
            }
            
            // Method 2: Try character-by-character splitting for mixed content
            if (data.length > 2) {
                const chars = data.split('').filter(char => char.trim() !== '');
                
                // Look for transition points (letter to number, etc.)
                let segments = [];
                let currentSegment = '';
                let lastType = null;
                
                for (const char of chars) {
                    const currentType = /\d/.test(char) ? 'number' : 
                                      /[a-zA-Z]/.test(char) ? 'letter' : 'symbol';
                    
                    if (lastType && lastType !== currentType && currentSegment) {
                        segments.push(currentSegment);
                        currentSegment = char;
                    } else {
                        currentSegment += char;
                    }
                    
                    lastType = currentType;
                }
                
                if (currentSegment) {
                    segments.push(currentSegment);
                }
                
                console.log('Segmented result:', segments);
                
                if (segments.length > 1) {
                    return segments;
                }
            }
            
            // Fallback: return as single item
            return [data];
        }

        // Enhanced header detection for concatenated data
        function isHeaderValue(value) {
            if (!value || typeof value !== 'string') return false;
            
            const cleanValue = value.trim().toLowerCase();
            
            // Known headers (expand this list as needed)
            const knownHeaders = [
                'ref. no', 'ref.no', 'refno', 'ref', 'reference no', 'reference number',
                'sap doc', 'sap document', 'sap mat doc', 'material document', 'sap',
                'invoice', 'invoice no', 'invoice number', 'inv',
                'supplier', 'supplier name', 'vendor',
                'part number', 'part no', 'partno', 'part', 'material number',
                'part name', 'partname', 'material name', 'description', 'desc',
                'qty returned', 'quantity returned', 'qty', 'quantity',
                'qty received', 'quantity received', 'received qty',
                'amount', 'price', 'cost', 'value',
                'due date', 'date', 'return date',
                'no.', 'no', 'number'
            ];
            
            // Check exact matches
            if (knownHeaders.includes(cleanValue)) return true;
            
            // Check if it starts with known header patterns
            const headerStarts = ['ref', 'sap', 'inv', 'part', 'qty', 'amount', 'date'];
            if (headerStarts.some(start => cleanValue.startsWith(start))) return true;
            
            // Check if it ends with common header endings
            const headerEndings = ['no.', 'no', 'number', 'name', 'date'];
            if (headerEndings.some(end => cleanValue.endsWith(end))) return true;
            
            return false;
        }

        // Enhanced header removal
        function removeHeaderFromColumnData(columnValues) {
            if (columnValues.length === 0) return columnValues;
            
            console.log('Original column values:', columnValues);
            
            // Remove headers from the beginning
            let cleanedValues = [...columnValues];
            let headersRemoved = 0;
            
            while (cleanedValues.length > 0 && isHeaderValue(cleanedValues[0])) {
                const removed = cleanedValues.shift();
                headersRemoved++;
                console.log('Header detected and removed:', removed);
                
                // Safety check
                if (headersRemoved > 5) break;
            }
            
            console.log('Cleaned column values:', cleanedValues);
            return cleanedValues;
        }

        // Remove header from row data
        function removeHeaderFromRowData(rowValues) {
            if (rowValues.length === 0) return rowValues;
            
            // Remove headers from the beginning
            let cleanedValues = [...rowValues];
            let headersRemoved = 0;
            
            while (cleanedValues.length > 0 && isHeaderValue(cleanedValues[0])) {
                const removed = cleanedValues.shift();
                headersRemoved++;
                console.log('Header detected and removed:', removed);
                
                // Safety check to avoid infinite loop
                if (headersRemoved > 10) break;
            }
            
            return cleanedValues;
        }

        // Remove header row from multiple data
        function removeHeaderFromMultipleData(parsedData) {
            if (parsedData.length === 0) return parsedData;
            
            const firstRow = parsedData[0];
            
            // Check if the first row contains mostly headers
            const headerCount = firstRow.filter(cell => isHeaderValue(cell)).length;
            const totalCells = firstRow.filter(cell => cell && cell.trim() !== '').length;
            
            // If more than 50% of the row contains headers, remove it
            if (totalCells > 0 && (headerCount / totalCells) > 0.5) {
                console.log('Header row detected and removed:', firstRow);
                return parsedData.slice(1);
            }
            
            return parsedData;
        }

        // Clean multiple data by removing empty rows and trailing empty columns
        function cleanMultipleData(parsedData) {
            // First, remove completely empty rows
            let cleanedData = parsedData.filter(row => {
                return row.some(cell => cell && cell.trim() !== '');
            });

            if (cleanedData.length === 0) return [];

            // Find the maximum meaningful column count
            let maxMeaningfulCols = 0;
            cleanedData.forEach(row => {
                for (let i = row.length - 1; i >= 0; i--) {
                    if (row[i] && row[i].trim() !== '') {
                        maxMeaningfulCols = Math.max(maxMeaningfulCols, i + 1);
                        break;
                    }
                }
            });

            // Trim each row to meaningful columns only
            cleanedData = cleanedData.map(row => {
                return row.slice(0, maxMeaningfulCols);
            });

            return cleanedData;
        }

        // Show enhanced column paste dialog
        function showColumnPasteDialog(columnData) {
            const columnHeaders = ['Ref. No', 'SAP Doc', 'Invoice', 'Supplier', 'Part Number', 'Part Name', 'Description', 'Qty Returned', 'Qty Received', 'Amount', 'Due Date'];
            const columnNames = ['ref_no', 'sap_doc', 'invoice_no', 'supplier', 'part_number', 'part_name', 'description', 'qty_returned', 'qty_received', 'amount', 'due_date'];
            
            // Determine target column based on selected cell
            let targetColumnIndex = 0;
            if (selectedCell && selectedCell.length > 0) {
                const inputName = selectedCell.attr('name');
                const match = inputName.match(/\[(\w+)\]/);
                if (match) {
                    const fieldName = match[1];
                    targetColumnIndex = columnNames.indexOf(fieldName);
                }
            }

            // Create enhanced preview
            const maxPreviewItems = Math.min(columnData.length, 15);
            let previewList = '<div class="column-preview"><ul class="list-group" style="max-height: 200px; overflow-y: auto;">';
            
            for (let i = 0; i < maxPreviewItems; i++) {
                const item = columnData[i];
                const displayItem = item.length > 50 ? item.substring(0, 50) + '...' : item;
                previewList += `<li class="list-group-item py-2 px-3 small d-flex justify-content-between align-items-center">
                    <span><strong>Row ${i + 1}:</strong> ${displayItem}</span>
                    <span class="badge badge-success badge-pill">${item}</span>
                </li>`;
            }
            
            previewList += '</ul></div>';
            
            if (columnData.length > maxPreviewItems) {
                previewList += `<p class="text-muted small mt-2">... and ${columnData.length - maxPreviewItems} more items</p>`;
            }

            Swal.fire({
                title: 'Paste Column Data',
                html: `
                    <div class="column-paste-options">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <strong>Column Data Successfully Parsed:</strong> ${columnData.length} valid data items
                            <br><small><i class="fas fa-magic"></i> Headers automatically detected and removed. Data ready for vertical pasting.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="targetColumn"><strong>Paste into column:</strong></label>
                            <select class="form-control" id="targetColumn">
                                ${columnHeaders.map((header, index) => 
                                    `<option value="${index}" ${index === targetColumnIndex ? 'selected' : ''}>${header}</option>`
                                ).join('')}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><strong>Paste Mode:</strong></label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="columnPasteMode" id="columnPasteSequential" value="sequential" checked>
                                                                <label class="form-check-label" for="columnPasteSequential">
                                    <i class="fas fa-plus"></i> Fill rows sequentially (${columnData.length} rows will be filled)
                                    <br><small class="text-muted">Will add new rows if needed to fit all data</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="columnPasteMode" id="columnPasteExisting" value="existing">
                                <label class="form-check-label" for="columnPasteExisting">
                                    <i class="fas fa-edit"></i> Fill existing rows only (${Math.min(columnData.length, $('#material_details_table tbody tr').length)} rows will be filled)
                                    <br><small class="text-muted">Will only fill existing rows, excess data will be ignored</small>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="startRow"><strong>Start from row:</strong></label>
                            <input type="number" class="form-control" id="startRow" value="1" min="1" max="1000">
                            <small class="text-muted">Row number to start pasting data (1 = first row)</small>
                        </div>
                        
                        <div class="form-group">
                            <strong>Data Preview (Vertical Layout):</strong>
                            ${previewList}
                        </div>
                    </div>
                `,
                width: '700px',
                showCancelButton: true,
                confirmButtonText: `<i class="fas fa-paste"></i> Paste ${columnData.length} Items Vertically`,
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#264c7eff',
                cancelButtonColor: '#6c757d',
                preConfirm: () => {
                    const targetColumn = parseInt($('#targetColumn').val());
                    const pasteMode = $('input[name="columnPasteMode"]:checked').val();
                    const startRow = parseInt($('#startRow').val()) || 1;
                    
                    return {
                        targetColumn: targetColumn,
                        mode: pasteMode,
                        startRow: startRow - 1, // Convert to 0-based index
                        data: columnData
                    };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    applyColumnData(result.value);
                }
            });
        }

        // Show row paste dialog
        function showRowPasteDialog(rowData) {
            const columnHeaders = ['Ref. No', 'SAP Doc', 'Invoice', 'Supplier', 'Part Number', 'Part Name', 'Description', 'Qty Returned', 'Qty Received', 'Amount', 'Due Date'];
            
            // Create preview
            let previewTable = '<div class="table-responsive"><table class="table table-sm table-bordered">';
            previewTable += '<thead><tr>';
            
            const maxCols = Math.min(rowData.length, columnHeaders.length);
            for (let i = 0; i < maxCols; i++) {
                previewTable += `<th>${columnHeaders[i]}</th>`;
            }
            previewTable += '</tr></thead><tbody><tr>';
            
            for (let i = 0; i < maxCols; i++) {
                const cellData = rowData[i];
                const displayData = cellData.length > 20 ? cellData.substring(0, 20) + '...' : cellData;
                previewTable += `<td title="${cellData}">${displayData}</td>`;
            }
            
            previewTable += '</tr></tbody></table></div>';

            Swal.fire({
                title: 'Paste Row Data',
                html: `
                    <div class="row-paste-options">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <strong>Clean Row Data Detected:</strong> ${rowData.length} valid columns
                            <br><small>Headers automatically detected and removed.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="targetRow"><strong>Paste into row:</strong></label>
                            <select class="form-control" id="targetRow">
                                <option value="new">Add as new row</option>
                                ${Array.from({length: $('#material_details_table tbody tr').length}, (_, i) => 
                                    `<option value="${i}">Row ${i + 1}</option>`
                                ).join('')}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="startColumn"><strong>Start from column:</strong></label>
                            <select class="form-control" id="startColumn">
                                ${columnHeaders.slice(0, Math.max(0, columnHeaders.length - rowData.length + 1)).map((header, index) => 
                                    `<option value="${index}">${header}</option>`
                                ).join('')}
                            </select>
                            <small class="text-muted">Ensure there's enough space for all ${rowData.length} columns</small>
                        </div>
                        
                        <div class="form-group">
                            <strong>Data Preview:</strong>
                            ${previewTable}
                        </div>
                    </div>
                `,
                width: '700px',
                showCancelButton: true,
                confirmButtonText: `<i class="fas fa-paste"></i> Paste ${rowData.length} Items`,
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#264c7eff',
                cancelButtonColor: '#6c757d',
                preConfirm: () => {
                    const targetRow = $('#targetRow').val();
                    const startColumn = parseInt($('#startColumn').val());
                    
                    return {
                        targetRow: targetRow === 'new' ? 'new' : parseInt(targetRow),
                        startColumn: startColumn,
                        data: rowData
                    };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    applyRowData(result.value);
                }
            });
        }

        // Show multiple data paste dialog
        function showMultiplePasteDialog(parsedData) {
            const maxPreviewRows = Math.min(parsedData.length, 10);
            const maxPreviewCols = Math.min(parsedData[0]?.length || 0, 11);
            
            let previewTable = '<div class="table-responsive" style="max-height: 300px;"><table class="table table-sm table-bordered">';
            
            const columnHeaders = ['Ref. No', 'SAP Doc', 'Invoice', 'Supplier', 'Part Number', 'Part Name', 'Description', 'Qty Returned', 'Qty Received', 'Amount', 'Due Date'];
            
            previewTable += '<thead><tr><th>Row</th>';
            for (let col = 0; col < maxPreviewCols && col < columnHeaders.length; col++) {
                previewTable += `<th>${columnHeaders[col]}</th>`;
            }
            previewTable += '</tr></thead><tbody>';
            
            for (let row = 0; row < maxPreviewRows; row++) {
                previewTable += `<tr><td>${row + 1}</td>`;
                for (let col = 0; col < maxPreviewCols; col++) {
                    const cellData = parsedData[row]?.[col] || '';
                    const displayData = cellData.length > 15 ? cellData.substring(0, 15) + '...' : cellData;
                    previewTable += `<td title="${cellData}">${displayData || '<em>empty</em>'}</td>`;
                }
                previewTable += '</tr>';
            }
            
            previewTable += '</tbody></table></div>';
            
            if (parsedData.length > maxPreviewRows) {
                previewTable += `<p class="text-muted small">... and ${parsedData.length - maxPreviewRows} more rows</p>`;
            }

            Swal.fire({
                title: 'Paste Multiple Data',
                html: `
                    <div class="multiple-paste-options">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <strong>Multiple Data Detected:</strong> ${parsedData.length} rows × ${parsedData[0]?.length || 0} columns
                            <br><small>Clean data ready for pasting.</small>
                        </div>
                        
                        <div class="form-group">
                            <label><strong>Paste Mode:</strong></label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="multiplePasteMode" id="multipleAppend" value="append" checked>
                                <label class="form-check-label" for="multipleAppend">
                                    Append to existing rows (${$('#material_details_table tbody tr').length} current rows)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="multiplePasteMode" id="multipleReplace" value="replace">
                                <label class="form-check-label" for="multipleReplace">
                                    Replace all existing data
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="multipleStartColumn"><strong>Start pasting from column:</strong></label>
                            <select class="form-control" id="multipleStartColumn">
                                ${columnHeaders.map((header, index) => 
                                    `<option value="${index}">${header}</option>`
                                ).join('')}
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <strong>Data Preview:</strong>
                            ${previewTable}
                        </div>
                    </div>
                `,
                width: '90%',
                showCancelButton: true,
                confirmButtonText: `<i class="fas fa-paste"></i> Paste Data`,
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#264c7eff',
                cancelButtonColor: '#6c757d',
                preConfirm: () => {
                    const pasteMode = $('input[name="multiplePasteMode"]:checked').val();
                    const startColumn = parseInt($('#multipleStartColumn').val());
                    
                    return {
                        mode: pasteMode,
                        startColumn: startColumn,
                        data: parsedData
                    };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    applyMultipleData(result.value);
                }
            });
        }

        // Apply column data to table
        function applyColumnData(config) {
            const { targetColumn, mode, startRow, data } = config;
            const columnNames = ['ref_no', 'sap_doc', 'invoice_no', 'supplier', 'part_number', 'part_name', 'description', 'qty_returned', 'qty_received', 'amount', 'due_date'];
            const targetColumnName = columnNames[targetColumn];
            
            try {
                // Ensure we have enough rows for sequential mode
                const currentRows = $('#material_details_table tbody tr').length;
                const neededRows = startRow + data.length;
                
                if (mode === 'sequential' && neededRows > currentRows) {
                    const rowsToAdd = neededRows - currentRows;
                    generateTableRows(rowsToAdd);
                }

                let successfulItems = 0;
                const $rows = $('#material_details_table tbody tr');

                // Only process the exact number of data items
                data.forEach((cellData, index) => {
                    const rowIndex = startRow + index;
                    if (rowIndex >= $rows.length && mode === 'existing') return;

                    const $row = $rows.eq(rowIndex);
                    if (!$row.length) return;

                    const $input = $row.find(`input[name*="[${targetColumnName}]"]`);
                    
                    if ($input.length > 0) {
                        // Format data based on input type
                        let formattedData = formatCellData($input, cellData);
                        
                        if (formattedData !== null) {
                            $input.val(formattedData).addClass('paste-success');
                            successfulItems++;
                            
                            // Remove highlight after animation
                            setTimeout(() => {
                                $input.removeClass('paste-success');
                            }, 2000);
                        }
                    }
                });

                // Update row data status and count
                $rows.each(function() {
                    checkRowData($(this));
                });
                updateMaterialCount();

                // Auto-expand the collapsible section
                if (!$('#materialDetailsCollapse').hasClass('show')) {
                    $('#materialDetailsCollapse').collapse('show');
                    $('.collapsible-header').attr('aria-expanded', 'true');
                }

                // Show success message with exact counts
                Swal.fire({
                    icon: 'success',
                    title: 'Column Data Pasted Successfully!',
                    html: `
                        <div style="text-align: center;">
                            <p><strong>${successfulItems}</strong> items pasted into ${columnNames[targetColumn].replace('_', ' ').toUpperCase()} column</p>
                            <p><strong>${recordsWithData}</strong> material records now have data</p>
                            ${mode === 'sequential' && neededRows > currentRows ? 
                                `<p class="text-info">Added ${neededRows - currentRows} new rows to accommodate all data</p>` : ''
                            }
                        </div>
                    `,
                    timer: 3000,
                    showConfirmButton: false
                });

            } catch (error) {
                console.error('Error applying column data:', error);
                showPasteError('Failed to apply column data. Please try again.');
            }
        }

        // Apply row data to table
        function applyRowData(config) {
            const { targetRow, startColumn, data } = config;
            const columnNames = ['ref_no', 'sap_doc', 'invoice_no', 'supplier', 'part_number', 'part_name', 'description', 'qty_returned', 'qty_received', 'amount', 'due_date'];
            
            try {
                let $targetRow;
                
                if (targetRow === 'new') {
                    // Add a new row
                    generateTableRows(1);
                    $targetRow = $('#material_details_table tbody tr:last');
                } else {
                    $targetRow = $(`#material_details_table tbody tr:eq(${targetRow})`);
                }

                if (!$targetRow.length) {
                    showPasteError('Target row not found.');
                    return;
                }

                let successfulItems = 0;

                data.forEach((cellData, index) => {
                    const columnIndex = startColumn + index;
                    if (columnIndex >= columnNames.length) return;

                    const columnName = columnNames[columnIndex];
                    const $input = $targetRow.find(`input[name*="[${columnName}]"]`);
                    
                    if ($input.length > 0) {
                        let formattedData = formatCellData($input, cellData);
                        
                        if (formattedData !== null) {
                            $input.val(formattedData).addClass('paste-success');
                            successfulItems++;
                            
                            // Remove highlight after animation
                            setTimeout(() => {
                                $input.removeClass('paste-success');
                            }, 2000);
                        }
                    }
                });

                checkRowData($targetRow);
                updateMaterialCount();

                // Auto-expand the collapsible section
                if (!$('#materialDetailsCollapse').hasClass('show')) {
                    $('#materialDetailsCollapse').collapse('show');
                    $('.collapsible-header').attr('aria-expanded', 'true');
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Row Data Pasted Successfully!',
                    html: `
                        <div style="text-align: center;">
                            <p><strong>${successfulItems}</strong> items pasted into the selected row</p>
                            <p><strong>${recordsWithData}</strong> material records now have data</p>
                        </div>
                    `,
                    timer: 3000,
                    showConfirmButton: false
                });

            } catch (error) {
                console.error('Error applying row data:', error);
                showPasteError('Failed to apply row data. Please try again.');
            }
        }

        // Apply multiple data to table
        function applyMultipleData(config) {
            const { mode, startColumn, data } = config;
            
            try {
                // If replace mode, clear existing data
                if (mode === 'replace') {
                    $('#material_details_table tbody').empty();
                    totalRows = 0;
                }

                // Ensure we have enough rows
                const currentRows = $('#material_details_table tbody tr').length;
                const neededRows = data.length;
                const rowsToAdd = Math.max(0, neededRows - currentRows);

                if (rowsToAdd > 0) {
                    generateTableRows(rowsToAdd);
                }

                // Apply data to table
                let successfulRows = 0;
                const columnNames = ['ref_no', 'sap_doc', 'invoice_no', 'supplier', 'part_number', 'part_name', 'description', 'qty_returned', 'qty_received', 'amount', 'due_date'];

                data.forEach((rowData, rowIndex) => {
                    const $row = $(`#material_details_table tbody tr:eq(${rowIndex})`);
                    if ($row.length === 0) return;

                    let hasDataInRow = false;

                    rowData.forEach((cellData, colIndex) => {
                        const targetColumnIndex = startColumn + colIndex;
                        if (targetColumnIndex >= columnNames.length || !cellData || cellData.trim() === '') return;

                        const columnName = columnNames[targetColumnIndex];
                        const $input = $row.find(`input[name*="[${columnName}]"]`);
                        
                        if ($input.length > 0) {
                            // Format data based on input type
                            let formattedData = formatCellData($input, cellData.trim());
                            
                            if (formattedData !== null) {
                                $input.val(formattedData).addClass('paste-success');
                                hasDataInRow = true;
                                
                                // Remove highlight after animation
                                setTimeout(() => {
                                    $input.removeClass('paste-success');
                                }, 2000);
                            }
                        }
                    });

                    if (hasDataInRow) {
                        successfulRows++;
                        checkRowData($row);
                    }
                });

                updateMaterialCount();

                // Auto-expand the collapsible section
                if (!$('#materialDetailsCollapse').hasClass('show')) {
                    $('#materialDetailsCollapse').collapse('show');
                    $('.collapsible-header').attr('aria-expanded', 'true');
                }

                // Show success message
                Swal.fire({
                    icon: 'success',
                    title: 'Multiple Data Pasted Successfully!',
                    html: `
                        <div style="text-align: center;">
                            <p><strong>${successfulRows}</strong> rows were filled with data</p>
                            <p><strong>${recordsWithData}</strong> total material records now available</p>
                            ${mode === 'replace' ? '<p class="text-info">Previous data was replaced</p>' : ''}
                        </div>
                    `,
                    timer: 3000,
                    showConfirmButton: false
                });

            } catch (error) {
                console.error('Error applying multiple data:', error);
                showPasteError('Failed to apply pasted data. Please try again.');
            }
        }

        // Format cell data based on input type
        function formatCellData(input, data) {
            const inputType = input.attr('type');
            
            if (inputType === 'number') {
                // Clean number data
                const cleanData = data.replace(/[^\d.-]/g, '');
                if (cleanData && !isNaN(cleanData)) {
                    return cleanData;
                }
                return null;
            } else if (inputType === 'date') {
                // Try to parse date
                const parsedDate = parseExcelDate(data);
                return parsedDate;
            } else {
                // Regular text input
                return data;
            }
        }

        // Parse Excel date formats
        function parseExcelDate(dateString) {
            if (!dateString || typeof dateString !== 'string') return null;

            // Try different date formats
            const dateFormats = [
                /^\d{4}-\d{2}-\d{2}$/, // YYYY-MM-DD
                /^\d{2}\/\d{2}\/\d{4}$/, // MM/DD/YYYY
                /^\d{2}-\d{2}-\d{4}$/, // MM-DD-YYYY
                /^\d{1,2}\/\d{1,2}\/\d{4}$/, // M/D/YYYY
            ];

            const cleanDateString = dateString.trim();

            // Check if it matches any known format
            for (const format of dateFormats) {
                if (format.test(cleanDateString)) {
                    const date = new Date(cleanDateString);
                    if (!isNaN(date.getTime())) {
                        return date.toISOString().split('T')[0]; // Return YYYY-MM-DD format
                    }
                }
            }

            // Try parsing as Excel serial date number
            if (!isNaN(cleanDateString)) {
                const serialDate = parseInt(cleanDateString);
                if (serialDate > 25000 && serialDate < 100000) { // Reasonable Excel date range
                    const excelEpoch = new Date(1900, 0, 1);
                    const date = new Date(excelEpoch.getTime() + (serialDate - 2) * 24 * 60 * 60 * 1000);
                    if (!isNaN(date.getTime())) {
                        return date.toISOString().split('T')[0];
                    }
                }
            }

            return null;
        }

        // Show paste error
        function showPasteError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Paste Error',
                text: message,
                confirmButtonText: 'OK',
                confirmButtonColor: '#264c7eff'
            });
        }

        // Add help button for paste functionality
        function addPasteHelpButton() {
            const helpButton = `
                <button type="button" class="btn btn-info btn-sm" id="pasteHelpBtn" title="How to paste Excel data">
                    <i class="fas fa-question-circle"></i> Paste Help
                </button>
            `;
            
            // Check if help button already exists to avoid duplicates
            if ($('#pasteHelpBtn').length === 0) {
                $('.materials-header .d-flex').append(helpButton);
            }
        }

        // Enhanced paste help dialog
        function showEnhancedPasteHelp() {
            Swal.fire({
                title: 'Excel Paste Instructions',
                html: `
                    <div style="text-align: left;">
                        <h5><i class="fas fa-clipboard"></i> How to paste Excel data:</h5>
                        
                        <div class="alert alert-primary">
                            <strong>Column Paste (Recommended):</strong>
                            <ol>
                                <li>In Excel, select an entire column (click column header)</li>
                                <li>Copy with Ctrl+C</li>
                                <li>Click on any cell in the target column in the table</li>
                                <li>Paste with Ctrl+V</li>
                                <li>Choose the target column and options</li>
                            </ol>
                        </div>
                        
                        <div class="alert alert-info">
                            <strong>Row Paste:</strong>
                            <ol>
                                <li>In Excel, select a row of data</li>
                                <li>Copy with Ctrl+C</li>
                                <li>Paste into the table</li>
                                <li>Choose which row to fill</li>
                            </ol>
                        </div>
                        
                        <div class="alert alert-success">
                            <strong>Multiple Data Paste:</strong>
                            <ol>
                                <li>Select multiple rows and columns in Excel</li>
                                <li>Copy with Ctrl+C</li>
                                <li>Paste into the table</li>
                                <li>Choose append or replace mode</li>
                            </ol>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <h6><i class="fas fa-lightbulb"></i> Pro Tips:</h6>
                            <ul class="mb-0">
                                <li><strong>Headers:</strong> Headers are automatically detected and removed</li>
                                <li><strong>Data validation:</strong> Numbers and dates are automatically validated</li>
                                <li><strong>Visual feedback:</strong> Successfully pasted cells are highlighted</li>
                                <li><strong>Empty cells:</strong> Empty values are automatically filtered out</li>
                            </ul>
                        </div>
                    </div>
                `,
                width: '700px',
                confirmButtonText: 'Got it!',
                confirmButtonColor: '#264c7eff'
            });
        }

        // Update help button click handler
        $(document).on('click', '#pasteHelpBtn', function() {
            showEnhancedPasteHelp();
        });

        // Add keyboard shortcut for paste (Ctrl+V)
        $(document).on('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
                const $focused = $(':focus');
                if ($focused.closest('.table-responsive-custom-inline').length > 0 || 
                    $focused.hasClass('material-input')) {
                    // Let the paste event handle this
                    return;
                }
            }
        });

        // Initialize paste help button
        setTimeout(() => {
            addPasteHelpButton();
        }, 1000);

        // ======= END ENHANCED EXCEL COLUMN PASTE FUNCTIONALITY =======

        // Form validation
        function validateForm() {
            let isValid = true;
            const errors = [];

            // Check if at least one material type is selected
            if ($('input[name="material_type[]"]:checked').length === 0) {
                errors.push('Please select at least one material type.');
                isValid = false;
            }

            // Check if material status is selected
            if ($('input[name="material_status[]"]:checked').length === 0) {
                errors.push('Please select a material status.');
                isValid = false;
            }

            // Check if judgement is selected
            if ($('input[name="judgement[]"]:checked').length === 0) {
                errors.push('Please select a judgement.');
                isValid = false;
            }

            // Check if return date is filled
            if (!$('#return_date').val()) {
                errors.push('Please select a return date.');
                isValid = false;
            }

            // Check if at least one material detail is filled
            if (recordsWithData === 0) {
                errors.push('Please add at least one material detail.');
                isValid = false;
            }

            if (!isValid) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Validation Error',
                    html: errors.map(error => `<li style="text-align: left;">${error}</li>`).join(''),
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#264c7eff'
                });
            }

            return isValid;
        }

        // Form submission handler
        $('#rtsForm').on('submit', function(e) {
            e.preventDefault();

            if (!validateForm()) {
                return;
            }

            // Show confirmation dialog
            Swal.fire({
                title: 'Submit RTS Form?',
                html: `
                    <div style="text-align: center;">
                        <p>You are about to submit the RTS form with:</p>
                        <ul style="text-align: left; display: inline-block;">
                            <li><strong>${recordsWithData}</strong> material records</li>
                            <li>Return Date: <strong>${$('#return_date').val()}</strong></li>
                            <li>Model: <strong>${$('#model').val() || 'Not specified'}</strong></li>
                        </ul>
                        <p style="color: #666; font-size: 14px;">This action cannot be undone.</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#264c7eff',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Submit Form',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm();
                }
            });
        });

        // Submit form function
        function submitForm() {
            const $submitBtn = $('button[type="submit"]');
            const originalText = $submitBtn.html();

            $submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Submitting...').prop('disabled', true);

            // Show loading SweetAlert
            Swal.fire({
                title: 'Processing...',
                text: 'Your RTS form is being processed. Please wait.',
                icon: 'info',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: $('#rtsForm').attr('action'),
                type: 'POST',
                data: $('#rtsForm').serialize(),
                dataType: 'json',
                timeout: 30000,
                                success: function(response) {
                    Swal.close();
                    
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Form Submitted Successfully!',
                            html: `
                                <div style="text-align: center;">
                                    <p>Your RTS form has been submitted successfully.</p>
                                    ${response.rts_number ? `<p><strong>RTS Number:</strong> ${response.rts_number}</p>` : ''}
                                    <p>You will be redirected shortly.</p>
                                </div>
                            `,
                            timer: 3000,
                            timerProgressBar: true,
                            showConfirmButton: false
                        }).then(() => {
                            if (response.redirect_url) {
                                window.location.href = response.redirect_url;
                            } else {
                                window.location.reload();
                            }
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Submission Failed',
                            text: response.message || 'An error occurred while submitting the form.',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#264c7eff'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    
                    let errorMessage = 'An unexpected error occurred.';
                    
                    if (xhr.status === 422) {
                        // Validation errors
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.errors) {
                                const errorList = Object.values(response.errors).flat();
                                errorMessage = `<ul style="text-align: left;">${errorList.map(err => `<li>${err}</li>`).join('')}</ul>`;
                            }
                        } catch (e) {
                            errorMessage = 'Validation failed. Please check your input.';
                        }
                    } else if (xhr.status === 500) {
                        errorMessage = 'Server error occurred. Please try again later.';
                    } else if (status === 'timeout') {
                        errorMessage = 'Request timed out. Please try again.';
                    } else if (status === 'error' && xhr.status === 0) {
                        errorMessage = 'Network error. Please check your connection.';
                    }

                    Swal.fire({
                        icon: 'error',
                        title: 'Submission Error',
                        html: errorMessage,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#264c7eff'
                    });
                },
                complete: function() {
                    $submitBtn.html(originalText).prop('disabled', false);
                }
            });
        }

        // Auto-save functionality (optional)
        let autoSaveTimer;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                if (recordsWithData > 0) {
                    autoSaveForm();
                }
            }, 30000); // Auto-save after 30 seconds of inactivity
        }

        function autoSaveForm() {
            const formData = $('#rtsForm').serialize();
            
            $.ajax({
                url: 'auto_save_rts.php', // You'll need to create this endpoint
                type: 'POST',
                data: formData + '&auto_save=1',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Show subtle success indicator
                        const $indicator = $('<span class="auto-save-indicator text-success"><i class="fas fa-check-circle"></i> Auto-saved</span>');
                        $('.form-header').append($indicator);
                        
                        setTimeout(() => {
                            $indicator.fadeOut(500, function() {
                                $(this).remove();
                            });
                        }, 2000);
                    }
                },
                error: function() {
                    // Silently fail auto-save
                    console.log('Auto-save failed');
                }
            });
        }

        // Trigger auto-save on form changes
        $(document).on('change input', '#rtsForm input, #rtsForm select, #rtsForm textarea', function() {
            scheduleAutoSave();
        });

        // Prevent accidental page navigation
        let formChanged = false;
        
        $(document).on('change input', '#rtsForm input, #rtsForm select, #rtsForm textarea', function() {
            formChanged = true;
        });

        $(window).on('beforeunload', function(e) {
            if (formChanged && recordsWithData > 0) {
                const message = 'You have unsaved changes. Are you sure you want to leave?';
                e.returnValue = message;
                return message;
            }
        });

        // Clear the beforeunload event when form is submitted
        $('#rtsForm').on('submit', function() {
            formChanged = false;
        });

        // Material type toggle functionality
        $(document).on('change', 'input[name="material_type[]"]', function() {
            updateMaterialTypeSelection();
        });

        function updateMaterialTypeSelection() {
            const selectedTypes = $('input[name="material_type[]"]:checked');
            const $indicator = $('#materialTypeIndicator');
            
            if (selectedTypes.length > 0) {
                const typeLabels = selectedTypes.map(function() {
                    return $(this).next('label').text().trim();
                }).get();
                
                $indicator.html(`<i class="fas fa-check-circle text-success"></i> Selected: ${typeLabels.join(', ')}`);
                $indicator.removeClass('text-danger').addClass('text-success');
            } else {
                $indicator.html(`<i class="fas fa-exclamation-triangle text-danger"></i> Please select at least one material type`);
                $indicator.removeClass('text-success').addClass('text-danger');
            }
        }

        // Initialize material type indicator
        updateMaterialTypeSelection();

        // Print functionality
        $('#printBtn').on('click', function() {
            if (recordsWithData === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data to Print',
                    text: 'Please add material details before printing.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#264c7eff'
                });
                return;
            }

            // Create print window
            const printWindow = window.open('', '_blank');
            const printContent = generatePrintContent();
            
            printWindow.document.write(printContent);
            printWindow.document.close();
            
            // Wait for images to load, then print
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 500);
            };
        });

        function generatePrintContent() {
            const selectedMaterialTypes = $('input[name="material_type[]"]:checked').map(function() {
                return $(this).next('label').text().trim();
            }).get().join(', ');

            const selectedMaterialStatus = $('input[name="material_status[]"]:checked').map(function() {
                return $(this).next('label').text().trim();
            }).get().join(', ');

            const selectedJudgement = $('input[name="judgement[]"]:checked').map(function() {
                return $(this).next('label').text().trim();
            }).get().join(', ');

            let materialDetailsHtml = '';
            let rowCounter = 1;

            $('#material_details_table tbody tr').each(function() {
                const $row = $(this);
                if (checkRowData($row)) {
                    const refNo = $row.find('input[name*="[ref_no]"]').val() || '-';
                    const sapDoc = $row.find('input[name*="[sap_doc]"]').val() || '-';
                    const invoiceNo = $row.find('input[name*="[invoice_no]"]').val() || '-';
                    const supplier = $row.find('input[name*="[supplier]"]').val() || '-';
                    const partNumber = $row.find('input[name*="[part_number]"]').val() || '-';
                    const partName = $row.find('input[name*="[part_name]"]').val() || '-';
                    const description = $row.find('input[name*="[description]"]').val() || '-';
                    const qtyReturned = $row.find('input[name*="[qty_returned]"]').val() || '0';
                    const qtyReceived = $row.find('input[name*="[qty_received]"]').val() || '0';
                    const amount = $row.find('input[name*="[amount]"]').val() || '0.00';
                    const dueDate = $row.find('input[name*="[due_date]"]').val() || '-';

                    materialDetailsHtml += `
                        <tr>
                            <td>${rowCounter}</td>
                            <td>${refNo}</td>
                            <td>${sapDoc}</td>
                            <td>${invoiceNo}</td>
                            <td>${supplier}</td>
                            <td>${partNumber}</td>
                            <td>${partName}</td>
                            <td>${description}</td>
                            <td>${qtyReturned}</td>
                            <td>${qtyReceived}</td>
                            <td>${amount}</td>
                            <td>${dueDate}</td>
                        </tr>
                    `;
                    rowCounter++;
                }
            });

            return `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>RTS Form - Print</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            font-size: 12px;
                            margin: 20px;
                            color: #333;
                        }
                        .header {
                            text-align: center;
                            margin-bottom: 30px;
                            border-bottom: 2px solid #264c7eff;
                            padding-bottom: 10px;
                        }
                        .header h1 {
                            margin: 0;
                            color: #264c7eff;
                            font-size: 24px;
                        }
                        .header h2 {
                            margin: 5px 0 0 0;
                            color: #666;
                            font-size: 16px;
                            font-weight: normal;
                        }
                        .info-section {
                            margin-bottom: 20px;
                            background: #f8f9fa;
                            padding: 15px;
                            border-radius: 5px;
                        }
                        .info-row {
                            margin-bottom: 10px;
                            display: flex;
                            align-items: center;
                        }
                        .info-label {
                            font-weight: bold;
                            width: 150px;
                            color: #264c7eff;
                        }
                        .info-value {
                            flex: 1;
                        }
                        .materials-section {
                            margin-top: 30px;
                        }
                        .materials-section h3 {
                            color: #264c7eff;
                            border-bottom: 1px solid #ddd;
                            padding-bottom: 5px;
                            margin-bottom: 15px;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            font-size: 10px;
                            margin-top: 10px;
                        }
                        th, td {
                            border: 1px solid #ddd;
                            padding: 8px;
                            text-align: left;
                            vertical-align: top;
                        }
                        th {
                            background-color: #264c7eff;
                            color: white;
                            font-weight: bold;
                            text-align: center;
                        }
                        tr:nth-child(even) {
                            background-color: #f9f9f9;
                        }
                        .footer {
                            margin-top: 30px;
                            text-align: center;
                            font-size: 10px;
                            color: #666;
                            border-top: 1px solid #ddd;
                            padding-top: 10px;
                        }
                        @media print {
                            body { margin: 0; }
                            .info-row { page-break-inside: avoid; }
                            table { page-break-inside: auto; }
                            tr { page-break-inside: avoid; page-break-after: auto; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Return To Supplier (RTS) Form</h1>
                        <h2>Material Return Documentation</h2>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-row">
                            <span class="info-label">Return Date:</span>
                            <span class="info-value">${$('#return_date').val() || '-'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Model:</span>
                            <span class="info-value">${$('#model').val() || 'Not specified'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Material Type:</span>
                            <span class="info-value">${selectedMaterialTypes || 'Not selected'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Material Status:</span>
                            <span class="info-value">${selectedMaterialStatus || 'Not selected'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Judgement:</span>
                            <span class="info-value">${selectedJudgement || 'Not selected'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Records:</span>
                            <span class="info-value">${recordsWithData}</span>
                        </div>
                    </div>

                    <div class="materials-section">
                        <h3>Material Details</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Ref. No</th>
                                    <th>SAP Doc</th>
                                    <th>Invoice No</th>
                                    <th>Supplier</th>
                                    <th>Part Number</th>
                                    <th>Part Name</th>
                                    <th>Description</th>
                                    <th>Qty Returned</th>
                                    <th>Qty Received</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${materialDetailsHtml}
                            </tbody>
                        </table>
                    </div>

                    <div class="footer">
                        <p>Generated on ${new Date().toLocaleString()}</p>
                        <p>This is a computer-generated document.</p>
                    </div>
                </body>
                </html>
            `;
        }

        // Export to Excel functionality
        $('#exportExcelBtn').on('click', function() {
            if (recordsWithData === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data to Export',
                    text: 'Please add material details before exporting.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#264c7eff'
                });
                return;
            }

            exportToExcel();
        });

        function exportToExcel() {
            // Prepare data for export
            const exportData = [];
            
            // Add header information
            exportData.push(['Return To Supplier (RTS) Form']);
            exportData.push([]);
            exportData.push(['Return Date:', $('#return_date').val() || '-']);
            exportData.push(['Model:', $('#model').val() || 'Not specified']);
            
            const selectedMaterialTypes = $('input[name="material_type[]"]:checked').map(function() {
                return $(this).next('label').text().trim();
            }).get().join(', ');
            exportData.push(['Material Type:', selectedMaterialTypes || 'Not selected']);
            
            const selectedMaterialStatus = $('input[name="material_status[]"]:checked').map(function() {
                return $(this).next('label').text().trim();
            }).get().join(', ');
            exportData.push(['Material Status:', selectedMaterialStatus || 'Not selected']);
            
            const selectedJudgement = $('input[name="judgement[]"]:checked').map(function() {
                return $(this).next('label').text().trim();
            }).get().join(', ');
            exportData.push(['Judgement:', selectedJudgement || 'Not selected']);
            
            exportData.push([]);
            exportData.push(['Material Details:']);
            
            // Add table headers
            exportData.push([
                'No.', 'Ref. No', 'SAP Doc', 'Invoice No', 'Supplier', 
                'Part Number', 'Part Name', 'Description', 'Qty Returned', 
                'Qty Received', 'Amount', 'Due Date'
            ]);

            // Add table data
            let rowCounter = 1;
            $('#material_details_table tbody tr').each(function() {
                const $row = $(this);
                if (checkRowData($row)) {
                    const rowData = [
                        rowCounter,
                        $row.find('input[name*="[ref_no]"]').val() || '',
                        $row.find('input[name*="[sap_doc]"]').val() || '',
                        $row.find('input[name*="[invoice_no]"]').val() || '',
                        $row.find('input[name*="[supplier]"]').val() || '',
                        $row.find('input[name*="[part_number]"]').val() || '',
                        $row.find('input[name*="[part_name]"]').val() || '',
                        $row.find('input[name*="[description]"]').val() || '',
                        $row.find('input[name*="[qty_returned]"]').val() || '0',
                        $row.find('input[name*="[qty_received]"]').val() || '0',
                        $row.find('input[name*="[amount]"]').val() || '0.00',
                        $row.find('input[name*="[due_date]"]').val() || ''
                    ];
                    exportData.push(rowData);
                    rowCounter++;
                }
            });

            // Convert to CSV format
            const csvContent = exportData.map(row => 
                row.map(field => `"${field}"`).join(',')
            ).join('');

            // Create and download file
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `RTS_Form_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                Swal.fire({
                    icon: 'success',
                    title: 'Export Successful',
                    text: 'RTS form data has been exported to CSV file.',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        }

        // Initialize form
        console.log('Enhanced RTS Form initialized successfully with Excel paste functionality');
        
        // Show welcome message on first load
        if (sessionStorage.getItem('rts_form_visited') !== 'true') {
            setTimeout(() => {
                Swal.fire({
                    title: 'Welcome to RTS Form',
                    html: `
                        <div style="text-align: left;">
                            <p>This enhanced form supports:</p>
                            <ul>
                                <li><strong>Excel Data Pasting:</strong> Copy and paste directly from Excel</li>
                                <li><strong>Auto-save:</strong> Your work is automatically saved</li>
                                <li><strong>Print & Export:</strong> Generate reports in multiple formats</li>
                                <li><strong>Smart Validation:</strong> Real-time form validation</li>
                            </ul>
                            <p>Click the "Paste Help" button for detailed instructions on Excel integration.</p>
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonText: 'Get Started',
                    confirmButtonColor: '#264c7eff',
                    showCloseButton: true
                });
                sessionStorage.setItem('rts_form_visited', 'true');
            }, 2000);
        }
    });
</script>



<?php
// Clear any output buffers and close database connections
if (ob_get_level()) {
    ob_end_flush();
}
?>