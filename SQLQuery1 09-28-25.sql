use dbfophcrtvsproject;

SELECT * FROM users;
SELECT * FROM rts_forms;
SELECT * FROM rts_materials;
SELECT * FROM rts_sequence;
SELECT * FROM sap_loc_code;
SELECT * FROM rts_disapproval_history;
SELECT * FROM notifications;

DELETE FROM rts_materials;
DELETE FROM rts_forms;
DELETE FROM rts_sequence;
DELETE FROM users;

CREATE TABLE users (
    user_id INT IDENTITY(1,1) PRIMARY KEY,
    username NVARCHAR(50) NOT NULL UNIQUE,
    password NVARCHAR(255) NOT NULL,
    requestor_name NVARCHAR(100),
    role NVARCHAR(20) NOT NULL DEFAULT 'user',
    id_number NVARCHAR(50) NOT NULL,
    email NVARCHAR(100),
    created_at DATETIME DEFAULT GETDATE(),
    updated_at DATETIME DEFAULT GETDATE(),
    is_active BIT DEFAULT 1,
    department NVARCHAR(50),
    category NVARCHAR(100),
    e_signiture VARBINARY(MAX),
    last_login DATETIME
);

CREATE TABLE rts_forms (
    id INT IDENTITY(1,1) PRIMARY KEY,
    control_no VARCHAR(50) NULL UNIQUE,
    requestor_id INT NULL,
    requestor_name VARCHAR(255) NULL,
    requestor_department VARCHAR(255) NULL,
    material_type VARCHAR(255) NULL,
    material_status VARCHAR(255) NULL,
    judgement VARCHAR(255) NULL,
    details TEXT NULL,
    return_date DATE NULL,
    department VARCHAR(255) NULL,
    model VARCHAR(255) NULL,
    sap_loc_code VARCHAR(255) NULL,
    prepared_by VARCHAR(255) NULL,
    checked_by VARCHAR(255) NULL,
    approved_by VARCHAR(255) NULL,
    noted_by VARCHAR(255) NULL,
    created_at DATETIME DEFAULT GETDATE(),
    checked_status VARCHAR(20) DEFAULT 'Pending',
    approved_status VARCHAR(20) DEFAULT 'Pending',
    noted_status VARCHAR(20) DEFAULT 'Pending',
    checked_by_id INT,
    approved_by_id INT,
    noted_by_id INT,
    checked_at DATETIME,
    approved_at DATETIME,
    noted_at DATETIME,
    remark TEXT NULL,
    prepared_by_signature_image VARBINARY(MAX) NULL,
    checked_by_signature_image VARBINARY(MAX) NULL,
    approved_by_signature_image VARBINARY(MAX) NULL,
    noted_by_signature_image VARBINARY(MAX) NULL,
    disapproval_reason TEXT NULL,
    disapproved_by_role VARCHAR(20) NULL,
    resubmission_count INT NULL,
    original_material_status_selection VARCHAR(255) NULL,
    resubmitted_at DATETIME NULL,
    sap_from_location VARCHAR(255) NULL,
    sap_to_location VARCHAR(255) NULL,
    sap_from_description VARCHAR(255) NULL,
    sap_to_description VARCHAR(255) NULL,
    sap_from_department VARCHAR(255) NULL,
    sap_to_department VARCHAR(255) NULL,
    workflow_status VARCHAR(255) NULL
);

CREATE TABLE rts_materials (
    id INT IDENTITY(1,1) PRIMARY KEY,
    rts_form_id INT NOT NULL,
    ref_no VARCHAR(255) NULL,
    sap_doc VARCHAR(255) NULL,
    invoice_no VARCHAR(255) NULL,
    supplier VARCHAR(255) NULL,
    part_name VARCHAR(255) NULL,
    part_number VARCHAR(255) NULL,
    description VARCHAR(255) NULL,
    qty_returned INT NULL,
    qty_received INT NULL,
    amount INT NULL,
    due_date DATE NULL,
    FOREIGN KEY (rts_form_id) REFERENCES rts_forms(id) ON DELETE CASCADE
);

CREATE TABLE sap_loc_code (
    LocationCode VARCHAR(10) PRIMARY KEY,
    LocationDescription VARCHAR(50),
    Department VARCHAR(50)
);

	CREATE TABLE dbo.rts_Sequence (
    id INT PRIMARY KEY IDENTITY(1,1),
    current_year INT,
    current_month INT,
    current_sequence INT,
    updated_at DATETIME DEFAULT GETDATE()
);

CREATE TABLE rts_disapproval_history (
    id INT IDENTITY(1,1) PRIMARY KEY,
    rts_form_id INT NOT NULL,
    disapproved_by_role VARCHAR(50) NOT NULL,
    disapproved_by_user_id INT NOT NULL,
    disapproval_reason TEXT,
    disapproved_at DATETIME DEFAULT GETDATE(),
    resubmission_count INT NOT NULL,
    FOREIGN KEY (rts_form_id) REFERENCES rts_forms(id)
);

-- Create notifications table
CREATE TABLE [dbo].[notifications] (
    [id] [int] IDENTITY(1,1) PRIMARY KEY,
    [user_id] [int] NOT NULL,
    [type] [varchar](50) NOT NULL,
    [title] [varchar](255) NOT NULL,
    [message] [text] NOT NULL,
    [related_id] [int] NULL, -- RTS form ID
    [related_type] [varchar](50) NULL, -- 'rts_form', 'ng_form', etc.
    [control_no] [varchar](100) NULL,
    [is_read] [bit] DEFAULT 0,
    [created_at] [datetime] DEFAULT GETDATE(),
    [read_at] [datetime] NULL,
    [url] [varchar](500) NULL -- Link to the form
);

-- Create indexes for better performance
CREATE INDEX IX_notifications_user_id ON [dbo].[notifications] ([user_id]);
CREATE INDEX IX_notifications_is_read ON [dbo].[notifications] ([is_read]);
CREATE INDEX IX_notifications_created_at ON [dbo].[notifications] ([created_at]);

INSERT INTO users (username, password, requestor_name, role, id_number, email, department, category)
VALUES ('admin', '$2y$10$SFBaeHTrXp5/Xd/LvleyYuNzJ9ffu/gXsRb8bL0nrzaDYsKtVqIOy', 'System Administrator', 'admin', '83911993', 'admin@foph.com', 'Engineering', 'Good');

INSERT INTO sap_loc_code (LocationCode, LocationDescription, Department)
VALUES
('1021', 'Instax WH', 'INSTAX'),
('1022', 'Instax Assy', 'INSTAX'),
('1101', 'Instax-EOL/EN', 'INSTAX'),
('102Y', 'InstaxMD/HOParts', 'INSTAX'),
('110Z', 'INSTAX-SCRAP DISPOSAL', 'INSTAX')

DROP TABLE user_approver_roles;

DROP SEQUENCE IF EXISTS dbo.RTS_ControlNo_Seq;

CREATE SEQUENCE dbo.RTS_ControlNo_Seq
    START WITH 1
    INCREMENT BY 1
    MINVALUE 1
    MAXVALUE 999999
    NO CYCLE;

INSERT INTO dbo.rts_Sequence (current_year, current_month, current_sequence)
VALUES (YEAR(GETDATE()), MONTH(GETDATE()), 0);

ALTER TABLE rts_forms 
ADD workflow_status VARCHAR(50) DEFAULT 'Pending';

-- Update existing records to set workflow_status
UPDATE rts_forms 
SET workflow_status = material_status 
WHERE material_status IN ('Pending', 'In-Progress', 'Completed', 'Disapproved', 'Canceled');

-- Reset material_status for records that were overwritten
UPDATE rts_forms 
SET material_status = original_material_status_selection 
WHERE original_material_status_selection IS NOT NULL;

CREATE INDEX IX_users_role_active ON users(role, is_active) INCLUDE (email);

