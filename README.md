- There are 6 roles here
1. Admins
2. Key Teachers
3. ICT Coordinators
4. Registrar
5. Adviser
6. Subject Teacher

Primary Color: #085019

- Admins has 3 pages
- Key Teachers has 3 pages
- ICT Coordinators has 4 pages
- Registrar 3 pages
- Adviser 4 pages
- Subject Teacher 2 pages

-- ========================================
-- CREATE DATABASE (if it doesn't exist) 
-- AND USE IT
-- ========================================
CREATE DATABASE IF NOT EXISTS djihs_enrollment;
USE djihs_enrollment;

-- ========================================
-- CREATE USER TABLE
-- ========================================
CREATE TABLE User (
    UserID INT PRIMARY KEY AUTO_INCREMENT,
    Username VARCHAR(50) UNIQUE NOT NULL,
    Password VARCHAR(255) NOT NULL,
    FirstName VARCHAR(100) NOT NULL,
    LastName VARCHAR(100) NOT NULL,
    Role ENUM('Admin', 'Adviser', 'Key_Teacher', 'ICT_Coordinator', 'Registrar', 'Subject_Teacher') NOT NULL,
    IsActive BOOLEAN DEFAULT TRUE,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ========================================
-- INSERT DEFAULT USERS
-- ========================================
INSERT INTO User (Username, Password, FirstName, LastName, Role)
VALUES
('admin001', '$2y$10$Badb4KyJ6hYo41XlhgpGlO3wyXilqexGqiIe1LglStN.BxTIl9PvG', 'Admin', 'User', 'Admin'),
('ICT001', '$2y$10$.cLBdKYZxXEoufPv7/x4xOk4VJsYvvIC5./jlGOK3WThId7OMKQra', 'ICT', 'Coordinator', 'ICT_Coordinator'),
('REG001', '$2y$10$LIiOi3AaEpsJKGJqq1Yb2.UD/rp7BhcMQHr.pCMFgiFsHm9Vu53l6', 'Registrar', 'User', 'Registrar'),
('ADV001', '$2y$10$wjYVJl2Trbe6ZGp0CNrDPOQ7KGaoZeL5nqSx9oPKzUERaKPseBV9y', 'Adviser', 'User', 'Adviser'),
('ST001', '$2y$10$26vGK7OuWCyJFCmM9r7rVe/1W99qNBbrQD65luFIm2gBsdwsBL7bW', 'Subject', 'Teacher', 'Subject_Teacher'),
('KT001', '$2y$10$np9RmZLh4OIqJLdDjEV.H.hiv0ygH6m9yytMA5kdVwPPGBp0iFzpm', 'Key', 'Teacher', 'Key_Teacher');

Just copy and paste into phpMyAdmin or MySQL CLI.
No need to hash the passwords again, they are ready to use.
They can login using the original credentials:

-- ========================================
-- LOGIN PASSWORDS (plain text reference)
-- ========================================
-- Admin          -> admin001 / admin123
-- ICT Coordinator -> ICT001 / ict123
-- Registrar      -> REG001 / reg123
-- Adviser        -> ADV001 / adv123
-- Subject Teacher -> ST001 / st123
-- Key Teacher    -> KT001 / kt123

-- ============================================
-- 2. GRADE LEVEL TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS GradeLevel (
    GradeLevelID INT AUTO_INCREMENT PRIMARY KEY,
    GradeLevelName VARCHAR(20) UNIQUE NOT NULL,
    GradeLevelNumber INT UNIQUE NOT NULL CHECK (GradeLevelNumber BETWEEN 7 AND 12),
    Department ENUM('Junior_High', 'Senior_High') NOT NULL,
    IsActive BOOLEAN DEFAULT TRUE
);

-- ============================================
-- 3. STRAND TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS Strand (
    StrandID INT AUTO_INCREMENT PRIMARY KEY,
    StrandCode VARCHAR(20) UNIQUE NOT NULL,
    StrandName VARCHAR(200) NOT NULL,
    StrandCategory ENUM('Academic', 'TVL') NOT NULL,
    Description TEXT,
    IsActive BOOLEAN DEFAULT TRUE
);

-- ============================================
-- 4. SECTION TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS Section (
    SectionID INT AUTO_INCREMENT PRIMARY KEY,
    SectionName VARCHAR(100) NOT NULL,
    GradeLevelID INT NOT NULL,
    StrandID INT,
    AdviserID INT NOT NULL,
    Capacity INT DEFAULT 40 NOT NULL,
    CurrentEnrollment INT DEFAULT 0 NOT NULL,
    AcademicYear VARCHAR(9) NOT NULL,
    IsActive BOOLEAN DEFAULT TRUE,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (GradeLevelID) REFERENCES GradeLevel(GradeLevelID),
    FOREIGN KEY (StrandID) REFERENCES Strand(StrandID),
    FOREIGN KEY (AdviserID) REFERENCES User(UserID),
    UNIQUE KEY unique_section (SectionName, AcademicYear)
);

-- ============================================
-- 5. STUDENT TABLE (EXPANDED)
-- ============================================
CREATE TABLE IF NOT EXISTS Student (
    StudentID INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Basic Identification
    LRN VARCHAR(12) UNIQUE NOT NULL,
    
    -- Personal Information
    LastName VARCHAR(100) NOT NULL,
    FirstName VARCHAR(100) NOT NULL,
    MiddleName VARCHAR(100),
    ExtensionName VARCHAR(10),
    DateOfBirth DATE NOT NULL,
    Age INT,
    Gender ENUM('Male', 'Female') NOT NULL,
    Religion VARCHAR(100),
    
    -- IP and Disability Information
    IsIPCommunity BOOLEAN DEFAULT FALSE,
    IPCommunitySpecify VARCHAR(200),
    IsPWD BOOLEAN DEFAULT FALSE,
    PWDSpecify VARCHAR(200),
    
    -- Current Address
    HouseNumber VARCHAR(50),
    SitioStreet VARCHAR(200),
    Barangay VARCHAR(100) NOT NULL,
    Municipality VARCHAR(100) NOT NULL,
    Province VARCHAR(100) NOT NULL,
    
    -- Parent/Guardian Information
    FatherLastName VARCHAR(100),
    FatherFirstName VARCHAR(100),
    FatherMiddleName VARCHAR(100),
    
    MotherLastName VARCHAR(100),
    MotherFirstName VARCHAR(100),
    MotherMiddleName VARCHAR(100),
    
    GuardianLastName VARCHAR(100),
    GuardianFirstName VARCHAR(100),
    GuardianMiddleName VARCHAR(100),
    
    ContactNumber VARCHAR(15) NOT NULL,
    
    -- Status
    EnrollmentStatus ENUM('Active', 'Cancelled', 'Transferred', 'Graduated', 'Dropped') DEFAULT 'Active',
    IsTransferee BOOLEAN DEFAULT FALSE,
    
    -- Audit Fields
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CreatedBy INT,
    UpdatedBy INT,
    
    FOREIGN KEY (CreatedBy) REFERENCES User(UserID),
    FOREIGN KEY (UpdatedBy) REFERENCES User(UserID)
);

-- ============================================
-- 6. ENROLLMENT TABLE (EXPANDED)
-- ============================================
CREATE TABLE IF NOT EXISTS Enrollment (
    EnrollmentID INT AUTO_INCREMENT PRIMARY KEY,
    
    -- References
    StudentID INT NOT NULL,
    GradeLevelID INT NOT NULL,
    StrandID INT,
    
    -- Enrollment Details
    AcademicYear VARCHAR(9) NOT NULL,
    EnrollmentDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    -- Learner Type Classification
    LearnerType ENUM(
        'Regular_Old_Student',
        'Regular_New_Student', 
        'Regular_ALS',
        'Regular_Balik_Aral',
        'Irregular_Balik_Aral',
        'Irregular_Repeater',
        'Irregular_Transferee'
    ) NOT NULL,
    
    -- Enrollment Processing
    EnrollmentType ENUM('Regular', 'Late', 'Transferee') NOT NULL,
    Status ENUM('Pending', 'Confirmed', 'Cancelled', 'For_Review') DEFAULT 'Pending',
    
    -- Processing Information
    ProcessedBy INT,
    ProcessedDate DATETIME,
    
    -- Remarks
    Remarks TEXT,
    
    -- Audit Fields
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (StudentID) REFERENCES Student(StudentID),
    FOREIGN KEY (GradeLevelID) REFERENCES GradeLevel(GradeLevelID),
    FOREIGN KEY (StrandID) REFERENCES Strand(StrandID),
    FOREIGN KEY (ProcessedBy) REFERENCES User(UserID)
);

-- ============================================
-- 7. SECTION ASSIGNMENT TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS SectionAssignment (
    AssignmentID INT AUTO_INCREMENT PRIMARY KEY,
    StudentID INT NOT NULL,
    SectionID INT NOT NULL,
    EnrollmentID INT NOT NULL,
    AssignmentDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    AssignmentMethod ENUM('Automatic', 'Manual') NOT NULL,
    IsActive BOOLEAN DEFAULT TRUE,
    AssignedBy INT,
    
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (StudentID) REFERENCES Student(StudentID),
    FOREIGN KEY (SectionID) REFERENCES Section(SectionID),
    FOREIGN KEY (EnrollmentID) REFERENCES Enrollment(EnrollmentID),
    FOREIGN KEY (AssignedBy) REFERENCES User(UserID),
    
    -- Ensure one active assignment per student per academic year
    UNIQUE KEY unique_active_assignment (StudentID, SectionID, IsActive)
);

-- ============================================
-- 8. ENROLLMENT DOCUMENTS TABLE (NEW)
-- ============================================
CREATE TABLE IF NOT EXISTS EnrollmentDocument (
    DocumentID INT AUTO_INCREMENT PRIMARY KEY,
    EnrollmentID INT NOT NULL,
    DocumentType ENUM(
        'Birth_Certificate',
        'Form_137',
        'Form_138',
        'Good_Moral',
        'Certificate_of_Completion',
        'Other'
    ) NOT NULL,
    DocumentName VARCHAR(255) NOT NULL,
    FilePath VARCHAR(500),
    UploadedBy INT,
    UploadedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    IsVerified BOOLEAN DEFAULT FALSE,
    VerifiedBy INT,
    VerifiedDate DATETIME,
    
    FOREIGN KEY (EnrollmentID) REFERENCES Enrollment(EnrollmentID),
    FOREIGN KEY (UploadedBy) REFERENCES User(UserID),
    FOREIGN KEY (VerifiedBy) REFERENCES User(UserID)
);

-- ============================================
-- 9. AUDIT LOG TABLE (NEW)
-- ============================================
CREATE TABLE IF NOT EXISTS AuditLog (
    LogID INT AUTO_INCREMENT PRIMARY KEY,
    TableName VARCHAR(50) NOT NULL,
    RecordID INT NOT NULL,
    Action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    OldValue TEXT,
    NewValue TEXT,
    ChangedBy INT,
    ChangedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    IPAddress VARCHAR(45),
    
    FOREIGN KEY (ChangedBy) REFERENCES User(UserID)
);

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================

-- Student Table Indexes
CREATE INDEX idx_student_lrn ON Student(LRN);
CREATE INDEX idx_student_name ON Student(LastName, FirstName);
CREATE INDEX idx_student_status ON Student(EnrollmentStatus);

-- Enrollment Table Indexes
CREATE INDEX idx_enrollment_student ON Enrollment(StudentID);
CREATE INDEX idx_enrollment_year ON Enrollment(AcademicYear);
CREATE INDEX idx_enrollment_status ON Enrollment(Status);
CREATE INDEX idx_enrollment_type ON Enrollment(LearnerType);

-- Section Assignment Indexes
CREATE INDEX idx_assignment_student ON SectionAssignment(StudentID);
CREATE INDEX idx_assignment_section ON SectionAssignment(SectionID);
CREATE INDEX idx_assignment_active ON SectionAssignment(IsActive);

-- Section Table Indexes
CREATE INDEX idx_section_year ON Section(AcademicYear);
CREATE INDEX idx_section_adviser ON Section(AdviserID);

-- ============================================
-- INITIAL DATA: GRADE LEVELS
-- ============================================
INSERT INTO GradeLevel (GradeLevelName, GradeLevelNumber, Department) VALUES
('Grade 7', 7, 'Junior_High'),
('Grade 8', 8, 'Junior_High'),
('Grade 9', 9, 'Junior_High'),
('Grade 10', 10, 'Junior_High'),
('Grade 11', 11, 'Senior_High'),
('Grade 12', 12, 'Senior_High');

-- ============================================
-- INITIAL DATA: STRANDS
-- ============================================
INSERT INTO Strand (StrandCode, StrandName, StrandCategory, Description) VALUES
-- Academic Strands
('ABM', 'Accountancy, Business and Management', 'Academic', 'Prepares students for business and accounting careers'),
('HUMSS', 'Humanities and Social Sciences', 'Academic', 'Focuses on human behavior, society, and culture'),
('STEM', 'Science, Technology, Engineering, and Mathematics', 'Academic', 'Prepares students for science and technology careers'),

-- TVL Strands
('HE-COOK', 'HE-COOKERY/BPP/FBS NCII', 'TVL', 'Home Economics specializing in Cookery, Bread and Pastry Production, and Food & Beverage Services'),
('ICT-CSS', 'ICT-CSS NCII', 'TVL', 'Information and Communications Technology - Computer Systems Servicing'),
('IA-EIM', 'IA-EIM NCII', 'TVL', 'Industrial Arts - Electrical Installation and Maintenance');

-- ============================================
-- VIEWS FOR COMMON QUERIES
-- ============================================

-- View: Complete Student Information
CREATE OR REPLACE VIEW vw_StudentComplete AS
SELECT 
    s.*,
    CONCAT(s.LastName, ', ', s.FirstName, ' ', IFNULL(s.MiddleName, '')) AS FullName,
    CONCAT(IFNULL(s.HouseNumber, ''), ' ', IFNULL(s.SitioStreet, ''), ', ', 
           s.Barangay, ', ', s.Municipality, ', ', s.Province) AS CompleteAddress,
    CONCAT(IFNULL(s.FatherLastName, ''), ', ', IFNULL(s.FatherFirstName, ''), ' ', 
           IFNULL(s.FatherMiddleName, '')) AS FatherFullName,
    CONCAT(IFNULL(s.MotherLastName, ''), ', ', IFNULL(s.MotherFirstName, ''), ' ', 
           IFNULL(s.MotherMiddleName, '')) AS MotherFullName,
    CONCAT(IFNULL(s.GuardianLastName, ''), ', ', IFNULL(s.GuardianFirstName, ''), ' ', 
           IFNULL(s.GuardianMiddleName, '')) AS GuardianFullName
FROM Student s;

-- View: Current Enrollments
CREATE OR REPLACE VIEW vw_CurrentEnrollments AS
SELECT 
    e.EnrollmentID,
    e.StudentID,
    s.LRN,
    CONCAT(s.LastName, ', ', s.FirstName) AS StudentName,
    gl.GradeLevelName,
    st.StrandName,
    e.LearnerType,
    e.Status,
    e.AcademicYear,
    e.EnrollmentDate,
    sec.SectionName,
    CONCAT(u.LastName, ', ', u.FirstName) AS AdviserName
FROM Enrollment e
JOIN Student s ON e.StudentID = s.StudentID
JOIN GradeLevel gl ON e.GradeLevelID = gl.GradeLevelID
LEFT JOIN Strand st ON e.StrandID = st.StrandID
LEFT JOIN SectionAssignment sa ON e.EnrollmentID = sa.EnrollmentID AND sa.IsActive = TRUE
LEFT JOIN Section sec ON sa.SectionID = sec.SectionID
LEFT JOIN User u ON sec.AdviserID = u.UserID
WHERE e.Status IN ('Pending', 'Confirmed');

-- View: Section Capacity Summary
CREATE OR REPLACE VIEW vw_SectionCapacity AS
SELECT 
    sec.SectionID,
    sec.SectionName,
    gl.GradeLevelName,
    st.StrandName,
    sec.Capacity,
    COUNT(sa.AssignmentID) AS EnrolledCount,
    sec.Capacity - COUNT(sa.AssignmentID) AS AvailableSlots,
    ROUND((COUNT(sa.AssignmentID) / sec.Capacity) * 100, 2) AS CapacityPercentage,
    sec.AcademicYear,
    CONCAT(u.LastName, ', ', u.FirstName) AS AdviserName
FROM Section sec
JOIN GradeLevel gl ON sec.GradeLevelID = gl.GradeLevelID
LEFT JOIN Strand st ON sec.StrandID = st.StrandID
JOIN User u ON sec.AdviserID = u.UserID
LEFT JOIN SectionAssignment sa ON sec.SectionID = sa.SectionID AND sa.IsActive = TRUE
WHERE sec.IsActive = TRUE
GROUP BY sec.SectionID, sec.SectionName, gl.GradeLevelName, st.StrandName, 
         sec.Capacity, sec.AcademicYear, u.LastName, u.FirstName;