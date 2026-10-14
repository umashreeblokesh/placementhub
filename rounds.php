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

function roundRedirect($message, $type = 'success') {
    $_SESSION['round_message'] = $message;
    $_SESSION['round_message_type'] = $type;
    header('Location: rounds.php');
    exit;
}

$message = $_SESSION['round_message'] ?? '';
$messageType = $_SESSION['round_message_type'] ?? 'success';
unset($_SESSION['round_message'], $_SESSION['round_message_type']);

$roundNames = [
    'Aptitude Test',
    'Technical Interview',
    'Coding Test',
    'HR Interview',
    'Group Discussion',
    'Other'
];

$roundResults = ['Pending', 'Passed', 'Failed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        roundRedirect('Invalid request. Please try again.', 'error');
    }

    $action = $_POST['action'] ?? '';
    $applicationId = filter_var(
        $_POST['application_id'] ?? '',
        FILTER_VALIDATE_INT
    );
    $roundName = trim($_POST['round_name'] ?? '');
    $roundDate = trim($_POST['round_date'] ?? '');
    $roundResult = trim($_POST['result'] ?? 'Pending');

    if (
        !in_array($roundName, $roundNames, true) ||
        !in_array($roundResult, $roundResults, true)
    ) {
        roundRedirect('Please choose a valid round name and result.', 'error');
    }

    if ($roundDate !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $roundDate);

        if (!$date || $date->format('Y-m-d') !== $roundDate) {
            roundRedirect('Please enter a valid round date.', 'error');
        }
    } else {
        $roundDate = null;
    }

    if ($action === 'add') {
        if (!$applicationId) {
            roundRedirect('Please select an application.', 'error');
        }

        $check = mysqli_prepare(
            $conn,
            'SELECT application_id FROM Application WHERE application_id = ?'
        );
        mysqli_stmt_bind_param($check, 'i', $applicationId);
        mysqli_stmt_execute($check);
        $checkResult = mysqli_stmt_get_result($check);
        $exists = mysqli_num_rows($checkResult) > 0;
        mysqli_stmt_close($check);

        if (!$exists) {
            roundRedirect('The selected application does not exist.', 'error');
        }

        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO Selection_Round
                (application_id, round_name, round_date, result)
             VALUES (?, ?, ?, ?)'
        );

        mysqli_stmt_bind_param(
            $stmt,
            'isss',
            $applicationId,
            $roundName,
            $roundDate,
            $roundResult
        );

        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        roundRedirect(
            $success ? 'Selection round added successfully.' : 'Could not add the selection round.',
            $success ? 'success' : 'error'
        );
    }

    if ($action === 'update') {
        $roundId = filter_var(
            $_POST['round_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$roundId) {
            roundRedirect('Invalid selection-round ID.', 'error');
        }

        $stmt = mysqli_prepare(
            $conn,
            'UPDATE Selection_Round
             SET application_id = ?, round_name = ?, round_date = ?, result = ?
             WHERE round_id = ?'
        );

        mysqli_stmt_bind_param(
            $stmt,
            'isssi',
            $applicationId,
            $roundName,
            $roundDate,
            $roundResult,
            $roundId
        );

        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        roundRedirect(
            $success ? 'Selection round updated successfully.' : 'Could not update the selection round.',
            $success ? 'success' : 'error'
        );
    }

    if ($action === 'delete') {
        $roundId = filter_var(
            $_POST['round_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$roundId) {
            roundRedirect('Invalid selection-round ID.', 'error');
        }

        $stmt = mysqli_prepare(
            $conn,
            'DELETE FROM Selection_Round WHERE round_id = ?'
        );

        mysqli_stmt_bind_param($stmt, 'i', $roundId);
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        roundRedirect(
            $success ? 'Selection round deleted successfully.' : 'Could not delete the selection round.',
            $success ? 'success' : 'error'
        );
    }
}

$search = trim($_GET['search'] ?? '');
$resultFilter = trim($_GET['result'] ?? '');

if (!in_array($resultFilter, $roundResults, true)) {
    $resultFilter = '';
}

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

$sql = "SELECT sr.round_id, sr.application_id, sr.round_name,
               sr.round_date, sr.result, s.name AS student_name,
               c.company_name, j.job_role
        FROM Selection_Round sr
        JOIN Application a ON sr.application_id = a.application_id
        JOIN Student s ON a.student_id = s.student_id
        JOIN Placement_Drive pd ON a.drive_id = pd.drive_id
        JOIN Job j ON pd.job_id = j.job_id
        JOIN Company c ON j.company_id = c.company_id
        WHERE (
            s.name LIKE ? OR
            c.company_name LIKE ? OR
            j.job_role LIKE ? OR
            sr.round_name LIKE ?
        )";

$searchLike = '%' . $search . '%';

if ($resultFilter !== '') {
    $sql .= ' AND COALESCE(sr.result, "Pending") = ?';
}

$sql .= ' ORDER BY sr.round_id DESC';

$stmt = mysqli_prepare($conn, $sql);

if ($resultFilter !== '') {
    mysqli_stmt_bind_param(
        $stmt,
        'sssss',
        $searchLike,
        $searchLike,
        $searchLike,
        $searchLike,
        $resultFilter
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
$rounds = mysqli_stmt_get_result($stmt);

$statsQuery = mysqli_query(
    $conn,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(result = 'Passed'), 0) AS passed,
        COALESCE(SUM(result = 'Failed'), 0) AS failed,
        COALESCE(SUM(result IS NULL OR result = 'Pending'), 0) AS pending
     FROM Selection_Round"
);

$stats = $statsQuery
    ? mysqli_fetch_assoc($statsQuery)
    : ['total' => 0, 'passed' => 0, 'failed' => 0, 'pending' => 0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selection Rounds - PlacementHub</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-card {
            padding: 18px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .stat-card strong {
            display: block;
            font-size: 25px;
            margin-top: 7px;
        }

        .round-form,
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .round-form label,
        .filter-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .round-form input,
        .round-form select,
        .filter-form input,
        .filter-form select {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            border: 1px solid #d5dbe5;
            border-radius: 7px;
        }

        .round-form button,
        .filter-form button,
        .action-button {
            padding: 9px 12px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            cursor: pointer;
            font-weight: 600;
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

        .table-wrap { overflow-x: auto; }

        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .row-actions select {
            padding: 7px;
            max-width: 125px;
        }

        .delete-button { background: #dc2626; }

        .filter-form { margin-bottom: 18px; }

        @media (max-width: 600px) {
            .round-form,
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
        <a href="applications.php">📄 Applications</a>
        <a href="rounds.php" class="active">📝 Selection Rounds</a>
        <a href="placements.php">🏆 Placements</a>
    </div>

    <div class="main">
        <div class="topbar">
            <div>
                <h1>Selection Rounds</h1>
                <p class="subtitle">Track student progress through recruitment rounds</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">Total rounds<strong><?= (int)$stats['total'] ?></strong></div>
            <div class="stat-card">Passed<strong><?= (int)$stats['passed'] ?></strong></div>
            <div class="stat-card">Failed<strong><?= (int)$stats['failed'] ?></strong></div>
            <div class="stat-card">Pending<strong><?= (int)$stats['pending'] ?></strong></div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message <?= h($messageType) ?>"><?= h($message) ?></div>
        <?php endif; ?>

        <div class="content-box">
            <h2>Add Selection Round</h2>

            <?php if ($applications && mysqli_num_rows($applications) > 0): ?>
                <form method="POST" class="round-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">

                    <div>
                        <label for="application_id">Application</label>
                        <select name="application_id" id="application_id" required>
                            <option value="">Choose application</option>
                            <?php while ($app = mysqli_fetch_assoc($applications)): ?>
                                <option value="<?= (int)$app['application_id'] ?>">
                                    #<?= (int)$app['application_id'] ?> -
                                    <?= h($app['student_name']) ?> -
                                    <?= h($app['company_name']) ?> /
                                    <?= h($app['job_role']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="round_name">Round name</label>
                        <select name="round_name" id="round_name" required>
                            <?php foreach ($roundNames as $name): ?>
                                <option value="<?= h($name) ?>"><?= h($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="round_date">Round date</label>
                        <input type="date" name="round_date" id="round_date">
                    </div>

                    <div>
                        <label for="new_result">Result</label>
                        <select name="result" id="new_result" required>
                            <?php foreach ($roundResults as $value): ?>
                                <option value="<?= h($value) ?>"><?= h($value) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div><button type="submit">Add Round</button></div>
                </form>
            <?php else: ?>
                <p>Add an application first to create a selection round.</p>
            <?php endif; ?>
        </div>

        <div class="content-box">
            <h2>Recruitment Round Details</h2>

            <form method="GET" class="filter-form">
                <div>
                    <label for="search">Search</label>
                    <input type="text" name="search" id="search"
                           placeholder="Student, company, role or round"
                           value="<?= h($search) ?>">
                </div>

                <div>
                    <label for="result">Result</label>
                    <select name="result" id="result">
                        <option value="">All results</option>
                        <?php foreach ($roundResults as $value): ?>
                            <option value="<?= h($value) ?>"
                                <?= $resultFilter === $value ? 'selected' : '' ?>>
                                <?= h($value) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">Search</button>
                <a href="rounds.php">Clear</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Company</th>
                        <th>Job Role</th>
                        <th>Round</th>
                        <th>Date</th>
                        <th>Result</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rounds && mysqli_num_rows($rounds) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($rounds)): ?>
                            <?php $currentResult = $row['result'] ?: 'Pending'; ?>
                            <tr>
                                <td><?= (int)$row['round_id'] ?></td>
                                <td><?= h($row['student_name']) ?></td>
                                <td><?= h($row['company_name']) ?></td>
                                <td><?= h($row['job_role']) ?></td>
                                <td><?= h($row['round_name']) ?></td>
                                <td><?= h($row['round_date'] ?: '—') ?></td>
                                <td>
                                    <?php if ($currentResult === 'Passed'): ?>
                                        <span class="status status-selected">Passed</span>
                                    <?php elseif ($currentResult === 'Failed'): ?>
                                        <span class="status status-rejected">Failed</span>
                                    <?php else: ?>
                                        <span class="status status-shortlisted">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="row-actions">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="round_id" value="<?= (int)$row['round_id'] ?>">
                                        <input type="hidden" name="application_id" value="<?= (int)$row['application_id'] ?>">

                                        <select name="round_name" aria-label="Round name">
                                            <?php foreach ($roundNames as $name): ?>
                                                <option value="<?= h($name) ?>"
                                                    <?= $row['round_name'] === $name ? 'selected' : '' ?>>
                                                    <?= h($name) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <input type="hidden" name="round_date" value="<?= h($row['round_date']) ?>">

                                        <select name="result" aria-label="Round result">
                                            <?php foreach ($roundResults as $value): ?>
                                                <option value="<?= h($value) ?>"
                                                    <?= $currentResult === $value ? 'selected' : '' ?>>
                                                    <?= h($value) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button class="action-button" type="submit">Update</button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Delete this selection round?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="round_id" value="<?= (int)$row['round_id'] ?>">
                                        <button type="submit" class="action-button delete-button">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8">No selection rounds found.</td></tr>
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