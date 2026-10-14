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

function appRedirect($message, $type = 'success') {
    $_SESSION['app_message'] = $message;
    $_SESSION['app_message_type'] = $type;
    header('Location: applications.php');
    exit;
}

$message = $_SESSION['app_message'] ?? '';
$messageType = $_SESSION['app_message_type'] ?? 'success';
unset($_SESSION['app_message'], $_SESSION['app_message_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        appRedirect('Invalid request. Please try again.', 'error');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $studentId = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT);
        $driveId = filter_var($_POST['drive_id'] ?? '', FILTER_VALIDATE_INT);
        $applicationDate = trim($_POST['application_date'] ?? '');
        $status = trim($_POST['status'] ?? 'Applied');

        $allowedStatuses = ['Applied', 'Shortlisted', 'Selected', 'Rejected'];

        if (!$studentId || !$driveId || !in_array($status, $allowedStatuses, true)) {
            appRedirect('Please enter valid application details.', 'error');
        }

        $date = DateTime::createFromFormat('!Y-m-d', $applicationDate);
        if (!$date || $date->format('Y-m-d') !== $applicationDate) {
            appRedirect('Please select a valid application date.', 'error');
        }

        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO Application (student_id, drive_id, application_date, status)
             VALUES (?, ?, ?, ?)'
        );

        mysqli_stmt_bind_param(
            $stmt,
            'iiss',
            $studentId,
            $driveId,
            $applicationDate,
            $status
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            appRedirect('Application added successfully.');
        }

        $error = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);

        appRedirect(
            $error === 1062
                ? 'This student has already applied for this drive.'
                : 'Could not add the application. Check the selected student and drive.',
            'error'
        );
    }

    if ($action === 'update') {
        $applicationId = filter_var($_POST['application_id'] ?? '', FILTER_VALIDATE_INT);
        $status = trim($_POST['status'] ?? '');
        $allowedStatuses = ['Applied', 'Shortlisted', 'Selected', 'Rejected'];

        if (!$applicationId || !in_array($status, $allowedStatuses, true)) {
            appRedirect('Please select a valid application and status.', 'error');
        }

        $stmt = mysqli_prepare(
            $conn,
            'UPDATE Application SET status = ? WHERE application_id = ?'
        );

        mysqli_stmt_bind_param($stmt, 'si', $status, $applicationId);
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        appRedirect(
            $success ? 'Application status updated.' : 'Could not update the application.',
            $success ? 'success' : 'error'
        );
    }

    if ($action === 'delete') {
        $applicationId = filter_var($_POST['application_id'] ?? '', FILTER_VALIDATE_INT);

        if (!$applicationId) {
            appRedirect('Invalid application ID.', 'error');
        }

        $stmt = mysqli_prepare(
            $conn,
            'DELETE FROM Application WHERE application_id = ?'
        );

        mysqli_stmt_bind_param($stmt, 'i', $applicationId);
        $success = mysqli_stmt_execute($stmt);
        $error = mysqli_stmt_errno($stmt);
        mysqli_stmt_close($stmt);

        if ($success) {
            appRedirect('Application deleted successfully.');
        }

        appRedirect(
            $error === 1451
                ? 'This application has placement results or selection rounds. Remove those linked records first.'
                : 'Could not delete the application.',
            'error'
        );
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$allowedStatuses = ['Applied', 'Shortlisted', 'Selected', 'Rejected'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$students = mysqli_query(
    $conn,
    'SELECT student_id, name, email FROM Student ORDER BY name'
);

$drives = mysqli_query(
    $conn,
    'SELECT pd.drive_id, pd.drive_date, pd.deadline, j.job_role,
            c.company_name
     FROM Placement_Drive pd
     JOIN Job j ON pd.job_id = j.job_id
     JOIN Company c ON j.company_id = c.company_id
     ORDER BY pd.drive_date DESC'
);

$sql = "SELECT a.application_id, a.student_id, a.drive_id,
               s.name AS student_name, s.email,
               c.company_name, j.job_role, j.package,
               pd.drive_date, a.application_date, a.status
        FROM Application a
        JOIN Student s ON a.student_id = s.student_id
        JOIN Placement_Drive pd ON a.drive_id = pd.drive_id
        JOIN Job j ON pd.job_id = j.job_id
        JOIN Company c ON j.company_id = c.company_id
        WHERE (
            s.name LIKE ? OR s.email LIKE ? OR
            c.company_name LIKE ? OR j.job_role LIKE ?
        )";

$searchLike = '%' . $search . '%';

if ($statusFilter !== '') {
    $sql .= ' AND a.status = ?';
}

$sql .= ' ORDER BY a.application_id DESC';

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
$applications = mysqli_stmt_get_result($stmt);

$totalApplications = 0;
$selectedCount = 0;
$shortlistedCount = 0;

$countResult = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'Selected'), 0) AS selected_count,
        COALESCE(SUM(status = 'Shortlisted'), 0) AS shortlisted_count
     FROM Application"
);

if ($countResult) {
    $counts = mysqli_fetch_assoc($countResult);
    $totalApplications = (int)$counts['total'];
    $selectedCount = (int)$counts['selected_count'];
    $shortlistedCount = (int)$counts['shortlisted_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applications - PlacementHub</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .application-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            align-items: end;
        }
        .application-form label,
        .filter-form label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
        }
        .application-form input,
        .application-form select,
        .filter-form input,
        .filter-form select {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            border: 1px solid #d5dbe5;
            border-radius: 7px;
        }
        .application-form button,
        .filter-form button,
        .action-button {
            padding: 10px 14px;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            font-weight: 600;
        }
        .application-form button,
        .filter-form button {
            background: #2563eb;
            color: white;
        }
        .filter-form {
            display: grid;
            grid-template-columns: minmax(200px, 1fr) 180px auto auto;
            gap: 10px;
            align-items: end;
            margin-bottom: 18px;
        }
        .action-form {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            min-width: 180px;
        }
        .action-form select {
            max-width: 125px;
            padding: 7px;
        }
        .action-button {
            background: #2563eb;
            color: white;
        }
        .delete-button {
            background: #dc2626;
            color: white;
        }
        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        .stat-card {
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: white;
        }
        .stat-card strong {
            display: block;
            font-size: 25px;
            margin-top: 7px;
        }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 650px) {
            .filter-form { grid-template-columns: 1fr; }
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
        <a href="applications.php" class="active">📄 Applications</a>
        <a href="placements.php">🏆 Placements</a>
        <a href="rounds.php">📝 Selection Rounds</a>
    </div>

    <div class="main">
        <div class="topbar">
            <div>
                <h1>Applications</h1>
                <p class="subtitle">Manage and track student placement applications</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">Total applications<strong><?= $totalApplications ?></strong></div>
            <div class="stat-card">Shortlisted<strong><?= $shortlistedCount ?></strong></div>
            <div class="stat-card">Selected<strong><?= $selectedCount ?></strong></div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message <?= h($messageType) ?>"><?= h($message) ?></div>
        <?php endif; ?>

        <div class="content-box">
            <h2>Add Application</h2>

            <?php if ($students && mysqli_num_rows($students) > 0 && $drives && mysqli_num_rows($drives) > 0): ?>
                <form method="POST" class="application-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">

                    <div>
                        <label for="student_id">Student</label>
                        <select name="student_id" id="student_id" required>
                            <option value="">Choose student</option>
                            <?php while ($student = mysqli_fetch_assoc($students)): ?>
                                <option value="<?= (int)$student['student_id'] ?>">
                                    <?= h($student['name']) ?> (ID <?= (int)$student['student_id'] ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="drive_id">Placement drive</label>
                        <select name="drive_id" id="drive_id" required>
                            <option value="">Choose drive</option>
                            <?php while ($drive = mysqli_fetch_assoc($drives)): ?>
                                <option value="<?= (int)$drive['drive_id'] ?>">
                                    <?= h($drive['company_name']) ?> - <?= h($drive['job_role']) ?>
                                    (<?= h($drive['drive_date']) ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="application_date">Application date</label>
                        <input type="date" name="application_date" id="application_date"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div>
                        <label for="new_status">Initial status</label>
                        <select name="status" id="new_status" required>
                            <option value="Applied">Applied</option>
                            <option value="Shortlisted">Shortlisted</option>
                            <option value="Selected">Selected</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>

                    <div>
                        <button type="submit">Add Application</button>
                    </div>
                </form>
            <?php else: ?>
                <p>Add at least one student and one placement drive before creating an application.</p>
            <?php endif; ?>
        </div>

        <div class="content-box">
            <h2>Student Applications</h2>

            <form method="GET" class="filter-form">
                <div>
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search"
                           placeholder="Student, email, company or job role"
                           value="<?= h($search) ?>">
                </div>

                <div>
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($allowedStatuses as $option): ?>
                            <option value="<?= h($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>>
                                <?= h($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">Search</button>
                <a href="applications.php">Clear</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Company</th>
                        <th>Job Role</th>
                        <th>Package</th>
                        <th>Drive Date</th>
                        <th>Applied On</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($applications && mysqli_num_rows($applications) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($applications)): ?>
                            <?php
                            $statusClass = strtolower($row['status'] ?? 'applied');
                            $statusClass = preg_replace('/[^a-z-]/', '', $statusClass);
                            ?>
                            <tr>
                                <td><?= (int)$row['application_id'] ?></td>
                                <td>
                                    <strong><?= h($row['student_name']) ?></strong><br>
                                    <small><?= h($row['email']) ?></small>
                                </td>
                                <td><?= h($row['company_name']) ?></td>
                                <td><?= h($row['job_role']) ?></td>
                                <td>₹<?= number_format((float)$row['package'], 2) ?> LPA</td>
                                <td><?= h($row['drive_date']) ?></td>
                                <td><?= h($row['application_date']) ?></td>
                                <td>
                                    <span class="status status-<?= h($statusClass) ?>">
                                        <?= h($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" class="action-form">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="application_id" value="<?= (int)$row['application_id'] ?>">

                                        <select name="status" aria-label="New status">
                                            <?php foreach ($allowedStatuses as $option): ?>
                                                <option value="<?= h($option) ?>" <?= $row['status'] === $option ? 'selected' : '' ?>>
                                                    <?= h($option) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="action-button">Update</button>
                                    </form>

                                    <form method="POST" class="action-form"
                                          onsubmit="return confirm('Delete this application?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="application_id" value="<?= (int)$row['application_id'] ?>">
                                        <button type="submit" class="action-button delete-button">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9">No applications found.</td></tr>
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