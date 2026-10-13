<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

try {
    // GET PARAMETERS
    $searchStatus   = trim($_GET['status'] ?? '');
    $searchLocation = trim($_GET['location'] ?? '');
    $searchCategory = trim($_GET['category'] ?? '');
    $searchUrgency  = trim($_GET['urgency'] ?? '');
    $searchDate     = trim($_GET['request_date'] ?? '');
    $searchQuery    = trim($_GET['q'] ?? '');

    // PAGINATION
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    $perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);

    // Defaults
    $page = ($page !== false && $page !== null && $page > 0) ? $page : 1;
    $perPage = ($perPage !== false && $perPage !== null && $perPage > 0) ? $perPage : 10;

    // Prevent excessively large requests. Limit maximum page size
    $perPage = min($perPage, 100);

    /*BASE QUERY
    follows same relationships used by index.php:
    task
    -> urgency
    -> status
    -> employee (creator)
    -> department
    -> location
    -> organization
    -> task_party
    -> party
    -> task_category
    */

    $fromSql = "
        FROM task t
        INNER JOIN urgency u ON t.urgID = u.urgID
        INNER JOIN status s ON t.current_sID = s.sID
        INNER JOIN employee e_creator ON t.created_by_eID = e_creator.eID
        INNER JOIN department creator_dept ON e_creator.deptID = creator_dept.deptID
        INNER JOIN location creator_loc ON creator_dept.lID = creator_loc.lID
        INNER JOIN organization creator_org ON creator_loc.oID = creator_org.oID
        LEFT JOIN task_party tp
            ON t.tID = tp.tID
            AND tp.role = 'TARGET'
        LEFT JOIN party target_p
            ON tp.pID = target_p.pID
        LEFT JOIN task_category tc
            ON t.tcID = tc.tcID
        WHERE 1 = 1
    ";
    $params = [];

    // STATUS FILTER
    if ($searchStatus !== '') {
        $fromSql .= "AND t.current_sID = :status";
        $params['status'] = (int) $searchStatus;
    }

    // LOCATION FILTER
    if ($searchLocation !== '') {
        $fromSql .= "AND creator_loc.lID = :location";
        $params['location'] = (int) $searchLocation;
    }

    // CATEGORY FILTER
    if ($searchCategory !== '') {
        $fromSql .= "AND t.tcID = :category";
        $params['category'] = (int) $searchCategory;
    }

    // URGENCY FILTER
    if ($searchUrgency !== '') {
        $fromSql .= "AND t.urgID = :urgency";
        $params['urgency'] = (int) $searchUrgency;
    }

    // REQUEST DATE FILTER
    if ($searchDate !== '') {
        // Validate YYYY-MM-DD
        $date = DateTime::createFromFormat('Y-m-d', $searchDate);

        if (!$date || $date->format('Y-m-d') !== $searchDate) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'Invalid request_date. Expected format: YYYY-MM-DD'
            ]);
            exit;
        }
        $fromSql .= "AND t.request_date = :request_date";
        $params['request_date'] = $searchDate;
    }

    /*
    SEARCH
    - Task title
    - Task description
    - Task number
    - Target person
    */
    if ($searchQuery !== '') {
        $fromSql .= "
            AND (
                t.tTitle LIKE :q_title
                OR t.tDesc LIKE :q_desc
                OR t.tNo LIKE :q_no
                OR target_p.display_name LIKE :q_person
            )
        ";

        $searchValue = '%' . $searchQuery . '%';

        $params['q_title']  = $searchValue;
        $params['q_desc']   = $searchValue;
        $params['q_no']     = $searchValue;
        $params['q_person'] = $searchValue;
    }

    // COUNT TOTAL RECORDS
    $countSql = "SELECT COUNT(DISTINCT t.tID) AS total" . $fromSql;
    $stmtCount = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        if (is_int($value)) {
            $stmtCount->bindValue(
                ':' . $key,
                $value,
                PDO::PARAM_INT
            );
        } else {
            $stmtCount->bindValue(
                ':' . $key,
                $value,
                PDO::PARAM_STR
            );
        }
    }
    $stmtCount->execute();
    $totalRecords = (int) $stmtCount->fetchColumn();

    // CALCULATE PAGINATION

    $totalPages = max(1, (int) ceil($totalRecords / $perPage));

    // If requested page is beyond the final page, return the final page.
    if ($page > $totalPages) {$page = $totalPages;}
    $offset = ($page - 1) * $perPage;

    // TASK DATA QUERY
    $sql = "
        SELECT
            t.tID,
            t.tNo,
            t.tTitle,
            t.tDesc,
            t.request_date,
            t.created_at,
            t.expected_resolve_at,
            t.closed_at,
            t.lID AS location_id,
            t.tcID AS category_id,
            t.urgID AS urgency_id,
            t.created_by_eID AS created_by_employee_id,
            t.current_sID AS status_id,
            creator_org.oID AS organization_id,
            creator_org.oName AS organization_name,
            creator_loc.lName AS location_name,
            u.urgName AS urgency_name,
            s.sName AS status_name,
            e_creator.eName AS creator_name,
            tc.tcName AS category_name,
            GROUP_CONCAT(
                DISTINCT target_p.pID
                ORDER BY target_p.pID
                SEPARATOR ','
            ) AS target_party_ids,
            GROUP_CONCAT(
                DISTINCT target_p.display_name
                ORDER BY target_p.display_name
                SEPARATOR ', '
            ) AS target_person_names
        " . $fromSql . "

        GROUP BY
            t.tID,
            t.tNo,
            t.tTitle,
            t.tDesc,
            t.request_date,
            t.created_at,
            t.expected_resolve_at,
            t.closed_at,
            t.lID,
            t.tcID,
            t.urgID,
            t.created_by_eID,
            t.current_sID,
            creator_org.oID,
            creator_org.oName,
            creator_loc.lName,
            u.urgName,
            s.sName,
            e_creator.eName,
            tc.tcName

        ORDER BY
            t.created_at DESC,
            t.tID DESC

        LIMIT :limit
        OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);

    // BIND FILTER PARAMETERS
    foreach ($params as $key => $value) {
        if (is_int($value)) {
            $stmt->bindValue(
                ':' . $key,
                $value,
                PDO::PARAM_INT
            );
        } else {
            $stmt->bindValue(
                ':' . $key,
                $value,
                PDO::PARAM_STR
            );
        }
    }

    /*
    BIND PAGINATION
    LIMIT/OFFSET are explicitly bound as integers.
    */
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $tasks = $stmt->fetchAll();

    /*
    FORMAT TARGET PARTY IDS
    Convert: "1,3,5" into [1, 3, 5]
    */
    foreach ($tasks as &$task) {
        if (
            isset($task['target_party_ids']) &&
            $task['target_party_ids'] !== null &&
            $task['target_party_ids'] !== ''
        ) {
            $task['target_party_ids'] = array_map(
                'intval',
                explode(',', $task['target_party_ids'])
            );
        } else {
            $task['target_party_ids'] = [];
        }

        // FORMAT TARGET NAMES
        if (
            !isset($task['target_person_names']) ||
            $task['target_person_names'] === null
        ) {
            $task['target_person_names'] = '';
        }
    }
    unset($task);

    // RESPONSE
    echo json_encode([
        'success' => true,
        'data' => $tasks,
        'pagination' => [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total_records'=> $totalRecords,
            'total_pages'  => $totalPages,
        ],

        'filters' => [
            'q'            => $searchQuery,
            'status'       => $searchStatus,
            'location'     => $searchLocation,
            'category'     => $searchCategory,
            'urgency'      => $searchUrgency,
            'request_date' => $searchDate
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve tasks'
    ]);
}