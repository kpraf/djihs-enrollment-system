# djihs-enrollment-system

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