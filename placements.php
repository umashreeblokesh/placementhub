```php
<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function placementRedirect($message, $type = 'success') {
    $_SESSION['placement_message'] = $message;
    $_SESSION['placement_message_type'] = $type;
    header('Location: placements.php');
    exit;
}

$message = $_SESSION['placement_message'] ?? '';
$messageType = $_SESSION['placement_message_type'] ?? 'success';

unset($_SESSION['placement_message'], $_SESSION['placement_message_type']);

$allowedStatuses = ['Selected', 'Rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        placementRedirect('Invalid request. Please try again.', 'error');
    }

    $applicationId = filter_var(
        $_POST['application_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    $finalStatus = trim($_POST['final_status'] ?? '');
    $joiningDate = trim($_POST['joining_date'] ?? '');

    if (!$applicationId || !in_array($finalStatus, $allowedStatuses, true)) {
        placementRedirect('Please select a valid application and result.', 'error');
    }

    if ($joiningDate !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $joiningDate);

        if (!$date || $date->format('Y-m-d') !== $joiningDate) {
            placementRedirect('Please enter a valid joining date.', 'error');
        }

        if ($finalStatus !== 'Selected') {
            placementRedirect('A joining date can only be recorded for a selected student.', 'error');
        }
    }

    // Confirm the application exists before recording a result.
    $check = mysqli_prepare(
        $conn,
        'SELECT application_id FROM Application WHERE application_id = ?'
    );

    mysqli_stmt_bind_param($check, 'i', $applicationId);
    mysqli_stmt_execute($check);
    $applicationResult = mysqli_stmt_get_result($check);
    $applicationExists = mysqli_num_rows($applicationResult) > 0;
    mysqli_stmt_close($check);

    if (!$applicationExists) {
        placementRedirect('The selected application does not exist.', 'error');
    }

    $joiningValue = $joiningDate === '' ? null : $joiningDate;

    // application_id is UNIQUE in Placement_Result, so this updates an
    // existing result instead of creating a duplicate.
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO Placement_Result
            (application_id, final_status, joining_date)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            final_status = VALUES(final_status),
            joining_date = VALUES(joining_date)'
    );

    mysqli_stmt_bind_param(
        $stmt,
        'iss',
        $applicationId,
        $finalStatus,
        $joiningValue
    );

    $success = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($success) {
        placementRedirect('Placement result saved successfully.');
    }

    placementRedirect('Could not save the result. Please check the database.', 'error');
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

// Applications that can receive a placement result.
$applications = mysqli_query(
    $conn,
    "SELECT a.application_id, s.name AS student_name,
            c.company_name, j.job_role
     FROM Application a
     JOIN Student s ON a.student_id = s.student_id
     JOIN Placement_Drive pd ON a.drive_id = pd.drive_id
     JOIN Job j ON pd.job_id = j.job_id
     JOIN Company c ON j.company_id = c.company_id
     ORDER BY a.application_id DESC"
);

$sql = "SELECT pr.result_id, pr.application_id, pr.final_status,
               pr.joining_date, s.name AS student_name,
               d.department_name, c.company_name, j.job_role,
               j.package, a.application_date
        FROM Placement_Result pr
        JOIN Application a ON pr.application_id = a.application_id
        JOIN Student s ON a.student_id = s.student_id
        LEFT JOIN Department d ON s.department_id = d.department_id
        JOIN Placement_Drive pd ON a.drive_id = pd.drive_id
        JOIN Job j ON pd.job_id = j.job_id
        JOIN Company c ON j.company_id = c.company_id
        WHERE (
            s.name LIKE ? OR
            COALESCE(d.department_name, '') LIKE ? OR
            c.company_name LIKE ? OR
            j.job_role LIKE ?
        )";

$searchLike = '%' . $search . '%';

if ($statusFilter !== '') {
    $sql .= ' AND pr.final_status = ?';
}

$sql .= ' ORDER BY pr.result_id DESC';

$stmt = mysqli_prepare($conn, $sql);

if ($statusFilter !== '') {
    mysqli_stmt_bind_param(
        $stmt,
        'sssss',
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $statusFilter
    );
} else {
    mysqli_stmt_bind_param(
        $stmt,
        'ssss',
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike
    );
}

mysqli_stmt_execute($stmt);
$results = mysqli_stmt_get_result($stmt);

// Summary statistics are calculated from actual placement results.
$statsQuery = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total_results,
        COALESCE(SUM(final_status = 'Selected'), 0) AS selected_count,
        COALESCE(SUM(final_status = 'Rejected'), 0) AS rejected_count
     FROM Placement_Result"
);

$stats = $statsQuery
    ? mysqli_fetch_assoc($statsQuery)
    : ['total_results' => 0, 'selected_count' => 0, 'rejected_count' => 0];

$packageQuery = mysqli_query(
    $conn,
    "SELECT MAX(j.package) AS highest_package
     FROM Placement_Result pr
     JOIN Application a ON pr.application_id = a.application_id
     JOIN Placement_Drive pd ON a.drive_id = pd.drive_id
     JOIN Job j ON pd.job_id = j.job_id
     WHERE pr.final_status = 'Selected'"
);

$packageData = $packageQuery
    ? mysqli_fetch_assoc($packageQuery)
    : ['highest_package' => null];

$highestPackage = $packageData['highest_package'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Placements - PlacementHub</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px;
        }

        .stat-card strong {
            display: block;
            font-size: 25px;
            margin-top: 7px;
        }

        .placement-form,
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            align-items: end;
        }

        .placement-form label,
        .filter-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .placement-form select,
        .placement-form input,
        .filter-form select,
        .filter-form input {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            border: 1px solid #d5dbe5;
            border-radius: 7px;
        }

        .placement-form button,
        .filter-form button {
            padding: 10px 14px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .filter-form {
            margin-bottom: 18px;
        }
    </style>
</head>

<body>
<div class="dashboard">

    <div class="sidebar">
        <div class="logo">
            🎓 PlacementHub
            <span>College Placement System</span>
        </div>

        <a href="index.php">🏠 Dashboard</a>
        <a href="students.php">👨‍🎓 Students</a>
        <a href="companies.php">🏢 Companies</a>
        <a href="jobs.php">💼 Jobs</a>
        <a href="applications.php">📄 Applications</a>
        <a href="placements.php" class="active">🏆 Placements</a>
        <a href="rounds.php">📝 Selection Rounds</a>
    </div>

    <div class="main">

        <div class="topbar">
            <div>
                <h1>Placement Results</h1>
                <p class="subtitle">
                    Manage final results and track student placements
                </p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                Total results
                <strong><?= (int)$stats['total_results'] ?></strong>
            </div>

            <div class="stat-card">
                Selected
                <strong><?= (int)$stats['selected_count'] ?></strong>
            </div>

            <div class="stat-card">
                Rejected
                <strong><?= (int)$stats['rejected_count'] ?></strong>
            </div>

            <div class="stat-card">
                Highest package
                <strong>
                    <?= $highestPackage !== null
                        ? '₹' . number_format((float)$highestPackage, 2) . ' LPA'
                        : '—' ?>
                </strong>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message <?= h($messageType) ?>">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="content-box">
            <h2>Add or Update Placement Result</h2>

            <?php if ($applications && mysqli_num_rows($applications) > 0): ?>
                <form method="POST" class="placement-form">
                    <input type="hidden" name="csrf_token"
                           value="<?= h($_SESSION['csrf_token']) ?>">

                    <div>
                        <label for="application_id">Application</label>
                        <select name="application_id" id="application_id" required>
                            <option value="">Choose application</option>

                            <?php while ($application = mysqli_fetch_assoc($applications)): ?>
                                <option value="<?= (int)$application['application_id'] ?>">
                                    #<?= (int)$application['application_id'] ?>
                                    — <?= h($application['student_name']) ?>
                                    — <?= h($application['company_name']) ?>
                                    (<?= h($application['job_role']) ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="final_status">Final status</label>
                        <select name="final_status" id="final_status" required>
                            <option value="Selected">Selected</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>

                    <div>
                        <label for="joining_date">Joining date (optional)</label>
                        <input type="date" name="joining_date" id="joining_date">
                    </div>

                    <div>
                        <button type="submit">Save Result</button>
                    </div>
                </form>

                <p>
                    Saving a result for an application that already has one
                    updates that result instead of creating a duplicate.
                </p>
            <?php else: ?>
                <p>Add an application first to record a placement result.</p>
            <?php endif; ?>
        </div>

        <div class="content-box">
            <h2>Placement Report</h2>

            <form method="GET" class="filter-form">
                <div>
                    <label for="search">Search</label>
                    <input type="text" name="search" id="search"
                           placeholder="Student, department, company or role"
                           value="<?= h($search) ?>">
                </div>

                <div>
                    <label for="status">Final status</label>
                    <select name="status" id="status">
                        <option value="">All statuses</option>
                        <?php foreach ($allowedStatuses as $option): ?>
                            <option value="<?= h($option) ?>"
                                <?= $statusFilter === $option ? 'selected' : '' ?>>
                                <?= h($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">Search</button>
                <a href="placements.php">Clear</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Department</th>
                        <th>Company</th>
                        <th>Job Role</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Joining Date</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if ($results && mysqli_num_rows($results) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($results)): ?>
                            <tr>
                                <td><strong><?= h($row['student_name']) ?></strong></td>
                                <td><?= h($row['department_name'] ?: '—') ?></td>
                                <td><?= h($row['company_name']) ?></td>
                                <td><?= h($row['job_role']) ?></td>
                                <td>₹<?= number_format((float)$row['package'], 2) ?> LPA</td>
                                <td>
                                    <?php if ($row['final_status'] === 'Selected'): ?>
                                        <span class="status status-selected">Selected</span>
                                    <?php else: ?>
                                        <span class="status status-rejected">
                                            <?= h($row['final_status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['joining_date'] ?: '—') ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">No placement results found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
</body>
</html>
```