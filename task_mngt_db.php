<?php

declare(strict_types=1);

$host = 'localhost';
$database = 'task_mngt_db';
$username = 'your database username';
$password = 'your password';
$port = 'your database port';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$database};port=$port;charset={$charset}";

try {
    /* ------------- DATABASE CONNECTION ----------------------- */
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    echo "Connected to database successfully.<br>" . PHP_EOL;

    /* ------------- DROP EXISTING TABLES ----------------------- 
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $tables = [
        'knowledge_base',
        'task_attachment',
        'task_action',
        'task_assignment',
        'task_party',
        'task',
        'task_category',
        'party',
        'employee',
        'department',
        'location',
        'organization',
        'urgency',
        'status',
    ];

    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        echo "Dropped table if existed: {$table}" . PHP_EOL;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');*/

    /* -------------- CREATE TABLES ------------------------- */
    $schema = [
        'status' => "
            CREATE TABLE IF NOT EXISTS status (
                sID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sName VARCHAR(50) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'urgency' => "
            CREATE TABLE IF NOT EXISTS urgency (
                urgID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                urgName VARCHAR(50) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'organization' => "
            CREATE TABLE IF NOT EXISTS organization (
                oID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                oName VARCHAR(150) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'location' => "
            CREATE TABLE IF NOT EXISTS location (
                lID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                oID INT UNSIGNED NOT NULL,
                lName VARCHAR(150) NOT NULL,
                CONSTRAINT fk_location_organization
                    FOREIGN KEY (oID) REFERENCES organization(oID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                UNIQUE KEY uq_location_organization_name (oID, lName),
                INDEX idx_location_organization (oID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'department' => "
            CREATE TABLE IF NOT EXISTS department (
                deptID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                lID INT UNSIGNED NOT NULL,
                deptName VARCHAR(150) NOT NULL,
                CONSTRAINT fk_department_location
                    FOREIGN KEY (lID) REFERENCES location(lID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                UNIQUE KEY uq_department_location_name (lID, deptName),
                INDEX idx_department_location (lID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'employee' => "
            CREATE TABLE IF NOT EXISTS employee (
                eID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                deptID INT UNSIGNED NOT NULL,
                eName VARCHAR(150) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                CONSTRAINT fk_employee_department
                    FOREIGN KEY (deptID) REFERENCES department(deptID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                INDEX idx_employee_department (deptID),
                INDEX idx_employee_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'party' => "
            CREATE TABLE IF NOT EXISTS party (
                pID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pType ENUM('ORGANIZATION', 'DEPARTMENT', 'EMPLOYEE') NOT NULL,
                oID INT UNSIGNED NULL,
                deptID INT UNSIGNED NULL,
                eID INT UNSIGNED NULL,
                display_name VARCHAR(150) NOT NULL,
                CONSTRAINT fk_party_organization
                    FOREIGN KEY (oID) REFERENCES organization(oID),
                CONSTRAINT fk_party_department
                    FOREIGN KEY (deptID) REFERENCES department(deptID),
                CONSTRAINT fk_party_employee
                    FOREIGN KEY (eID) REFERENCES employee(eID),
                CONSTRAINT chk_party_one_reference CHECK (
                    (pType = 'ORGANIZATION' AND oID IS NOT NULL AND deptID IS NULL AND eID IS NULL)
                    OR
                    (pType = 'DEPARTMENT' AND oID IS NULL AND deptID IS NOT NULL AND eID IS NULL)
                    OR
                    (pType = 'EMPLOYEE' AND oID IS NULL AND deptID IS NULL AND eID IS NOT NULL)
                ),
                UNIQUE KEY uq_party_organization (oID),
                UNIQUE KEY uq_party_department (deptID),
                UNIQUE KEY uq_party_employee (eID),

                INDEX idx_party_type (pType)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'task_category' => "
            CREATE TABLE IF NOT EXISTS task_category (
                tcID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_tcID INT UNSIGNED NULL,
                owner_pID INT UNSIGNED NULL,
                tcName VARCHAR(120) NOT NULL,
                CONSTRAINT fk_category_parent
                    FOREIGN KEY (parent_tcID) REFERENCES task_category(tcID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_category_owner_party
                    FOREIGN KEY (owner_pID) REFERENCES party(pID)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                INDEX idx_category_parent (parent_tcID),
                INDEX idx_category_owner (owner_pID),
                INDEX idx_category_name (tcName)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'task' => "
            CREATE TABLE IF NOT EXISTS task (
                tID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tNo VARCHAR(200) NOT NULL,
                lID INT UNSIGNED NOT NULL,
                tcID INT UNSIGNED NULL,
                urgID INT UNSIGNED NOT NULL,
                created_by_eID INT UNSIGNED NOT NULL,
                current_sID INT UNSIGNED NOT NULL,
                request_date DATE NOT NULL,
                tTitle VARCHAR(200) NOT NULL,
                tDesc TEXT NOT NULL,
                expected_resolve_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME NULL,
                CONSTRAINT fk_task_location
                    FOREIGN KEY (lID) REFERENCES location(lID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_task_category
                    FOREIGN KEY (tcID) REFERENCES task_category(tcID)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_task_urgency
                    FOREIGN KEY (urgID) REFERENCES urgency(urgID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_task_created_by
                    FOREIGN KEY (created_by_eID) REFERENCES employee(eID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_task_current_status
                    FOREIGN KEY (current_sID) REFERENCES status(sID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                UNIQUE KEY uq_task_tNo (tNo),
                INDEX idx_task_location (lID),
                INDEX idx_task_category (tcID),
                INDEX idx_task_urgency (urgID),
                INDEX idx_task_created_by (created_by_eID),
                INDEX idx_task_status (current_sID),
                INDEX idx_task_request_date (request_date),
                INDEX idx_task_expected_resolve (expected_resolve_at),
                INDEX idx_task_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'task_party' => "
            CREATE TABLE IF NOT EXISTS task_party (
                tpID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tID INT UNSIGNED NOT NULL,
                pID INT UNSIGNED NOT NULL,
                role ENUM('REQUESTOR', 'TARGET') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_task_party_task
                    FOREIGN KEY (tID) REFERENCES task(tID)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_task_party_party
                    FOREIGN KEY (pID) REFERENCES party(pID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                UNIQUE KEY uq_task_party_role (tID, pID, role),
                INDEX idx_task_party_task (tID),
                INDEX idx_task_party_party (pID),
                INDEX idx_task_party_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'task_assignment' => "
            CREATE TABLE IF NOT EXISTS task_assignment (
                taID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tID INT UNSIGNED NOT NULL,
                assignee_pID INT UNSIGNED NOT NULL,
                assigned_by_eID INT UNSIGNED NOT NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                accepted_at DATETIME NULL,
                completed_at DATETIME NULL,
                assignment_status ENUM('ASSIGNED', 'REASSIGNED', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'ASSIGNED',
                remarks TEXT NULL,
                CONSTRAINT fk_assignment_task
                    FOREIGN KEY (tID) REFERENCES task(tID)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_assignment_assignee_party
                    FOREIGN KEY (assignee_pID) REFERENCES party(pID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_assignment_assigned_by
                    FOREIGN KEY (assigned_by_eID) REFERENCES employee(eID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                INDEX idx_assignment_task (tID),
                INDEX idx_assignment_assignee (assignee_pID),
                INDEX idx_assignment_assigned_by (assigned_by_eID),
                INDEX idx_assignment_status (assignment_status),
                INDEX idx_assignment_assigned_at (assigned_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'task_action' => "
            CREATE TABLE IF NOT EXISTS task_action (
                tactID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tID INT UNSIGNED NOT NULL,
                action_by_eID INT UNSIGNED NOT NULL,
                sID INT UNSIGNED NULL,
                tactType ENUM('CREATE', 'COMMENT', 'RESOLVE_PLAN', 'REASSIGN', 'UPDATE_STATUS', 'CLOSE') NOT NULL,
                tactRemark TEXT NULL,
                root_cause TEXT NULL,
                solution_steps TEXT NULL,
                expected_resolve_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_action_task
                    FOREIGN KEY (tID) REFERENCES task(tID)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_action_employee
                    FOREIGN KEY (action_by_eID) REFERENCES employee(eID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_action_status
                    FOREIGN KEY (sID) REFERENCES status(sID)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                INDEX idx_action_task (tID),
                INDEX idx_action_employee (action_by_eID),
                INDEX idx_action_status (sID),
                INDEX idx_action_type (tactType),
                INDEX idx_action_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'task_attachment' => "
            CREATE TABLE IF NOT EXISTS task_attachment (
                attID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tID INT UNSIGNED NOT NULL,
                uploaded_by_eID INT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_attachment_task
                    FOREIGN KEY (tID) REFERENCES task(tID)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_attachment_employee
                    FOREIGN KEY (uploaded_by_eID) REFERENCES employee(eID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                INDEX idx_attachment_task (tID),
                INDEX idx_attachment_employee (uploaded_by_eID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'knowledge_base' => "
            CREATE TABLE IF NOT EXISTS knowledge_base (
                kID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_from_tID INT UNSIGNED NOT NULL,
                tcID INT UNSIGNED NULL,
                tTitle VARCHAR(200) NOT NULL,
                tDesc TEXT NOT NULL,
                root_cause TEXT NULL,
                solution_steps TEXT NULL,
                created_by_eID INT UNSIGNED NOT NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_knowledge_task
                    FOREIGN KEY (created_from_tID) REFERENCES task(tID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_knowledge_category
                    FOREIGN KEY (tcID) REFERENCES task_category(tcID)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_knowledge_created_by
                    FOREIGN KEY (created_by_eID) REFERENCES employee(eID)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                INDEX idx_knowledge_task (created_from_tID),
                INDEX idx_knowledge_category (tcID),
                INDEX idx_knowledge_created_by (created_by_eID),
                INDEX idx_knowledge_published (is_published),
                INDEX idx_knowledge_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
    ];

    /* ------------------ CREATE EACH TABLE ---------------------------- */
    foreach ($schema as $table => $sql) {
        $pdo->exec($sql);
        echo "Table checked: {$table}<br>";
    }

    /* -------------------- SEED DATA ------------------------- */
    $pdo->beginTransaction();
    try {
        $statusStmt = $pdo->prepare("
            INSERT INTO status (sName) VALUES (:sName)
            ON DUPLICATE KEY UPDATE
            sID = LAST_INSERT_ID(sID)
        ");
        $statuses = [
                'NEW',
                'ASSIGNED',
                'IN_PROGRESS',
                'PENDING',
                'REASSIGNED',
                'RESOLVED',
                'CLOSED',
                'CANCELLED',
        ];
        foreach ($statuses as $statusName) {
            $statusStmt->execute([
            'sName' => $statusName,
            ]);
        }
        echo "Status data checked.<br>";


        $urgencyStmt = $pdo->prepare("
            INSERT INTO urgency (urgName)
            VALUES (:urgName)
            ON DUPLICATE KEY UPDATE
            urgID = LAST_INSERT_ID(urgID)
        ");    
        $urgencies = [
                'LOW',
                'MEDIUM',
                'HIGH',
                'CRITICAL',
        ];
        foreach ($urgencies as $urgencyName) {
            $urgencyStmt->execute([
            'urgName' => $urgencyName,
            ]);
        }
        echo "Urgency data checked.<br>";


        $organizationStmt = $pdo->prepare("
            INSERT INTO organization (oName)
            VALUES (:oName)
            ON DUPLICATE KEY UPDATE
            oID = LAST_INSERT_ID(oID)
        ");
        $organizations = [
            'MAIN OFFICE',
            'WIN HOTEL KL',
            'SAFENCLEAN',
            'WATERFRONT RESIDENCE'
        ];

        $organizationIds = [];

        foreach ($organizations as $organizationName) {
            $organizationStmt->execute([
                'oName' => $organizationName,
            ]);
            $organizationIds[$organizationName] = (int) $pdo->lastInsertId();
        }
        echo "Organization data checked.<br>";


        $locationStmt = $pdo->prepare("
            INSERT INTO location (oID, lName)
            VALUES (:oID, :lName)
            ON DUPLICATE KEY UPDATE
            lID = LAST_INSERT_ID(lID)
        ");
        $locations = [
            ['MAIN OFFICE', 'Sibu'],
            ['WIN HOTEL KL', 'KL'],
            ['SAFENCLEAN', 'Labuan'],
            ['WATERFRONT RESIDENCE', 'Sibu'],
        ];

        $locationIds = [];

        foreach ($locations as [$organizationName, $locationName]) {
            $locationStmt->execute([
                'oID'   => $organizationIds[$organizationName],
                'lName' => $locationName,
            ]);
            $locationIds[$organizationName . ':' . $locationName] = (int) $pdo->lastInsertId();
        }
        echo "Location data checked.<br>";



        $departmentStmt = $pdo->prepare("
            INSERT INTO department (lID, deptName)
            VALUES (:lID, :deptName)
            ON DUPLICATE KEY UPDATE
            deptID = LAST_INSERT_ID(deptID)
        ");
        $departments = [
            ['MAIN OFFICE:Sibu', 'IT'],
            ['MAIN OFFICE:Sibu', 'M&E'],
            ['MAIN OFFICE:Sibu', 'Purchasing'],
            ['MAIN OFFICE:Sibu', 'HR'],
            ['MAIN OFFICE:Sibu', 'Account'],
            ['MAIN OFFICE:Sibu', 'Hotel'],
            ['MAIN OFFICE:Sibu', 'Admin'],
            ['MAIN OFFICE:Sibu', 'Management'],

            ['WATERFRONT RESIDENCE:Sibu', 'Management'],
            ['WATERFRONT RESIDENCE:Sibu', 'Security'],

            ['SAFENCLEAN:Labuan', 'Admin'],
            ['SAFENCLEAN:Labuan', 'HR'],
            ['SAFENCLEAN:Labuan', 'Account'],

            ['WIN HOTEL KL:KL', 'Customer Service'],
            ['WIN HOTEL KL:KL', 'Housekeeping'],
            ['WIN HOTEL KL:KL', 'Management'],
        ];

        $departmentIds = [];

        foreach ($departments as [$locationKey, $departmentName]) {
            $departmentStmt->execute([
                'lID'      => $locationIds[$locationKey],
                'deptName' => $departmentName,
            ]);
            $departmentIds[$locationKey . ':' . $departmentName] = (int) $pdo->lastInsertId();
        }
        echo "Department data checked.<br>";


        $employeeStmt = $pdo->prepare("
            INSERT INTO employee (deptID, eName, is_active)
            VALUES (:deptID, :eName, :is_active)
        ");
        $employees = [
            ['MAIN OFFICE:Sibu', 'IT', 'Ling Han Wuoi', 1],
            ['MAIN OFFICE:Sibu', 'IT', 'Ngui Sieng Chang', 1],
            ['MAIN OFFICE:Sibu', 'IT', 'Derek Chiu Toh Hui', 1],
            ['MAIN OFFICE:Sibu', 'IT', 'Jordan Su Siang Ong', 1],
            ['MAIN OFFICE:Sibu', 'M&E', 'Lawrence Hii', 1],
            ['MAIN OFFICE:Sibu', 'Purchasing', 'Ivan Lee', 1],
            ['MAIN OFFICE:Sibu', 'HR', 'Andrew Lo', 1],
            ['MAIN OFFICE:Sibu', 'HR', 'Doreen Jee', 1],
            ['MAIN OFFICE:Sibu', 'HR', 'Jackie Tiong', 1],
            ['MAIN OFFICE:Sibu', 'HR', 'Celine Lau', 1],
            ['MAIN OFFICE:Sibu', 'Account', 'Lee', 1],
            ['MAIN OFFICE:Sibu', 'Admin', 'Jacob Wong', 1],
            ['MAIN OFFICE:Sibu', 'Hotel', 'Desmond Leong', 1],
            ['MAIN OFFICE:Sibu', 'Management', 'Willie Hii Wen Yew', 1],

            ['WATERFRONT RESIDENCE:Sibu', 'Management', 'Peter Hii Hung Kai', 1],
            ['WATERFRONT RESIDENCE:Sibu', 'Security', 'Rosli Bin Dorby', 1],

            ['SAFENCLEAN:Labuan', 'Admin', 'Wong Mei Yee', 1],
            ['SAFENCLEAN:Labuan', 'HR', 'Lee Suk Ting', 1],
            ['SAFENCLEAN:Labuan', 'Account', 'Lau Siew Ching', 1],

            ['WIN HOTEL KL:KL', 'Customer Service', 'Nur Syazwani Binti Rahim', 1],
            ['WIN HOTEL KL:KL', 'Housekeeping', 'Priya Sharma', 1],
            ['WIN HOTEL KL:KL', 'Management', 'Winnie Hii', 1],
        ];

        $employeeIds = [];
        
        /* look for existing employee. If found, reuse it, otherwise create a new employee */
        $findEmployeeStmt = $pdo->prepare("     
            SELECT eID
            FROM employee
            WHERE deptID = :deptID
            AND eName = :eName
            LIMIT 1
        ");

        foreach ($employees as [$locationKey, $departmentName, $employeeName, $isActive]) {
            $deptKey = $locationKey . ':' . $departmentName;
            $deptID = $departmentIds[$deptKey];
        
            $findEmployeeStmt->execute([
            'deptID' => $deptID,
            'eName'  => $employeeName,
            ]);
            $existingEmployee = $findEmployeeStmt->fetch();

            if ($existingEmployee) {
                $employeeId = (int) $existingEmployee['eID'];

                $updateEmployeeStmt = $pdo->prepare("
                    UPDATE employee
                    SET is_active = :is_active
                    WHERE eID = :eID
                ");

                $updateEmployeeStmt->execute([
                'is_active' => $isActive,
                'eID'       => $employeeId,
                ]);
            } else {
                $employeeStmt->execute([
                'deptID'    => $deptID,
                'eName'     => $employeeName,
                'is_active' => $isActive,
                ]);

                $employeeId = (int) $pdo->lastInsertId();
            }
            
            $employeeIds[$employeeName] = $employeeId;
        }
        echo "Employee data checked.<br>";


        $partyStmt = $pdo->prepare("
            INSERT INTO party (pType, oID, deptID, eID, display_name)
            VALUES (:pType, :oID, :deptID, :eID, :display_name)
        ");

        $partyIds = [
            'ORGANIZATION' => [],
            'DEPARTMENT'   => [],
            'EMPLOYEE'     => [],
        ];

        $findOrganizationPartyStmt = $pdo->prepare("
            SELECT pID
            FROM party
            WHERE oID = :oID
            LIMIT 1
        ");
        foreach ($organizationIds as $organizationName => $organizationId) {
            $findOrganizationPartyStmt->execute([
                'oID' => $organizationId,
            ]);
            $existingParty = $findOrganizationPartyStmt->fetch();

            if ($existingParty) {
                $partyId = (int) $existingParty['pID'];
            } else {
                $partyStmt->execute([
                'pType'        => 'ORGANIZATION',
                'oID'          => $organizationId,
                'deptID'       => null,
                'eID'          => null,
                'display_name' => $organizationName,
                ]);
                $partyId = (int) $pdo->lastInsertId();
            }

            $partyIds['ORGANIZATION'][$organizationName] = $partyId;
        }

        $findDepartmentPartyStmt = $pdo->prepare("
            SELECT pID
            FROM party
            WHERE deptID = :deptID
            LIMIT 1
        ");
        foreach ($departmentIds as $departmentName => $departmentId) {
            $findDepartmentPartyStmt->execute([
            'deptID' => $departmentId,
            ]);
            $existingParty = $findDepartmentPartyStmt->fetch();

            if ($existingParty) {
                $partyId = (int) $existingParty['pID'];
            } else {
                $partyStmt->execute([
                'pType'        => 'DEPARTMENT',
                'oID'          => null,
                'deptID'       => $departmentId,
                'eID'          => null,
                'display_name' => "{$departmentName} Department",
                ]);
                $partyId = (int) $pdo->lastInsertId();
            }
            
            $partyIds['DEPARTMENT'][$departmentName] = $partyId;
        }

        $findEmployeePartyStmt = $pdo->prepare("
            SELECT pID
            FROM party
            WHERE eID = :eID
            LIMIT 1
        ");
        foreach ($employeeIds as $employeeName => $employeeId) {
            $findEmployeePartyStmt->execute([
            'eID' => $employeeId,
            ]);
            $existingParty = $findEmployeePartyStmt->fetch();

            if ($existingParty) {
                $partyId = (int) $existingParty['pID'];
            } else {
                $partyStmt->execute([
                'pType'        => 'EMPLOYEE',
                'oID'          => null,
                'deptID'       => null,
                'eID'          => $employeeId,
                'display_name' => $employeeName,
                ]);
                $partyId = (int) $pdo->lastInsertId();
            }
            
            $partyIds['EMPLOYEE'][$employeeName] = $partyId;
        }
        echo "Party data checked.<br>";


        $categoryStmt = $pdo->prepare("
            INSERT INTO task_category (tcName)
            SELECT :tcName WHERE NOT EXISTS (
                SELECT 1
                FROM task_category
                WHERE tcName = :tcName_check
            )
        ");
        $categories = [
            'Plumbing',
            'Electrical',
            'IT - Hardware',
            'IT - Software',
            'IT - Networking',
            'Housekeeping',
            'General Maintenance',
            'Other',
        ];

        foreach ($categories as $categoryName) {
            $categoryStmt->execute([
            'tcName'       => $categoryName,
            'tcName_check' => $categoryName,
            ]);
        }
        echo "Task category data checked.<br>";

        /* ---------------- COMMIT SEED DATA ---------------- */
        $pdo->commit();
        echo "<br>";
        echo "Database setup completed successfully.<br>";
        echo "Existing data was preserved.<br>";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
} catch (Throwable $e) {
    echo "<br>";
    echo "DATABASE SETUP FAILED<br>";
    echo "Error: "
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . "<br>";

    exit(1);
}
?>